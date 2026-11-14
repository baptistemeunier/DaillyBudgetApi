<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Traits\DeletableTrait;
use App\Entity\Traits\TimestampableTrait;
use App\Entity\Traits\UuidTrait;
use App\Enum\CategoryType;
use App\Repository\CategoryRepository;
use App\State\CategoryProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_CODE', columns: ['code'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(
            normalizationContext: ['groups' => ['category:read', 'category:read-one', 'timestamp:read']],
            security: "is_granted('CATEGORY_READ', object)",),
        new GetCollection(security: "is_granted('CATEGORY_READ')",),
        new Post(
            security: "is_granted('CATEGORY_CREATE')",
            securityPostDenormalize: "is_granted('CATEGORY_CREATE', object)",
            denormalizationContext: ['groups' => ['category:create']],
            processor: CategoryProcessor::class,
        ),
        new Patch(
            denormalizationContext: ['groups' => ['category:create']],
            security: "is_granted('CATEGORY_EDIT', object)",
        ),
        new Delete(security: "is_granted('CATEGORY_DELETE', object)")
    ],
    normalizationContext: ['groups' => ['category:read', 'timestamp:read']],
)]
class Category
{
    use UuidTrait;
    use TimestampableTrait;
    use DeletableTrait;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['category:read'])]
    private ?string $code = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 100)]
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['category:read', 'category:create'])]
    private ?string $name = null;

    /**
     * Set by the catalog sync for system roots; inherited from the parent otherwise (see setParent()).
     */
    #[ORM\Column(length: 255, enumType: CategoryType::class, options: ['default' => 'expense'])]
    #[Groups(['category:read'])]
    private CategoryType $type = CategoryType::EXPENSE;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[Assert\NotBlank]
    #[Assert\Expression(
        'value === null or value.getParent() === null',
        message: 'The parent must be a root category.',
    )]
    #[Groups(['category:read', 'category:create'])]
    #[ApiProperty(readableLink: false, writableLink: false)]
    private ?self $parent = null;

    /**
     * @var Collection<int, self>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[Groups(['category:read-one'])]
    private Collection $children;

    #[ORM\ManyToOne]
    private ?User $owner = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->children = new ArrayCollection();
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        // A sub-category always has the type of its root
        if ($parent) {
            $this->type = $parent->getType();
        }

        return $this;
    }

    public function getType(): CategoryType
    {
        return $this->type;
    }

    public function setType(CategoryType $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(self $child): static
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }

        return $this;
    }

    public function removeChild(self $child): static
    {
        if ($this->children->removeElement($child)) {
            // set the owning side to null (unless already changed)
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }
}
