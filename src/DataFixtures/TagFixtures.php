<?php

namespace App\DataFixtures;

use App\Entity\Tag;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class TagFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $tags = [
            'Choix Amazon',
            'Top Ventes',
            'Promo',
        ];

        foreach ($tags as $tagName) {
            $tag = new Tag();
            $tag->setNom($tagName);
            $manager->persist($tag);
        }

        $manager->flush();
    }
}
