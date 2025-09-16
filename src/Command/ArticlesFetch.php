<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Console\Command\LockableTrait;
use App\Util\AwsV4;
use App\Entity\Article;
use App\Entity\ProduitArticle;
use App\Entity\Requetes;

#[AsCommand(
    name: 'app:articles:refresh',
    description: 'Rafraîchit les produits Amazon liés aux articles (sans toucher aux produits globaux).',
)]
class ArticlesFetch extends Command
{
    use LockableTrait;

    private HttpClientInterface $httpClient;
    private EntityManagerInterface $entityManager;

    /** Timestamp du dernier appel API pour le throttle */
    private float $lastCallTs = 0.0;

    public function __construct(HttpClientInterface $httpClient, EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->httpClient = $httpClient;
        $this->entityManager = $entityManager;
    }

    /** Signature AWS v4 pour PA-API */
    private function aws4(string $payload): AwsV4
    {
        $region    = $_ENV['AWS_REGION'];
        $accessKey = $_ENV['AWS_ACCESS_KEY'];
        $secretKey = $_ENV['AWS_SECRET_KEY'];

        $awsv4 = new AwsV4($accessKey, $secretKey);
        $awsv4->setRegionName($region);
        $awsv4->setServiceName("ProductAdvertisingAPI");
        $awsv4->setPath("/paapi5/searchitems");
        $awsv4->setPayload($payload);
        $awsv4->setRequestMethod("POST");

        $awsv4->addHeader('content-encoding', 'amz-1.0');
        $awsv4->addHeader('content-type', 'application/json; charset=utf-8');
        $awsv4->addHeader('host', 'webservices.amazon.fr');
        $awsv4->addHeader('x-amz-target', 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.SearchItems');

        return $awsv4;
    }

    /** Limiteur de débit (attend pour garantir ≥ $minMs ms entre 2 appels) */
    private function throttle(int $minMs = 1600): void
    {
        $now = microtime(true);
        $elapsedMs = (int)(($now - $this->lastCallTs) * 1000);
        if ($elapsedMs < $minMs) {
            usleep(($minMs - $elapsedMs) * 1000);
        }
        $this->lastCallTs = microtime(true);
    }

    /**
     * Appel PA-API avec retries (429/5xx), respect Retry-After, backoff + jitter.
     * Retourne le tableau JSON décodé, ou null en cas d’échec.
     */
    private function callPaapi(array $payloadArr, OutputInterface $output): ?array
    {
        $url     = "https://webservices.amazon.fr/paapi5/searchitems";
        $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);
        $headers = $this->aws4($payload)->getHeaders();

        $maxRetries = 6;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            // espacement minimal
            $this->throttle(1600);

            try {
                $resp = $this->httpClient->request('POST', $url, [
                    'headers' => $headers,
                    'body'    => $payload,
                    'timeout' => 30,
                ]);

                $status = $resp->getStatusCode();

                // 429 : quota atteint -> attendre puis retry
                if ($status === 429) {
                    $headersAll = $resp->getHeaders(false);
                    $retryAfter = 0;
                    if (!empty($headersAll['retry-after'][0])) {
                        $retryAfter = (int)$headersAll['retry-after'][0];
                    }
                    if ($attempt === $maxRetries) {
                        $output->writeln("Erreur 429 persistante (tentative $attempt/$maxRetries)");
                        return null;
                    }
                    $sleepSec = max($retryAfter, (int)pow(2, $attempt - 1)) + (random_int(0, 500) / 1000);
                    $output->writeln("429 reçu, pause {$sleepSec}s puis retry (tentative $attempt/$maxRetries)...");
                    usleep((int)($sleepSec * 1_000_000));
                    continue;
                }

                // 5xx : erreur serveur -> backoff + retry
                if ($status >= 500) {
                    if ($attempt === $maxRetries) {
                        $output->writeln("Erreur $status serveur après $attempt tentatives.");
                        return null;
                    }
                    $sleepSec = (int)pow(2, $attempt - 1) + (random_int(0, 500) / 1000);
                    $output->writeln("Erreur $status, retry dans {$sleepSec}s (tentative $attempt/$maxRetries)...");
                    usleep((int)($sleepSec * 1_000_000));
                    continue;
                }

                // 2xx/3xx : on parse (false => pas d’exception si JSON vide)
                return $resp->toArray(false);
            } catch (\Throwable $e) {
                if ($attempt === $maxRetries) {
                    $output->writeln("Exception HttpClient: " . $e->getMessage());
                    return null;
                }
                $sleepSec = (int)pow(2, $attempt - 1) + (random_int(0, 500) / 1000);
                $output->writeln("Exception réseau, retry dans {$sleepSec}s (tentative $attempt/$maxRetries)...");
                usleep((int)($sleepSec * 1_000_000));
            }
        }

