<?php

namespace App\Controller;

use App\Repository\ProduitRepository;
use App\Repository\CategorieRepository;
use App\Repository\ArticleRepository;
use App\Repository\RefreshDateRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DefaultController extends AbstractController
{
    private function formatDateFr(?\DateTimeInterface $date): ?string
    {
        if (!$date) {
            return null;
     }

        setlocale(LC_TIME, 'fr_FR.UTF-8', 'fra', 'fr_FR', 'fr_FR@euro', 'fr_FR.utf8');
        return strftime('%e %B %Y', $date->getTimestamp());
    }

    #[Route('/', name: 'home')]
    public function index(ProduitRepository $produitRepository, CategorieRepository $categorieRepository, ArticleRepository $articleRepository, RefreshDateRepository $refreshDateRepository): Response
    {

        $categories = $categorieRepository->findAll();
        $articles = $articleRepository->findBy([], ['date' => 'DESC'], 3);

        $refreshDate = $refreshDateRepository->find(1);

        $formattedDate = null;
        if ($refreshDate) {
            $formattedDate = $this->formatDateFr($refreshDate->getDate());
        }

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

        $allProducts = array_merge($topVentes, $promos);

        $offerCount = count($allProducts);
        $lowPrice = null;
        $highPrice = null;
    
        foreach ($allProducts as $produit) {
            $prix = $produit->getPrix();
            if ($lowPrice === null || $prix < $lowPrice) {
                $lowPrice = $prix;
            }
            if ($highPrice === null || $prix > $highPrice) {
                $highPrice = $prix;
            }
        }

        return $this->render('default/index.html.twig', [
            'categories' => $categories,
            'articles' => $articles,
            'topVentes' => $topVentes,
            'promos' => $promos,
            'offerCount' => $offerCount,
            'lowPrice' => $lowPrice,
            'highPrice' => $highPrice,
            'formattedDate' => $formattedDate,
        ]);
    }


    #[Route('/{slug}', name: 'categorie_jouets', requirements: ['slug' => 'jouets'])]
    public function jouets(
        string $slug,
        CategorieRepository $categorieRepository,
        ProduitRepository $produitRepository,
        RefreshDateRepository $refreshDateRepository
    ): Response {
        $categories = $categorieRepository->findAll();

        // Récupérer la catégorie correspondante au slug
        $categorie = $categorieRepository->findOneBy(['slug' => $slug]);

        // Si la catégorie n'existe pas, renvoyer une erreur 404
        if (!$categorie) {
            throw $this->createNotFoundException("La catégorie demandée n'existe pas.");
        }

        $produitsAffiches = [-1]; // Tableau pour stocker les ID des produits déjà affichés

        // 1. Produits de la catégorie (4 produits)
        $produits = $produitRepository->createQueryBuilder('p')
            ->join('p.categorie', 'c')
            ->where('c.id = :categorieId')
            ->andWhere('p.id NOT IN (:exclus)')
            ->setParameter('categorieId', $categorie->getId())
            ->setParameter('exclus', $produitsAffiches)
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();

        foreach ($produits as $produit) {
            $produitsAffiches[] = $produit->getId();
        }

        // 2. Produits en promotion (4 produits)
        $promos = $produitRepository->createQueryBuilder('p')
            ->join('p.categorie', 'c')
            ->where('c.id = :categorieId')
            ->andWhere('p.promo IS NOT NULL')
            ->andWhere('p.promo > 0')
            ->andWhere('p.id NOT IN (:exclus)')
            ->setParameter('categorieId', $categorie->getId())
            ->setParameter('exclus', $produitsAffiches)
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();

        foreach ($promos as $promo) {
            $produitsAffiches[] = $promo->getId();
        }

        // 3. Produits à moins de 20€ (4 produits)
        $moinsDe20 = $produitRepository->createQueryBuilder('p')
            ->join('p.categorie', 'c')
            ->where('c.id = :categorieId')
            ->andWhere('p.prix < 20')
            ->andWhere('p.id NOT IN (:exclus)')
            ->setParameter('categorieId', $categorie->getId())
            ->setParameter('exclus', $produitsAffiches)
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();

        foreach ($moinsDe20 as $produit) {
            $produitsAffiches[] = $produit->getId();
        }

        // 4. Produits entre 20€ et 50€ (4 produits)
        $moinsDe50 = $produitRepository->createQueryBuilder('p')
            ->join('p.categorie', 'c')
            ->where('c.id = :categorieId')
            ->andWhere('p.prix >= 20')
            ->andWhere('p.prix < 50')
            ->andWhere('p.id NOT IN (:exclus)')
            ->setParameter('categorieId', $categorie->getId())
            ->setParameter('exclus', $produitsAffiches)
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();

        foreach ($moinsDe50 as $produit) {
            $produitsAffiches[] = $produit->getId();
        }

        // 5. Produits entre 50€ et 100€ (4 produits)
        $moinsDe100 = $produitRepository->createQueryBuilder('p')
            ->join('p.categorie', 'c')
            ->where('c.id = :categorieId')
            ->andWhere('p.prix >= 50')
            ->andWhere('p.prix < 100')
            ->andWhere('p.id NOT IN (:exclus)')
            ->setParameter('categorieId', $categorie->getId())
            ->setParameter('exclus', $produitsAffiches)
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();

        foreach ($moinsDe100 as $produit) {
            $produitsAffiches[] = $produit->getId();
        }

        // 6. Produits à plus de 100€ (4 produits)
        $plusDe100 = $produitRepository->createQueryBuilder('p')
            ->join('p.categorie', 'c')
            ->where('c.id = :categorieId')
            ->andWhere('p.prix >= 100')
            ->andWhere('p.id NOT IN (:exclus)')
            ->setParameter('categorieId', $categorie->getId())
            ->setParameter('exclus', $produitsAffiches)
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();

        foreach ($plusDe100 as $produit) {
            $produitsAffiches[] = $produit->getId();
        }

        $allProducts = array_merge($produits, $promos, $moinsDe20, $moinsDe50, $moinsDe100, $plusDe100);

        $offerCount = count($allProducts);
        $lowPrice = null;
        $highPrice = null;

        foreach ($allProducts as $produit) {
            $prix = $produit->getPrix();
            if ($lowPrice === null || $prix < $lowPrice) {
                $lowPrice = $prix;
            }
            if ($highPrice === null || $prix > $highPrice) {
                $highPrice = $prix;
            }
        }

        $refreshDate = $refreshDateRepository->find(1);

        $formattedDate = null;
        if ($refreshDate) {
            $formattedDate = $this->formatDateFr($refreshDate->getDate());
        }

        // Rendre un fichier Twig spécifique à la catégorie
        $template = sprintf('categories/%s.html.twig', $slug);

        return $this->render($template, [
            'categories' => $categories,
            'categorie' => $categorie,
            'produits' => $produits,
            'promos' => $promos,
            'moinsDe20' => $moinsDe20,
            'moinsDe50' => $moinsDe50,
            'moinsDe100' => $moinsDe100,
            'plusDe100' => $plusDe100,
            'offerCount' => $offerCount,
            'lowPrice' => $lowPrice,
            'highPrice' => $highPrice,
            'formattedDate' => $formattedDate,
            
        ]);
    }

    #[Route('/{slug}', name: 'categorie_gaming', requirements: ['slug' => 'gaming'])]
    public function gaming(
        string $slug,
        CategorieRepository $categorieRepository,
        ProduitRepository $produitRepository,
        RefreshDateRepository $refreshDateRepository
    ): Response {
        $categories = $categorieRepository->findAll();

        // Récupérer la catégorie correspondante au slug
        $categorie = $categorieRepository->findOneBy(['slug' => $slug]);

        // Si la catégorie n'existe pas, renvoyer une erreur 404
        if (!$categorie) {
            throw $this->createNotFoundException("La catégorie demandée n'existe pas.");
        }

        $produitsAffiches = [-1]; // Tableau pour stocker les ID des produits déjà affichés

        // 1. Produits de la catégorie (4 produits)
        $produits = $produitRepository->createQueryBuilder('p')
            ->join('p.categorie', 'c')
            ->where('c.id = :categorieId')
            ->andWhere('p.id NOT IN (:exclus)')
            ->setParameter('categorieId', $categorie->getId())
            ->setParameter('exclus', $produitsAffiches)
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();

        foreach ($produits as $produit) {
            $produitsAffiches[] = $produit->getId();
        }

        // 2. Produits en promotion (4 produits)
        $promos = $produitRepository->createQueryBuilder('p')
            ->join('p.categorie', 'c')
            ->where('c.id = :categorieId')
            ->andWhere('p.promo IS NOT NULL')
            ->andWhere('p.promo > 0')
            ->andWhere('p.id NOT IN (:exclus)')
            ->setParameter('categorieId', $categorie->getId())
            ->setParameter('exclus', $produitsAffiches)
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();

        foreach ($promos as $promo) {
            $produitsAffiches[] = $promo->getId();
        }

        // 3. Produits ayant la sous-catégorie "Jeux vidéo" (4 produits)
        $jeuxVideo = $produitRepository->createQueryBuilder('p')
            ->join('p.souscategorie', 'sc') // Jointure avec la table de sous-catégories
            ->join('p.categorie', 'c') // Jointure avec la catégorie
            ->where('c.id = :categorieId') // Filtrer par catégorie principale
            ->andWhere('sc.nom = :sousCategorieNom') // Filtrer par le nom de la sous-catégorie
            ->andWhere('p.id NOT IN (:exclus)') // Exclure les produits déjà affichés
            ->setParameter('categorieId', $categorie->getId())
            ->setParameter('sousCategorieNom', 'Jeux vidéo') // Nom de la sous-catégorie
            ->setParameter('exclus', $produitsAffiches)
            ->setMaxResults(4) // Limiter à 4 résultats
            ->getQuery()
            ->getResult();


        foreach ($jeuxVideo as $produit) {
            $produitsAffiches[] = $produit->getId();
        }

        // 4. Produits ayant la sous-catégorie "Jeux vidéo" (4 produits)
        $consoles = $produitRepository->createQueryBuilder('p')
            ->join('p.souscategorie', 'sc') // Jointure avec la table de sous-catégories
            ->join('p.categorie', 'c') // Jointure avec la catégorie
            ->where('c.id = :categorieId') // Filtrer par catégorie principale
            ->andWhere('sc.nom = :sousCategorieNom') // Filtrer par le nom de la sous-catégorie
            ->andWhere('p.id NOT IN (:exclus)') // Exclure les produits déjà affichés
            ->setParameter('categorieId', $categorie->getId())
            ->setParameter('sousCategorieNom', 'Consoles de jeux') // Nom de la sous-catégorie
            ->setParameter('exclus', $produitsAffiches)
            ->setMaxResults(4) // Limiter à 4 résultats
            ->getQuery()
            ->getResult();


        foreach ($jeuxVideo as $produit) {
            $produitsAffiches[] = $produit->getId();
        }

        // 5. Produits ayant la sous-catégorie "Jeux vidéo" (4 produits)
        $accessoiresGaming = $produitRepository->createQueryBuilder('p')
            ->join('p.souscategorie', 'sc') // Jointure avec la table de sous-catégories
            ->join('p.categorie', 'c') // Jointure avec la catégorie
            ->where('c.id = :categorieId') // Filtrer par catégorie principale
            ->andWhere('sc.nom = :sousCategorieNom') // Filtrer par le nom de la sous-catégorie
            ->andWhere('p.id NOT IN (:exclus)') // Exclure les produits déjà affichés
            ->setParameter('categorieId', $categorie->getId())
            ->setParameter('sousCategorieNom', 'Accessoires Gaming') // Nom de la sous-catégorie
            ->setParameter('exclus', $produitsAffiches)
            ->setMaxResults(4) // Limiter à 4 résultats
            ->getQuery()
            ->getResult();


        foreach ($jeuxVideo as $produit) {
            $produitsAffiches[] = $produit->getId();
        }

        $allProducts = array_merge($produits, $promos, $jeuxVideo, $consoles, $accessoiresGaming);

        $offerCount = count($allProducts);
        $lowPrice = null;
        $highPrice = null;

        foreach ($allProducts as $produit) {
            $prix = $produit->getPrix();
            if ($lowPrice === null || $prix < $lowPrice) {
                $lowPrice = $prix;
            }
            if ($highPrice === null || $prix > $highPrice) {
                $highPrice = $prix;
            }
        }

        $refreshDate = $refreshDateRepository->find(1);

        $formattedDate = null;
        if ($refreshDate) {
            $formattedDate = $this->formatDateFr($refreshDate->getDate());
        }


        // Rendre un fichier Twig spécifique à la catégorie
        $template = sprintf('categories/%s.html.twig', $slug);

        return $this->render($template, [
            'categories' => $categories,
            'categorie' => $categorie,
            'produits' => $produits,
            'promos' => $promos,
            'jeuxVideo' => $jeuxVideo,
            'consoles' => $consoles,
            'accessoiresGaming' => $accessoiresGaming,
            'offerCount' => $offerCount,
            'lowPrice' => $lowPrice,
            'highPrice' => $highPrice,
            'formattedDate' => $formattedDate,
        ]);
    }

    #[Route('/{slug}', name: 'categorie_autres', requirements: ['slug' => 'jeux-plein-air|jeux-de-societe|livres|jeux-educatifs'])]
    public function autresCategories(
        string $slug,
        CategorieRepository $categorieRepository,
        ProduitRepository $produitRepository,
        RefreshDateRepository $refreshDateRepository
    ): Response {
        $categories = $categorieRepository->findAll();

        // Récupérer la catégorie correspondante au slug
        $categorie = $categorieRepository->findOneBy(['slug' => $slug]);

        // Si la catégorie n'existe pas, renvoyer une erreur 404
        if (!$categorie) {
            throw $this->createNotFoundException("La catégorie demandée n'existe pas.");
        }

        $produitsAffiches = [-1]; // Tableau pour stocker les ID des produits déjà affichés

        // 1. Produits de la catégorie (4 produits)
        $produits = $produitRepository->createQueryBuilder('p')
            ->join('p.categorie', 'c')
            ->where('c.id = :categorieId')
            ->andWhere('p.id NOT IN (:exclus)')
            ->setParameter('categorieId', $categorie->getId())
            ->setParameter('exclus', $produitsAffiches)
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();

        foreach ($produits as $produit) {
            $produitsAffiches[] = $produit->getId();
        }

        // 2. Produits en promotion (4 produits)
        $promos = $produitRepository->createQueryBuilder('p')
            ->join('p.categorie', 'c')
            ->where('c.id = :categorieId')
            ->andWhere('p.promo IS NOT NULL')
            ->andWhere('p.promo > 0')
            ->andWhere('p.id NOT IN (:exclus)')
            ->setParameter('categorieId', $categorie->getId())
            ->setParameter('exclus', $produitsAffiches)
            ->setMaxResults(4)
            ->getQuery()
            ->getResult();

        foreach ($promos as $promo) {
            $produitsAffiches[] = $promo->getId();
        }

        // 3. Produits en promotion (4 produits)
        $produits2 = $produitRepository->createQueryBuilder('p')
            ->join('p.categorie', 'c')
            ->where('c.id = :categorieId')
            ->andWhere('p.id NOT IN (:exclus)')
            ->setParameter('categorieId', $categorie->getId())
            ->setParameter('exclus', $produitsAffiches)
            ->setMaxResults(8)
            ->getQuery()
            ->getResult();

        foreach ($produits2 as $produit) {
            $produitsAffiches[] = $produit->getId();
        }

        $allProducts = array_merge($produits, $promos, $produits2);

        $offerCount = count($allProducts);
        $lowPrice = null;
        $highPrice = null;

        foreach ($allProducts as $produit) {
            $prix = $produit->getPrix();
            if ($lowPrice === null || $prix < $lowPrice) {
                $lowPrice = $prix;
            }
            if ($highPrice === null || $prix > $highPrice) {
                $highPrice = $prix;
            }
        }

        $refreshDate = $refreshDateRepository->find(1);

        $formattedDate = null;
        if ($refreshDate) {
            $formattedDate = $this->formatDateFr($refreshDate->getDate());
        }

        // Rendre un fichier Twig spécifique à la catégorie
        $template = sprintf('categories/%s.html.twig', $slug);

        return $this->render($template, [
            'categories' => $categories,
            'categorie' => $categorie,
            'produits' => $produits,
            'promos' => $promos,
            'produits2' => $produits2,
            'offerCount' => $offerCount,
            'lowPrice' => $lowPrice,
            'highPrice' => $highPrice,
            'formattedDate' => $formattedDate,
        ]);
    }

    #[Route('/top-ventes', name: 'top_ventes')]
    public function topVentes(
        CategorieRepository $categorieRepository,
        RefreshDateRepository $refreshDateRepository
    ): Response {
        $categories = $categorieRepository->findAll();
        $produitsParCategorie = [];

        foreach ($categories as $categorie) {
            $produitsParCategorie[$categorie->getNom()] = $categorie->getProduits()->slice(0, 4);
        }

        $allProducts = array_merge(
            $produitsParCategorie['Jouets'] ?? [],
            $produitsParCategorie['Jeux de société'] ?? [],
            $produitsParCategorie['Gaming'] ?? [],
            $produitsParCategorie['Jeux éducatifs'] ?? [],
            $produitsParCategorie['Jeux plein air'] ?? [],
            $produitsParCategorie['Livres'] ?? []
        );

        $offerCount = count($allProducts);
        $lowPrice = null;
        $highPrice = null;

        foreach ($allProducts as $produit) {
            $prix = $produit->getPrix();
            if ($lowPrice === null || $prix < $lowPrice) {
                $lowPrice = $prix;
            }
            if ($highPrice === null || $prix > $highPrice) {
                $highPrice = $prix;
            }
        }

        $refreshDate = $refreshDateRepository->find(1);

        $formattedDate = null;
        if ($refreshDate) {
            $formattedDate = $this->formatDateFr($refreshDate->getDate());
        }


        return $this->render('default/top-ventes.html.twig', [
            'categories' => $categories,
            'produitsParCategorie' => $produitsParCategorie,
            'offerCount' => $offerCount,
            'lowPrice' => $lowPrice,
            'highPrice' => $highPrice,
            'formattedDate' => $formattedDate,
        ]);
    }

    #[Route('/promotions', name: 'promotions')]
    public function promotions(
        CategorieRepository $categorieRepository,
        RefreshDateRepository $refreshDateRepository
    ): Response {
        // Récupérer toutes les catégories
        $categories = $categorieRepository->findAll();
        $produitsParCategorie = [];

        // Parcourir les catégories
        foreach ($categories as $categorie) {
            // Filtrer les produits ayant une promotion > 0
            $produitsAvecPromos = $categorie->getProduits()->filter(function ($produit) {
                return $produit->getPromo() !== null && $produit->getPromo() > 0;
            });

            // Limiter à 4 produits
            $produitsParCategorie[$categorie->getNom()] = $produitsAvecPromos->slice(0, 4);
        }

        $allProducts = array_merge(
            $produitsParCategorie['Jouets'] ?? [],
            $produitsParCategorie['Jeux de société'] ?? [],
            $produitsParCategorie['Gaming'] ?? [],
            $produitsParCategorie['Jeux éducatifs'] ?? [],
            $produitsParCategorie['Jeux plein air'] ?? [],
            $produitsParCategorie['Livres'] ?? []
        );

        $offerCount = count($allProducts);
        $lowPrice = null;
        $highPrice = null;

        foreach ($allProducts as $produit) {
            $prix = $produit->getPrix();
            if ($lowPrice === null || $prix < $lowPrice) {
                $lowPrice = $prix;
            }
            if ($highPrice === null || $prix > $highPrice) {
                $highPrice = $prix;
            }
        }

        $refreshDate = $refreshDateRepository->find(1);

        $formattedDate = null;
        if ($refreshDate) {
            $formattedDate = $this->formatDateFr($refreshDate->getDate());
        }

        return $this->render('default/promotions.html.twig', [
            'categories' => $categories,
            'produitsParCategorie' => $produitsParCategorie,
            'offerCount' => $offerCount,
            'lowPrice' => $lowPrice,
            'highPrice' => $highPrice,
            'formattedDate' => $formattedDate,
        ]);
    }

    #[Route('/plan-du-site', name: 'plan_site')]
    public function planSite(
        CategorieRepository $categorieRepository
    ): Response {
        // Récupérer toutes les catégories
        $categories = $categorieRepository->findAll();

        return $this->render('default/plan-de-site.html.twig', [
            'categories' => $categories,
        ]);
    }

    #[Route('/mentions-legales', name: 'mentions_legales')]
    public function mentionsLegales(
        CategorieRepository $categorieRepository
    ): Response {
        // Récupérer toutes les catégories
        $categories = $categorieRepository->findAll();

        return $this->render('default/mentions-legales.html.twig', [
            'categories' => $categories,
        ]);
    }

    #[Route('/sitemap.xml', name: 'sitemap')]
    public function sitemap(RefreshDateRepository $refreshDateRepository): Response
    {

        $refreshDate = $refreshDateRepository->find(1);
        $date = $refreshDate->getDate()->format('Y-m-d');

        $urls = [
            ['loc' => 'https://reperehub.com/', 'lastmod' => $date, 'priority' => '1.0'],
            ['loc' => 'https://reperehub.com/jouets', 'lastmod' => '2025-07-20', 'priority' => '1.0'],
            ['loc' => 'https://reperehub.com/jeux-de-societe', 'lastmod' => $date, 'priority' => '1.0'],
            ['loc' => 'https://reperehub.com/gaming', 'lastmod' => $date, 'priority' => '1.0'],
            ['loc' => 'https://reperehub.com/jeux-educatifs', 'lastmod' => $date, 'priority' => '1.0'],
            ['loc' => 'https://reperehub.com/jeux-plein-air', 'lastmod' => $date, 'priority' => '1.0'],
            ['loc' => 'https://reperehub.com/livres', 'lastmod' => $date, 'priority' => '1.0'],
            ['loc' => 'https://reperehub.com/top-ventes', 'lastmod' => $date, 'priority' => '1.0'],
            ['loc' => 'https://reperehub.com/promotions', 'lastmod' => $date, 'priority' => '1.0'],
        ];

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="https://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');

        foreach ($urls as $url) {
            $urlTag = $xml->addChild('url');
            $urlTag->addChild('loc', $url['loc']);
            $urlTag->addChild('lastmod', $url['lastmod']);
            $urlTag->addChild('priority', $url['priority']);
        }

        $response = new Response($xml->asXML());
        $response->headers->set('Content-Type', 'application/xml');

        return $response;
    }

}
