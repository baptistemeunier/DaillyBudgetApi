<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Entity\Traits\UuidTrait;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface
{

    use UuidTrait;
    use TimestampableTrait;

    #[ORM\Column(length: 255)]
    private ?string $username = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoggedAt = null;

    private array $roles;

    /**
     * @var Collection<int, AccountConnection>
     */
    #[ORM\OneToMany(targetEntity: AccountConnection::class, mappedBy: 'owner')]
    private Collection $accountConnections;

    public function __construct()
    {
        $this->accountConnections = new ArrayCollection();
        $this->id = Uuid::v7();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getLastLoggedAt(): ?\DateTimeImmutable
    {
        return $this->lastLoggedAt;
    }

    public function setLastLoggedAt(?\DateTimeImmutable $lastLoggedAt): static
    {
        $this->lastLoggedAt = $lastLoggedAt;

        return $this;
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    /**
     * @return Collection<int, AccountConnection>
     */
    public function getAccountConnections(): Collection
    {
        return $this->accountConnections;
    }

    public function addAccountConnection(AccountConnection $accountConnection): static
    {
        if (!$this->accountConnections->contains($accountConnection)) {
            $this->accountConnections->add($accountConnection);
            $accountConnection->setOwner($this);
        }

        return $this;
    }

    public function removeAccountConnection(AccountConnection $accountConnection): static
    {
        if ($this->accountConnections->removeElement($accountConnection)) {
            // set the owning side to null (unless already changed)
            if ($accountConnection->getOwner() === $this) {
                $accountConnection->setOwner(null);
            }
        }

        return $this;
    }
}
