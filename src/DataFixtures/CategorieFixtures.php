<?php

namespace App\DataFixtures;

use App\Entity\Categorie;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Cocur\Slugify\Slugify;

class CategorieFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $slugify = new Slugify();

        $categories = [
            'Jouets' => 'images/categories/jouets.webp',
            'Jeux de société' => 'images/categories/jeux-de-societe.webp',
            'Gaming' => 'images/categories/gaming.webp',
            'Jeux éducatifs' => 'images/categories/jeux-educatifs.webp',
            'Jeux plein air' => 'images/categories/jeux-plein-air.webp',
            'Livres' => 'images/categories/livres.webp',
        ];

        foreach ($categories as $categoryName => $imagePath) {
            $category = new Categorie();
            $category->setNom($categoryName);
            $category->setSlug($slugify->slugify($categoryName)); // Génération du slug
            $category->setImage($imagePath);
            $manager->persist($category);
        }

        $manager->flush();
    }
}

