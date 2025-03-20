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


    #[Route('/{slug}', name: 'categorie_jouets', requirements: ['slug' => 'jouets'])]
    public function jouets(
        string $slug,
        CategorieRepository $categorieRepository,
        ProduitRepository $produitRepository
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
        ]);
    }

    #[Route('/{slug}', name: 'categorie_gaming', requirements: ['slug' => 'gaming'])]
    public function gaming(
        string $slug,
        CategorieRepository $categorieRepository,
        ProduitRepository $produitRepository
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
        ]);
    }

    #[Route('/{slug}', name: 'categorie_autres', requirements: ['slug' => 'jeux-plein-air|jeux-de-societe|livres|jeux-educatifs'])]
    public function autresCategories(
        string $slug,
        CategorieRepository $categorieRepository,
        ProduitRepository $produitRepository
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

        // Rendre un fichier Twig spécifique à la catégorie
        $template = sprintf('categories/%s.html.twig', $slug);

        return $this->render($template, [
            'categories' => $categories,
            'categorie' => $categorie,
            'produits' => $produits,
            'promos' => $promos,
            'produits2' => $produits2,
        ]);
    }

    #[Route('/top-ventes', name: 'top_ventes')]
    public function topVentes(
        CategorieRepository $categorieRepository
    ): Response {
        $categories = $categorieRepository->findAll();
        $produitsParCategorie = [];

        foreach ($categories as $categorie) {
            $produitsParCategorie[$categorie->getNom()] = $categorie->getProduits()->slice(0, 4);
        }

        return $this->render('default/top-ventes.html.twig', [
            'categories' => $categories,
            'produitsParCategorie' => $produitsParCategorie,
        ]);
    }

    #[Route('/promotions', name: 'promotions')]
    public function promotions(
        CategorieRepository $categorieRepository
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

        return $this->render('default/promotions.html.twig', [
            'categories' => $categories,
            'produitsParCategorie' => $produitsParCategorie,
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

}
