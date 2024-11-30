<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use App\Entity\Produit;
use App\Entity\Tag;
use App\Entity\Categorie;
use App\Entity\SousCategorie;

class ProduitFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();

        // Générer 5 tags
        $tags = [];
        for ($i = 0; $i < 5; $i++) {
            $tag = new Tag();
            $tag->setNom($faker->word());
            $manager->persist($tag);
            $tags[] = $tag; // Stocker pour réutilisation
        }

        // Générer 5 catégories
        $categories = [];
        for ($i = 0; $i < 5; $i++) {
            $categorie = new Categorie();
            $categorie->setNom($faker->word());
            $manager->persist($categorie);
            $categories[] = $categorie; // Stocker pour réutilisation
        }

        // Générer 5 sous-catégories
        $souscategories = [];
        for ($i = 0; $i < 5; $i++) {
            $souscategorie = new SousCategorie();
            $souscategorie->setNom($faker->word());
            $manager->persist($souscategorie);
            $souscategories[] = $souscategorie; // Stocker pour réutilisation
        }

        // Générer 50 produits
        for ($i = 0; $i < 50; $i++) {
            $produit = new Produit();
            $produit->setNom($faker->words(3, true)); // Nom aléatoire
            $produit->setImage($faker->imageUrl()); // URL d'image aléatoire
            $produit->setLien($faker->url()); // Lien aléatoire
            $produit->setPrix($faker->randomFloat(2, 10, 100)); // Prix entre 10 et 100 €
            $produit->setPromo($faker->optional(0.5)->numberBetween(5, 50)); // Promo aléatoire ou null

            // Associer entre 1 et 3 tags au produit
            for ($j = 0; $j < rand(1, 3); $j++) {
                $produit->addTag($faker->randomElement($tags));
            }

            // Associer entre 1 et 2 catégories au produit
            for ($j = 0; $j < rand(1, 2); $j++) {
                $produit->addCategorie($faker->randomElement($categories));
            }

            // Associer entre 1 et 2 sous-catégories au produit
            for ($j = 0; $j < rand(1, 2); $j++) {
                $produit->addSouscategorie($faker->randomElement($souscategories));
            }

            $manager->persist($produit); // Persist le produit
        }

        $manager->flush(); // Enregistre tout en base de données
    }

}
