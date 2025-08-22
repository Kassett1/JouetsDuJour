<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\CategorieRepository;
use App\Repository\ProduitArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ArticlesController extends AbstractController
{
    private function formatDateFr( ? \DateTimeInterface $date): ?string
    {
        if (!$date) {
            return null;
        }

        setlocale(LC_TIME, 'fr_FR.UTF-8', 'fra', 'fr_FR', 'fr_FR@euro', 'fr_FR.utf8');
        return strftime('%e %B %Y', $date->getTimestamp());
    }

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
    public function legoVoitures(CategorieRepository $categorieRepository, ArticleRepository $articleRepository, ProduitArticleRepository $produitArticleRepository): Response
    {
        $categories = $categorieRepository->findAll();

        $article = $articleRepository->find(1);

        $formattedDate = $this->formatDateFr($article->getDate());

        $produits = $produitArticleRepository->findBy([
            'article' => 1,
        ]);

        $produits1 = array_slice($produits, 0, 4);
        $produits2 = array_slice($produits, 4, 4);
        $produits3 = array_slice($produits, 8, 4);
        $produits4 = array_slice($produits, 12, 4);
        $produits5 = array_slice($produits, 16, 4);

        $offerCount = count($produits);

        return $this->render('articles/lego-voitures.html.twig', [
            'categories' => $categories,
            'formattedDate' => $formattedDate,
            'produits' => $produits,
            'produits1' => $produits1,
            'produits2' => $produits2,
            'produits3' => $produits3,
            'produits4' => $produits4,
            'produits5' => $produits5,
            'article' => $article,
        ]);
    }

}