        return null;
    }

    private function truncateWords(string $text, int $maxWords): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        $words = preg_split('/\s+/u', $text) ?: [];
        if (count($words) <= $maxWords) {
            return implode(' ', $words);
        }

        return implode(' ', array_slice($words, 0, $maxWords));
    }

    private function truncateAtWords2(string $text, int $maxChars): string
    {
        $text = trim((string) $text);
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxChars);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut) . '...';
    }

    /**
     * Récupère jusqu’à N produits pour une requête (N = $requete->getNbProduit()).
     * SearchItems renvoie max ~10 items/page : on boucle sur ItemPage jusqu’à N.
     */
    private function requete_1(Requetes $requete, OutputInterface $output): array
    {
        $associateTag = $_ENV['AWS_ASSOCIATE_TAG'];
        $need = max(1, (int)$requete->getNbProduit()); // nombre souhaité
        $page = 1;
        $maxPages = 10; // sécurité
        $products = [];

        while (count($products) < $need && $page <= $maxPages) {
            $payload = [
                "Keywords"     => $requete->getNom(),
                "Resources"    => [
                    "ItemInfo.Title",
                    "Images.Primary.Large",
                    "ItemInfo.Features",
                    "Offers.Listings.Price",
                    "BrowseNodeInfo.BrowseNodes.SalesRank",
                ],
                "Availability" => "Available",
                "PartnerTag"   => $associateTag,
                "PartnerType"  => "Associates",
                "Marketplace"  => "www.amazon.fr",
                "ItemPage"     => $page,
            ];

            $data = $this->callPaapi($payload, $output);
            if (!$data) {
                $output->writeln("   ⚠️ API vide/erreur pour « {$requete->getNom()} » (page $page).");
                break; // on s’arrête proprement (on conservera anciens produits si aucun nouveau)
            }

            foreach ($data['SearchResult']['Items'] ?? [] as $item) {
                $amount = $item['Offers']['Listings'][0]['Price']['Amount'] ?? null;
                if (!is_numeric($amount)) {
                    continue;
                }

                $title = $item['ItemInfo']['Title']['DisplayValue'] ?? 'Inconnu';

                $product = new ProduitArticle();
                $product->setNom($this->truncateWords($title, 5));                 // max 5 mots
                $product->setDescription($this->truncateAtWords2($title, 150));    // max 150 chars
                $product->setPrix((float)$amount);
                $product->setImage($item['Images']['Primary']['Large']['URL'] ?? null);
                $product->setLien($item['DetailPageURL'] ?? null);

                // Tags
                $tags = [];
                $promoPct = $item['Offers']['Listings'][0]['Price']['Savings']['Percentage'] ?? null;
                if (is_numeric($promoPct)) {
                    $product->setPromo((float)$promoPct);
                    $tags[] = "Promo";
                }
                $salesRank = $item['BrowseNodeInfo']['BrowseNodes'][0]['SalesRank'] ?? null;
                if (!empty($salesRank) && (int)$salesRank === 1) {
                    $tags[] = "Top Ventes";
                }
                $product->setTags($tags);

                $products[] = $product;
                if (count($products) >= $need) break;
            }

            // Si Amazon ne renvoie plus d’items, inutile d’insister
            if (empty($data['SearchResult']['Items'])) {
                break;
            }

            $page++;
        }

        return $products;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Empêche deux exécutions simultanées
        if (!$this->lock()) {
            $output->writeln('Commande déjà en cours — arrêt.');
            return Command::SUCCESS;
        }

        $em = $this->entityManager;
        $tz = new \DateTimeZone('Europe/Paris');
        $maintenant = new \DateTime('now', $tz);
        $jour = (int)$maintenant->format('j');

        $articles = $em->getRepository(Article::class)->findAll();

        // Filtrer les articles à rafraîchir aujourd’hui
        $articlesARafraichir = array_filter($articles, function (Article $article) use ($jour) {
            $jours = $article->getJoursRafraichissement() ?? [];
            return in_array($jour, $jours, true);
        });

        if (!$articlesARafraichir) {
            $output->writeln(sprintf('[%s] Aucun article à rafraîchir aujourd’hui.', $maintenant->format('Y-m-d H:i:s')));
            return Command::SUCCESS;
        }

        $totalInserts = 0;

        foreach ($articlesARafraichir as $article) {
            $output->writeln(sprintf('→ Article #%d “%s”', $article->getId(), $article->getNom() ?? ''));

            // Requêtes liées à l’article
            $requetes = $em->getRepository(Requetes::class)->findBy(
                ['article' => $article],
                ['id' => 'ASC']
            );

            // Construire les nouveaux produits
            $nouveauxProduits = [];

            foreach ($requetes as $requete) {
                $produitsTrouves = $this->requete_1($requete, $output);

                foreach ($produitsTrouves as $produit) {
                    if ($produit instanceof ProduitArticle) {
                        $produit->setArticle($article);
                        $nouveauxProduits[] = $produit;
                    }
                }
            }

            if (count($nouveauxProduits) > 0) {
                // Supprimer les anciens produits de cet article
                $em->createQuery('DELETE FROM App\Entity\ProduitArticle p WHERE p.article = :article')
                    ->setParameter('article', $article)
                    ->execute();

                // Enregistrer les nouveaux
                foreach ($nouveauxProduits as $produit) {
                    $em->persist($produit);
                }

                // Mettre à jour la date de dernier rafraîchissement
                $article->setDate(clone $maintenant);

                $inserts = count($nouveauxProduits);
                $totalInserts += $inserts;
                $output->writeln(sprintf('   ↳ %d produits insérés (remplacement réussi).', $inserts));
            } else {
                $output->writeln('   ↳ Aucun nouveau produit (API vide/erreur) : anciens produits conservés.');
            }
        }

        $em->flush();

        $output->writeln(sprintf('[%s] Articles traités: %d | Produits insérés: %d',
            $maintenant->format('Y-m-d H:i:s'),
            count($articlesARafraichir),
            $totalInserts
        ));

        return Command::SUCCESS;
    }
}
