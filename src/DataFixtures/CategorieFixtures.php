<?php

namespace App\DataFixtures;

use App\Entity\Categorie;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class CategorieFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create();
        
        $categories = [
            'Jouets',
            'Jeux de société',
            'Gaming',
            'Jeux éducatifs',
            'Jeux plein air',
            'Livres pour enfants',
        ];

        foreach ($categories as $categoryName) {
            $category = new Categorie();
            $category->setNom($categoryName);
            $category->setImage($faker->imageUrl());
            $manager->persist($category);
        }

        $manager->flush();
    }
}
