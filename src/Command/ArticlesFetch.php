<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Contracts\HttpClient\HttpClientInterface;
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
    private HttpClientInterface $httpClient;
    private EntityManagerInterface $entityManager;

    public function __construct(HttpClientInterface $httpClient, EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->httpClient = $httpClient;
        $this->entityManager = $entityManager;
    }

    private function aws4($payload)
    {
        $region = $_ENV['AWS_REGION'];
        $accessKey = $_ENV['AWS_ACCESS_KEY'];
        $secretKey = $_ENV['AWS_SECRET_KEY'];

        // 4️⃣ Génération des headers signés
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

    private function requete_1(Requetes $requete, $output, $httpClient, $entityManager)
    {

        $associateTag = $_ENV['AWS_ASSOCIATE_TAG'];

        $url = "https://webservices.amazon.fr/paapi5/searchitems";
        $payload = json_encode([
            "Keywords" => $requete->getNom(),
            "Resources" => [
                "ItemInfo.Title",
                "Images.Primary.Large",
                "ItemInfo.Features",
                "Offers.Listings.Price",
                "BrowseNodeInfo.BrowseNodes.SalesRank",
            ],
            "ItemCount" => $requete->getNbProduit(), // <= limite du nombre de produits
            "Availability" => "Available",
            "PartnerTag" => $associateTag,
            "PartnerType" => "Associates",
            "Marketplace" => "www.amazon.fr",
        ]);

        $headers = $this->aws4($payload)->getHeaders();

        try {
            $response = $httpClient->request('POST', $url, [
                'headers' => $headers,
                'body' => $payload,
            ]);
            $data = $response->toArray();
        } catch (\Exception $e) {
            $output->writeln("Erreur lors de la requête Amazon : " . $e->getMessage());
            return []; // si erreur => tu renvoies vide => tu gardes anciens produits
        }

        $products = [];

        foreach ($data['SearchResult']['Items'] ?? [] as $item) {
            if (isset($item['Offers']['Listings'][0]['Price']['Amount'])) {
                $title = $item['ItemInfo']['Title']['DisplayValue'] ?? 'Inconnu';

                $product = new ProduitArticle();
                $product->setNom($this->truncateWords($title, 5)); // max 5 mots
                $product->setDescription($this->truncateAtWords2($title, 150)); // max 150 caractères
                $product->setPrix($item['Offers']['Listings'][0]['Price']['Amount']);
                $product->setImage($item['Images']['Primary']['Large']['URL'] ?? null);
                $product->setLien($item['DetailPageURL']);

                // Tags
                $tags = [];
                if (isset($item['Offers']['Listings'][0]['Price']['Savings']['Percentage'])) {
                    $product->setPromo($item['Offers']['Listings'][0]['Price']['Savings']['Percentage']);
                    $tags[] = "Promo";
                }
                if (!empty($item['BrowseNodeInfo']['BrowseNodes'][0]['SalesRank']) &&
                    $item['BrowseNodeInfo']['BrowseNodes'][0]['SalesRank'] == 1) {
                    $tags[] = "Top Ventes";
                }
                $product->setTags($tags);

                $products[] = $product;
            }
        }

        return $products;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->entityManager;
        $tz = new \DateTimeZone('Europe/Paris');
        $maintenant = new \DateTime('now', $tz);
        $jour = (int) $maintenant->format('j');

        $articles = $em->getRepository(Article::class)->findAll();

        // Filtrer les articles qui doivent être rafraîchis aujourd’hui
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

            // Récupérer les requêtes liées à l’article
            $requetes = $em->getRepository(Requetes::class)->findBy(
                ['article' => $article],
                ['id' => 'ASC']
            );

            // Construire les nouveaux produits
            $nouveauxProduits = [];

            foreach ($requetes as $requete) {
                // On passe l'objet entier (Requetes) pour récupérer nom + nb_produit dedans
                $produitsTrouves = $this->requete_1($requete, $output, $this->httpClient, $em);

                foreach ($produitsTrouves as $produit) {
                    // Sécurité : on s’assure qu’on a bien un ProduitArticle
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
