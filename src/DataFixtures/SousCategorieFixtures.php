<?php

namespace App\DataFixtures;

use App\Entity\SousCategorie;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class SousCategorieFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $subCategories = [
            'Jeux vidéo',
            'Consoles',
            'Accessoires Gaming',
        ];

        foreach ($subCategories as $subCategoryName) {
            $subCategory = new SousCategorie();
            $subCategory->setNom($subCategoryName);
            $manager->persist($subCategory);
        }

        $manager->flush();
    }
}
