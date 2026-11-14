<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Dto\CreateAccountConnection;
use App\Entity\Traits\DeletableTrait;
use App\Entity\Traits\TimestampableTrait;
use App\Entity\Traits\UuidTrait;
use App\Enum\RequisitionStatus;
use App\Repository\AccountConnectionRepository;
use App\State\AccountConnectionProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new Get(security: "is_granted('ACCOUNT_CONNECTION_READ', object)",),
        new GetCollection(security: "is_granted('ACCOUNT_CONNECTION_READ')",),
        new Post(
            security: "is_granted('ACCOUNT_CONNECTION_CREATE', object)",
            input: CreateAccountConnection::class
        ),
        new Patch(
            uriTemplate: '/account_connections/{id}/sync',
            denormalizationContext: ['groups' => []],
            security: "is_granted('ACCOUNT_CONNECTION_SYNC', object)",
        ),
        new Delete(security: "is_granted('ACCOUNT_CONNECTION_DELETE', object)")
    ],
    normalizationContext: ['groups' => ['account-connection:read', 'timestamp:read']],
    processor: AccountConnectionProcessor::class
)]
#[ORM\Entity(repositoryClass: AccountConnectionRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_EXTERNAL_ID', columns: ['external_id'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity('externalId')]
class AccountConnection
{

    use UuidTrait;
    use TimestampableTrait;
    use DeletableTrait;

    #[ORM\ManyToOne(inversedBy: 'accountConnections')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 50)]
    #[ORM\Column(length: 50)]
    private ?string $bankId = null;

    #[ORM\Column(length: 255)]
    private ?string $externalLink = null;

    #[ORM\Column(type: 'uuid')]
    #[Groups(['account-connection:read'])]
    private ?Uuid $externalId = null;

    #[ORM\Column(type: Types::ENUM, nullable: true, enumType: RequisitionStatus::class)]
    #[Groups(['account-connection:read'])]
    private ?RequisitionStatus $externalStatus = null;

    /**
     * End of the bank access granted by the End User Agreement (accepted + access_valid_for_days).
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['account-connection:read'])]
    private ?\DateTimeImmutable $accessExpiresAt = null;

    /**
     * @var Collection<int, Account>
     */
    #[ORM\OneToMany(targetEntity: Account::class, mappedBy: 'accountConnection')]
    private Collection $accounts;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->accounts = new ArrayCollection();
    }

    public function getExternalId(): ?Uuid
    {
        return $this->externalId;
    }

    public function setExternalId(Uuid $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }

    /**
     * @return RequisitionStatus|null
     */
    public function getExternalStatus(): ?RequisitionStatus
    {
        return $this->externalStatus;
    }

    public function setExternalStatus(?RequisitionStatus $externalStatus): static
    {
        $this->externalStatus = $externalStatus;

        return $this;
    }

    public function getAccessExpiresAt(): ?\DateTimeImmutable
    {
        return $this->accessExpiresAt;
    }

    public function setAccessExpiresAt(?\DateTimeImmutable $accessExpiresAt): static
    {
        $this->accessExpiresAt = $accessExpiresAt;

        return $this;
    }

    public function getBankId(): ?string
    {
        return $this->bankId;
    }

    public function setBankId(string $bankId): static
    {
        $this->bankId = $bankId;

        return $this;
    }

    public function getExternalLink(): ?string
    {
        return $this->externalLink;
    }

    public function setExternalLink(string $externalLink): static
    {
        $this->externalLink = $externalLink;

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

    /**
     * @return Collection<int, Account>
     */
    public function getAccounts(): Collection
    {
        return $this->accounts;
    }

    public function addAccount(Account $account): static
    {
        if (!$this->accounts->contains($account)) {
            $this->accounts->add($account);
            $account->setAccountConnection($this);
        }

        return $this;
    }

    public function removeAccount(Account $account): static
    {
        if ($this->accounts->removeElement($account)) {
            // set the owning side to null (unless already changed)
            if ($account->getAccountConnection() === $this) {
                $account->setAccountConnection(null);
            }
        }

        return $this;
    }
}
