<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Console\Command\LockableTrait;
use App\Entity\Produit;
use App\Util\AwsV4;
use App\Entity\Tag;
use App\Entity\Categorie;
use App\Entity\SousCategorie;

#[AsCommand(
    name: 'app:fetch-amazon-products',
    description: 'Récupère les produits Amazon et les stocke en base de données.',
)]
class AmazonFetchCommand extends Command
{
    use LockableTrait;

    private HttpClientInterface $httpClient;
    private EntityManagerInterface $entityManager;

    // timestamp du dernier appel pour throttling
    private float $lastCallTs = 0.0;

    public function __construct(HttpClientInterface $httpClient, EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->httpClient   = $httpClient;
        $this->entityManager = $entityManager;
    }

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

    /** Throttle simple pour éviter les bursts. */
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
     * Envoie une requête PA-API avec retries (429/5xx) et respect de Retry-After.
     * Retourne le tableau décodé ou null.
     */
    private function callPaapi(array $payloadArr, OutputInterface $output): ?array
    {
        $url     = "https://webservices.amazon.fr/paapi5/searchitems";
        $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);
        $headers = $this->aws4($payload)->getHeaders();

        $maxRetries = 6;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            // espacement minimal entre appels
            $this->throttle(1600);

            try {
                $response = $this->httpClient->request('POST', $url, [
                    'headers' => $headers,
                    'body'    => $payload,
                    'timeout' => 30,
                ]);

                $status = $response->getStatusCode();

                if ($status === 429) {
                    $headersAll = $response->getHeaders(false);
                    $retryAfter = 0;
                    if (!empty($headersAll['retry-after'][0])) {
                        $retryAfter = (int)$headersAll['retry-after'][0];
                    }

                    if ($attempt === $maxRetries) {
                        $output->writeln("Erreur 429 persistante (tentative $attempt/$maxRetries)");
                        return null;
                    }

                    $sleepSec = max($retryAfter, (int)pow(2, $attempt - 1)) + (random_int(0, 500) / 1000);
                    $output->writeln("429 reçu, attente {$sleepSec}s puis retry (tentative $attempt/$maxRetries)...");
                    usleep((int)($sleepSec * 1_000_000));
                    continue;
                }

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

                // 2xx/3xx — on tente de parser même vide
                return $response->toArray(false);

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

    private function requete_1(OutputInterface $output): array
    {
        $priceRanges = [
            ['min' => 1,     'max' => 2000],    // 0-20€
            ['min' => 2001,  'max' => 5000],    // 20-50€
            ['min' => 5001,  'max' => 10000],   // 50-100€
            ['min' => 10001, 'max' => 100000],  // 100€+
        ];

        $allProducts = [];
        $associateTag = $_ENV['AWS_ASSOCIATE_TAG'];

        $tagRepo = $this->entityManager->getRepository(Tag::class);
        $tagPromo = $tagRepo->findOneBy(['nom' => 'Promo']);
        $tagTopVentes = $tagRepo->findOneBy(['nom' => 'Top Ventes']);

        $categorieRepo = $this->entityManager->getRepository(Categorie::class);
        $categorie = $categorieRepo->findOneBy(['nom' => 'Jouets']);

        foreach ($priceRanges as $range) {
            $min = (int)$range['min'];
            $max = (int)$range['max'];
            $output->writeln("Récupération des Jouets pour la plage de prix min: {$min} - max: {$max}");

            $maxPages = ($min === 1 && $max === 2000) ? 2 : 1;

            for ($page = 1; $page <= $maxPages; $page++) {
                $payloadArray = [
                    "Keywords"     => "jouets",
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
                    "MaxPrice"     => $max,
                    "MinPrice"     => $min,
                    "ItemPage"     => (int)$page,
                ];

                $data = $this->callPaapi($payloadArray, $output);
                if (!$data) { continue; }

                foreach ($data['SearchResult']['Items'] ?? [] as $item) {
                    $amount = $item['Offers']['Listings'][0]['Price']['Amount'] ?? null;
                    if (!is_numeric($amount)) { continue; }

                    $product = new Produit();
                    $product->setNom($item['ItemInfo']['Title']['DisplayValue'] ?? 'Inconnu');
                    $product->setPrix((float)$amount);

                    $promoPct = $item['Offers']['Listings'][0]['Price']['Savings']['Percentage'] ?? null;
                    if (is_numeric($promoPct)) {
                        $product->setPromo((float)$promoPct);
                        if ($tagPromo) { $product->addTag($tagPromo); }
                    }

                    $product->setImage($item['Images']['Primary']['Large']['URL'] ?? null);
                    $product->setLien($item['DetailPageURL'] ?? null);
                    $product->setDescription($item['ItemInfo']['Title']['DisplayValue'] ?? null);

                    $salesRank = $item['BrowseNodeInfo']['BrowseNodes'][0]['SalesRank'] ?? null;
                    if (!empty($salesRank) && (int)$salesRank === 1 && $tagTopVentes) {
                        $product->addTag($tagTopVentes);
                    }

                    if ($categorie) { $product->addCategorie($categorie); }
                    $allProducts[] = $product;
                }
            }
        }

        return $allProducts;
    }

    private function requete_2(string $searchCategorie, OutputInterface $output): array
    {
        $allProducts = [];
        $associateTag = $_ENV['AWS_ASSOCIATE_TAG'];

        $tagRepo = $this->entityManager->getRepository(Tag::class);
        $tagPromo = $tagRepo->findOneBy(['nom' => 'Promo']);
        $tagTopVentes = $tagRepo->findOneBy(['nom' => 'Top Ventes']);

        $categorieRepo = $this->entityManager->getRepository(Categorie::class);
        $categorieNom = ($searchCategorie === "Livre pour enfant") ? "Livres" : $searchCategorie;
        $categorie = $categorieRepo->findOneBy(['nom' => $categorieNom]);

        $output->writeln("Récupération des " . $searchCategorie);

        for ($page = 1; $page <= 3; $page++) {
            $payloadArray = [
                "Keywords"     => $searchCategorie,
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
                "ItemPage"     => (int)$page,
            ];

            $data = $this->callPaapi($payloadArray, $output);
            if (!$data) { continue; }

            foreach ($data['SearchResult']['Items'] ?? [] as $item) {
                $amount = $item['Offers']['Listings'][0]['Price']['Amount'] ?? null;
                if (!is_numeric($amount)) { continue; }

                $product = new Produit();
                $product->setNom($item['ItemInfo']['Title']['DisplayValue'] ?? 'Inconnu');
                $product->setPrix((float)$amount);

                $promoPct = $item['Offers']['Listings'][0]['Price']['Savings']['Percentage'] ?? null;
                if (is_numeric($promoPct)) {
                    $product->setPromo((float)$promoPct);
                    if ($tagPromo) { $product->addTag($tagPromo); }
                }

                $product->setImage($item['Images']['Primary']['Large']['URL'] ?? null);
                $product->setLien($item['DetailPageURL'] ?? null);
                $product->setDescription($item['ItemInfo']['Title']['DisplayValue'] ?? null);

                $salesRank = $item['BrowseNodeInfo']['BrowseNodes'][0]['SalesRank'] ?? null;
                if (!empty($salesRank) && (int)$salesRank === 1 && $tagTopVentes) {
                    $product->addTag($tagTopVentes);
                }

                if ($categorie) { $product->addCategorie($categorie); }
                $allProducts[] = $product;
            }
        }

        return $allProducts;
    }

    private function requete_3(string $searchCategorie, OutputInterface $output): array
    {
        $allProducts = [];
        $associateTag = $_ENV['AWS_ASSOCIATE_TAG'];

        $tagRepo = $this->entityManager->getRepository(Tag::class);
        $tagPromo = $tagRepo->findOneBy(['nom' => 'Promo']);
        $tagTopVentes = $tagRepo->findOneBy(['nom' => 'Top Ventes']);

        $categorieRepo = $this->entityManager->getRepository(Categorie::class);
        $categorie = $categorieRepo->findOneBy(['nom' => "Gaming"]);

        $sousCategorieRepo = $this->entityManager->getRepository(SousCategorie::class);
        $sousCategorie = $sousCategorieRepo->findOneBy(['nom' => $searchCategorie]);

        $output->writeln("Récupération des " . $searchCategorie);

        for ($page = 1; $page <= 1; $page++) {
            $payloadArray = [
                "Keywords"     => $searchCategorie,
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
                "ItemPage"     => (int)$page,
            ];

            $data = $this->callPaapi($payloadArray, $output);
            if (!$data) { continue; }

            foreach ($data['SearchResult']['Items'] ?? [] as $item) {
                $amount = $item['Offers']['Listings'][0]['Price']['Amount'] ?? null;
                if (!is_numeric($amount)) { continue; }

                $product = new Produit();
                $product->setNom($item['ItemInfo']['Title']['DisplayValue'] ?? 'Inconnu');
                $product->setPrix((float)$amount);

                $promoPct = $item['Offers']['Listings'][0]['Price']['Savings']['Percentage'] ?? null;
                if (is_numeric($promoPct)) {
                    $product->setPromo((float)$promoPct);
                    if ($tagPromo) { $product->addTag($tagPromo); }
                }

                $product->setImage($item['Images']['Primary']['Large']['URL'] ?? null);
                $product->setLien($item['DetailPageURL'] ?? null);
                $product->setDescription($item['ItemInfo']['Title']['DisplayValue'] ?? null);

                $salesRank = $item['BrowseNodeInfo']['BrowseNodes'][0]['SalesRank'] ?? null;
                if (!empty($salesRank) && (int)$salesRank === 1 && $tagTopVentes) {
                    $product->addTag($tagTopVentes);
                }

                if ($categorie) { $product->addCategorie($categorie); }
                if ($sousCategorie) { $product->addSousCategorie($sousCategorie); }

                $allProducts[] = $product;
            }
        }

        return $allProducts;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Empêche les exécutions simultanées
        if (!$this->lock()) {
            $output->writeln('Commande déjà en cours — arrêt.');
            return Command::SUCCESS;
        }

        // Nettoyage tables (attention : opération destructive)
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $connection->executeStatement('TRUNCATE TABLE produit_sous_categorie');
        $connection->executeStatement('TRUNCATE TABLE produit_tag');
        $connection->executeStatement('TRUNCATE TABLE produit_categorie');
        $connection->executeStatement('TRUNCATE TABLE produit');
        $connection->executeStatement('TRUNCATE TABLE refresh_date');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        // Ajoute la date du refresh
        $now = new \DateTime();
        $connection->insert('refresh_date', [
            'date' => $now->format('Y-m-d'),
        ]);

        // Récupérations
        $allProducts = [];
        $allProducts = array_merge(
            $allProducts,
            $this->requete_1($output),
            $this->requete_2("Jeux de société", $output),
            $this->requete_2("Jeux éducatifs", $output),
            $this->requete_2("Jeux plein air", $output),
            $this->requete_2("Livre pour enfant", $output),
            $this->requete_2("Gaming", $output),
            $this->requete_3("Consoles de jeux", $output),
            $this->requete_3("Jeux vidéo", $output),
            $this->requete_3("Accessoires Gaming", $output)
        );

        foreach ($allProducts as $product) {
            $this->entityManager->persist($product);
        }
        $this->entityManager->flush();

        $output->writeln(date('[Y-m-d H:i:s]') . ' ' . count($allProducts) . ' produits Amazon mis à jour avec succès !');

        // Le lock est libéré automatiquement à la fin
        return Command::SUCCESS;
    }
}
