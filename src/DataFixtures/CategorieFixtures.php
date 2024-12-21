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
        $faker = Factory::create();
        $slugify = new Slugify();

        $categories = [
            'Jouets',
            'Jeux de société',
            'Gaming',
            'Jeux éducatifs',
            'Jeux plein air',
            'Livres',
        ];

        foreach ($categories as $categoryName) {
            $category = new Categorie();
            $category->setNom($categoryName);
            $category->setSlug($slugify->slugify($categoryName)); // Génération du slug
            $category->setImage($faker->imageUrl());
            $manager->persist($category);
        }

        $manager->flush();
    }
}
