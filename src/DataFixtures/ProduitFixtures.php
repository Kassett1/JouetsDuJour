<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use App\Entity\Produit;
use App\Entity\Tag;
use App\Entity\Categorie;
use App\Entity\SousCategorie;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;

class ProduitFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();

        // Récupération des entités existantes (tags, catégories et sous-catégories)
        $tags = $manager->getRepository(Tag::class)->findAll();
        $categories = $manager->getRepository(Categorie::class)->findAll();
        $SousCategories = $manager->getRepository(SousCategorie::class)->findAll();

        // Vérification que les entités nécessaires existent
        if (empty($tags) || empty($categories)) {
            throw new \Exception('Veuillez charger les fixtures des Tags, Catégories et Sous-Catégories avant celles des Produits.');
        }

        // Générer 50 produits
        for ($i = 0; $i < 200; $i++) {
            $produit = new Produit();

            // Remplir les propriétés du produit
            $produit->setNom($faker->words(3, true)); // Nom aléatoire
            $produit->setImage($faker->imageUrl()); // URL d'image aléatoire
            $produit->setLien($faker->url()); // Lien aléatoire
            $produit->setPrix($faker->randomFloat(2, 10, 150)); // Prix entre 10 et 100 €
            $produit->setPromo($faker->optional(0.5)->numberBetween(5, 50)); // Promo aléatoire ou null
            $produit->setDescription($faker->paragraph()); // Paragraphe de texte aléatoire

            // Assigner un tag aléatoire
            $randomTag = $tags[array_rand($tags)];
            $produit->addTag($randomTag);

            // Assigner une catégorie aléatoire
            $randomCategorie = $categories[array_rand($categories)];
            $produit->addCategorie($randomCategorie);

            // Si la catégorie est "Gaming", assigner une sous-catégorie aléatoire
            if ($randomCategorie->getNom() === 'Gaming' && !empty($SousCategories)) {
                $randomSousCategorie = $SousCategories[array_rand($SousCategories)];
                $produit->addSousCategorie($randomSousCategorie);
            }

            // Persist le produit
            $manager->persist($produit);
        }

        // Enregistrer tout en base de données
        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            TagFixtures::class,
            CategorieFixtures::class,
            SousCategorieFixtures::class,
        ];
    }

}
