<?php

namespace App\Controller;

use App\Repository\ProduitRepository;
use App\Repository\CategorieRepository;
use App\Repository\ArticleRepository;
use App\Repository\RefreshDateRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ArticlesController extends AbstractController
{

    #[Route('/articles', name: 'articles')]
    public function index(CategorieRepository $categorieRepository, ArticleRepository $articleRepository): Response
    {   
        $categories = $categorieRepository->findAll();

        $articles = $articleRepository->findAll();

        return $this->render('default/articles.html.twig', [
            'categories' => $categories,
            'articles' => $articles,
        ]);
    }

    #[Route('/lego-voitures', name: 'lego-voitures')]
    public function legoVoitures(CategorieRepository $categorieRepository): Response
    {   
        $categories = $categorieRepository->findAll();       

        return $this->render('default/lego-voitures.html.twig', [
            'categories' => $categories,
       
        ]);
    }

   

}
