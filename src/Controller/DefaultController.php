<?php

namespace App\Controller;

use App\Repository\ProduitRepository;
use App\Repository\CategorieRepository;
use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DefaultController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(ProduitRepository $produitRepository, CategorieRepository $categorieRepository, ArticleRepository $articleRepository): Response
    {

        $categories = $categorieRepository->findAll();
        $articles = $articleRepository->findBy([], ['date' => 'DESC'], 3);

        $topVentes = $produitRepository->createQueryBuilder('p')
            ->join('p.tag', 't') // Jointure avec la table des tags
            ->where('t.nom = :tagNom') // Utilise le champ "nom" dans l'entité Tag
            ->setParameter('tagNom', 'Top ventes') // Passe la valeur du tag
            ->setMaxResults(4) // Limite à 4 résultats
            ->getQuery()
            ->getResult();


        $promos = $produitRepository->createQueryBuilder('p')
            ->where('p.promo IS NOT NULL')
            ->andWhere('p.promo > 0')
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();


        return $this->render('default/index.html.twig', [
            'categories' => $categories,
            'articles' => $articles,
            'topVentes' => $topVentes,
            'promos' => $promos,
        ]);
    }
}
