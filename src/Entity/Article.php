<?php

namespace App\Entity;

use App\Repository\ArticleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ArticleRepository::class)]
class Article
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(length: 255)]
    private ?string $image = null;

    #[ORM\Column(length: 255)]
    private ?string $lien = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column]
    private array $joursRafraichissement = [];

    /**
     * @var Collection<int, Requetes>
     */
    #[ORM\OneToMany(targetEntity: Requetes::class, mappedBy: 'article', orphanRemoval: true)]
    private Collection $requetes;

    /**
     * @var Collection<int, ProduitArticle>
     */
    #[ORM\OneToMany(targetEntity: ProduitArticle::class, mappedBy: 'article', orphanRemoval: true)]
    private Collection $produitArticles;

    public function __construct()
    {
        $this->requetes = new ArrayCollection();
        $this->produitArticles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getLien(): ?string
    {
        return $this->lien;
    }

    public function setLien(string $lien): static
    {
        $this->lien = $lien;

        return $this;
    }

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getJoursRafraichissement(): array
    {
        return $this->joursRafraichissement;
    }

    public function setJoursRafraichissement(array $joursRafraichissement): static
    {
        $this->joursRafraichissement = $joursRafraichissement;

        return $this;
    }

    /**
     * @return Collection<int, Requetes>
     */
    public function getRequetes(): Collection
    {
        return $this->requetes;
    }

    public function addRequete(Requetes $requete): static
    {
        if (!$this->requetes->contains($requete)) {
            $this->requetes->add($requete);
            $requete->setArticle($this);
        }

        return $this;
    }

    public function removeRequete(Requetes $requete): static
    {
        if ($this->requetes->removeElement($requete)) {
            // set the owning side to null (unless already changed)
            if ($requete->getArticle() === $this) {
                $requete->setArticle(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ProduitArticle>
     */
    public function getProduitArticles(): Collection
    {
        return $this->produitArticles;
    }

    public function addProduitArticle(ProduitArticle $produitArticle): static
    {
        if (!$this->produitArticles->contains($produitArticle)) {
            $this->produitArticles->add($produitArticle);
            $produitArticle->setArticle($this);
        }

        return $this;
    }

    public function removeProduitArticle(ProduitArticle $produitArticle): static
    {
        if ($this->produitArticles->removeElement($produitArticle)) {
            // set the owning side to null (unless already changed)
            if ($produitArticle->getArticle() === $this) {
                $produitArticle->setArticle(null);
            }
        }

        return $this;
    }
}
