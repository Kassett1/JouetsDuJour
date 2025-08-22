<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Contracts\HttpClient\HttpClientInterface;
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

    private function addToBDD_1()
    {

    }

    private function requete_1($output, $httpClient, $entityManager)
    {
        // 🔹 Définition des plages de prix
        $priceRanges = [
            ['min' => 1, 'max' => 2000],     // 0-20€
            ['min' => 2001, 'max' => 5000],  // 20-50€
            ['min' => 5001, 'max' => 10000], // 50-100€
            ['min' => 10001, 'max' => 100000]  // 100€+
        ];

        $allProducts = [];

        $associateTag = $_ENV['AWS_ASSOCIATE_TAG'];

        // 🔹 Récupération des tags en base de données
        $tagRepo = $entityManager->getRepository(Tag::class);
        $tagPromo = $tagRepo->findOneBy(['nom' => 'Promo']);
        $tagTopVentes = $tagRepo->findOneBy(['nom' => 'Top Ventes']);

        // 🔹 Récupération des catégories en base de données
        $categorieRepo = $entityManager->getRepository(Categorie::class);
        $categorie = $categorieRepo->findOneBy(['nom' => 'Jouets']);

        // Requête API Amazon sur les plages de prix
        foreach ($priceRanges as $range) {
            $output->writeln("Récupération des Jouets pour la plage de prix min: {$range['min']} - max: " . ($range['max'] ?? '∞'));

            $maxPages = ($range['min'] === 1 && $range['max'] === 2000) ? 2 : 1; // 2 pages pour la première plage, sinon 1

            for ($page = 1; $page <= $maxPages; $page++) {
                // $output->writeln("📄 Page $page/$maxPages pour cette plage de prix");

                $url = "https://webservices.amazon.fr/paapi5/searchitems";
                $payload = json_encode([
                    "Keywords" => "jouets",
                    "Resources" => [
                        "ItemInfo.Title",
                        "Images.Primary.Large",
                        "ItemInfo.Features",
                        "Offers.Listings.Price",
                        "BrowseNodeInfo.BrowseNodes.SalesRank",
                    ],
                    "Availability" => "Available",
                    "PartnerTag" => $associateTag,
                    "PartnerType" => "Associates",
                    "Marketplace" => "www.amazon.fr",
                    "MaxPrice" => $range['max'],
                    "MinPrice" => $range['min'],
                    "ItemPage" => $page, // Ajout de la pagination
                ]);

                $headers = $this->aws4($payload)->getHeaders();

                // 5️⃣ Exécuter la requête et récupérer les résultats
                try {
                    $response = $httpClient->request('POST', $url, [
                        'headers' => $headers,
                        'body' => $payload,
                    ]);
                    $data = $response->toArray();
                } catch (\Exception $e) {
                    $output->writeln("Erreur lors de la requête Amazon : " . $e->getMessage());
                    continue;
                }

                // 6️⃣ Sauvegarde temporaire des produits
                foreach ($data['SearchResult']['Items'] ?? [] as $item) {
                    if (isset($item['Offers']['Listings'][0]['Price']['Amount'])) {
                        $product = new Produit();
                        $product->setNom($item['ItemInfo']['Title']['DisplayValue'] ?? 'Inconnu');
                        $product->setPrix($item['Offers']['Listings'][0]['Price']['Amount']);

                        if (isset($item['Offers']['Listings'][0]['Price']['Savings']['Percentage'])) {
                            $product->setPromo($item['Offers']['Listings'][0]['Price']['Savings']['Percentage']);
                            $product->addTag($tagPromo);
                        }

                        $product->setImage($item['Images']['Primary']['Large']['URL'] ?? null);
                        $product->setLien($item['DetailPageURL']);
                        $product->setDescription($item['ItemInfo']['Title']['DisplayValue']);

                        if (
                            !empty($item['BrowseNodeInfo']['BrowseNodes'][0]['SalesRank']) &&
                            $item['BrowseNodeInfo']['BrowseNodes'][0]['SalesRank'] == 1
                        ) {
                            if ($tagTopVentes) {
                                $product->addTag($tagTopVentes);
                            }
                        }

                        $product->addCategorie($categorie);
                        $allProducts[] = $product;
                    }
                }

                sleep(1);
            }
        }

        return $allProducts;
    }

    private function requete_2($searchCategorie, $output, $httpClient, $entityManager)
    {
        $allProducts = [];

        $associateTag = $_ENV['AWS_ASSOCIATE_TAG'];

        // 🔹 Récupération des tags en base de données
        $tagRepo = $entityManager->getRepository(Tag::class);
        $tagPromo = $tagRepo->findOneBy(['nom' => 'Promo']);
        $tagTopVentes = $tagRepo->findOneBy(['nom' => 'Top Ventes']);

        // 🔹 Récupération des catégories en base de données
        $categorieRepo = $entityManager->getRepository(Categorie::class);

        if ($searchCategorie == "Livre pour enfant") {
            $categorie = $categorieRepo->findOneBy(['nom' => "Livres"]);
        } else {
            $categorie = $categorieRepo->findOneBy(['nom' => $searchCategorie]);
        }

        // Requête API Amazon sur les plages de prix
        $output->writeln("Récupération des " . $searchCategorie);

        for ($page = 1; $page <= 3; $page++) {
            // $output->writeln("📄 Page $page/3 pour cette catégorie");

            $url = "https://webservices.amazon.fr/paapi5/searchitems";
            $payload = json_encode([
                "Keywords" => $searchCategorie,
                "Resources" => [
                    "ItemInfo.Title",
                    "Images.Primary.Large",
                    "ItemInfo.Features",
                    "Offers.Listings.Price",
                    "BrowseNodeInfo.BrowseNodes.SalesRank",
                ],
                "Availability" => "Available",
                "PartnerTag" => $associateTag,
                "PartnerType" => "Associates",
                "Marketplace" => "www.amazon.fr",
                "ItemPage" => $page, // Ajout de la pagination
            ]);

            $headers = $this->aws4($payload)->getHeaders();

            // 5️⃣ Exécuter la requête et récupérer les résultats
            try {
                $response = $httpClient->request('POST', $url, [
                    'headers' => $headers,
                    'body' => $payload,
                ]);
                $data = $response->toArray();
            } catch (\Exception $e) {
                $output->writeln("Erreur lors de la requête Amazon : " . $e->getMessage());
                continue;
            }

            // 6️⃣ Sauvegarde temporaire des produits
            foreach ($data['SearchResult']['Items'] ?? [] as $item) {
                if (isset($item['Offers']['Listings'][0]['Price']['Amount'])) {
                    $product = new Produit();
                    $product->setNom($item['ItemInfo']['Title']['DisplayValue'] ?? 'Inconnu');
                    $product->setPrix($item['Offers']['Listings'][0]['Price']['Amount']);

                    if (isset($item['Offers']['Listings'][0]['Price']['Savings']['Percentage'])) {
                        $product->setPromo($item['Offers']['Listings'][0]['Price']['Savings']['Percentage']);
                        $product->addTag($tagPromo);
                    }

                    $product->setImage($item['Images']['Primary']['Large']['URL'] ?? null);
                    $product->setLien($item['DetailPageURL']);
                    $product->setDescription($item['ItemInfo']['Title']['DisplayValue']);

                    if (
                        !empty($item['BrowseNodeInfo']['BrowseNodes'][0]['SalesRank']) &&
                        $item['BrowseNodeInfo']['BrowseNodes'][0]['SalesRank'] == 1
                    ) {
                        if ($tagTopVentes) {
                            $product->addTag($tagTopVentes);
                        }
                    }

                    $product->addCategorie($categorie);
                    $allProducts[] = $product;
                }
            }

            sleep(1);
        }

        return $allProducts;
    }

    private function requete_3($searchCategorie, $output, $httpClient, $entityManager)
    {
        $allProducts = [];

        $associateTag = $_ENV['AWS_ASSOCIATE_TAG'];

        // 🔹 Récupération des tags en base de données
        $tagRepo = $entityManager->getRepository(Tag::class);
        $tagPromo = $tagRepo->findOneBy(['nom' => 'Promo']);
        $tagTopVentes = $tagRepo->findOneBy(['nom' => 'Top Ventes']);

        // 🔹 Récupération des catégories en base de données
        $categorieRepo = $entityManager->getRepository(Categorie::class);
        $categorie = $categorieRepo->findOneBy(['nom' => "Gaming"]);

        $sousCategorieRepo =$entityManager->getRepository(SousCategorie::class);
        $sousCategorie = $sousCategorieRepo->findOneBy(['nom' => $searchCategorie]);

        // Requête API Amazon sur les plages de prix
        $output->writeln("Récupération des " . $searchCategorie);

        for ($page = 1; $page <= 1; $page++) {
            // $output->writeln("📄 Page $page/1 pour cette catégorie");

            $url = "https://webservices.amazon.fr/paapi5/searchitems";
            $payload = json_encode([
                "Keywords" => $searchCategorie,
                "Resources" => [
                    "ItemInfo.Title",
                    "Images.Primary.Large",
                    "ItemInfo.Features",
                    "Offers.Listings.Price",
                    "BrowseNodeInfo.BrowseNodes.SalesRank",
                ],-
                "Availability" => "Available",
                "PartnerTag" => $associateTag,
                "PartnerType" => "Associates",
                "Marketplace" => "www.amazon.fr",
                "ItemPage" => $page, // Ajout de la pagination
            ]);

            $headers = $this->aws4($payload)->getHeaders();

            // 5️⃣ Exécuter la requête et récupérer les résultats
            try {
                $response = $httpClient->request('POST', $url, [
                    'headers' => $headers,
                    'body' => $payload,
                ]);
                $data = $response->toArray();
            } catch (\Exception $e) {
                $output->writeln("Erreur lors de la requête Amazon : " . $e->getMessage());
                continue;
            }

            // 6️⃣ Sauvegarde temporaire des produits
            foreach ($data['SearchResult']['Items'] ?? [] as $item) {
                if (isset($item['Offers']['Listings'][0]['Price']['Amount'])) {
                    $product = new Produit();
                    $product->setNom($item['ItemInfo']['Title']['DisplayValue'] ?? 'Inconnu');
                    $product->setPrix($item['Offers']['Listings'][0]['Price']['Amount']);

                    if (isset($item['Offers']['Listings'][0]['Price']['Savings']['Percentage'])) {
                        $product->setPromo($item['Offers']['Listings'][0]['Price']['Savings']['Percentage']);
                        $product->addTag($tagPromo);
                    }

                    $product->setImage($item['Images']['Primary']['Large']['URL'] ?? null);
                    $product->setLien($item['DetailPageURL']);
                    $product->setDescription($item['ItemInfo']['Title']['DisplayValue']);

                    if (
                        !empty($item['BrowseNodeInfo']['BrowseNodes'][0]['SalesRank']) &&
                        $item['BrowseNodeInfo']['BrowseNodes'][0]['SalesRank'] == 1
                    ) {
                        if ($tagTopVentes) {
                            $product->addTag($tagTopVentes);
                        }
                    }

                    $product->addCategorie($categorie);
                    $product->addSousCategorie($sousCategorie);

                    $allProducts[] = $product;
                }
            }

            sleep(1);
        }

        return $allProducts;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        // Supprimer les anciens produits avec TRUNCATE
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $connection->executeStatement('TRUNCATE TABLE produit_sous_categorie');
        $connection->executeStatement('TRUNCATE TABLE produit_tag');
        $connection->executeStatement('TRUNCATE TABLE produit_categorie');
        $connection->executeStatement('TRUNCATE TABLE produit');
        $connection->executeStatement('TRUNCATE TABLE refresh_date');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        // $output->writeln('Table produit vidée avec TRUNCATE.');


        // Insère la date actuelle dans la colonne d de la table refresh_date
        $now = new \DateTime();
        $connection->insert('refresh_date', [
            'date' => $now->format('Y-m-d'),  // format date sans heure
        ]);


        // Insérer tous les produits en base
        $allProducts = [];

        $allProducts = array_merge(
            $allProducts,
            $this->requete_1($output, $this->httpClient, $this->entityManager),
            $this->requete_2("Jeux de société", $output, $this->httpClient, $this->entityManager),
            $this->requete_2("Jeux éducatifs", $output, $this->httpClient, $this->entityManager),
            $this->requete_2("Jeux plein air", $output, $this->httpClient, $this->entityManager),
            $this->requete_2("Livre pour enfant", $output, $this->httpClient, $this->entityManager),
            $this->requete_2("Gaming", $output, $this->httpClient, $this->entityManager),
            $this->requete_3("Consoles de jeux", $output, $this->httpClient, $this->entityManager),
            $this->requete_3("Jeux vidéo", $output, $this->httpClient, $this->entityManager),
            $this->requete_3("Accessoires Gaming", $output, $this->httpClient, $this->entityManager)
        );

        foreach ($allProducts as $product) {
            $this->entityManager->persist($product);
        }
        $this->entityManager->flush();

        $output->writeln(date('[Y-m-d H:i:s]') . ' ' . count($allProducts) . ' produits Amazon mis à jour avec succès !');
        return Command::SUCCESS;
    }
}