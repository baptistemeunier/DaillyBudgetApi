<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use App\Repository\PushSubscriptionRepository;
use App\State\PushSubscriptionProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Web Push subscription of one browser / device of the user.
 * The id is the uuid v5 of the endpoint (URL namespace), computed by the client: subscribing is an idempotent PUT.
 */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(security: "is_granted('PUSH_SUBSCRIPTION_READ', object)"),
        // Subscribed devices of the user (scoped by CurrentUserExtension)
        new GetCollection(
            order: ['createdAt' => 'DESC', 'id' => 'DESC'],
            security: "is_granted('PUSH_SUBSCRIPTION_READ')",
        ),
        // Creates or replaces the subscription of this device; 422 when the id is not the uuid v5 of the endpoint
        new Put(
            denormalizationContext: ['groups' => ['push-subscription:write']],
            security: "is_granted('PUSH_SUBSCRIPTION_WRITE')",
            processor: PushSubscriptionProcessor::class,
            allowCreate: true,
        ),
        new Delete(security: "is_granted('PUSH_SUBSCRIPTION_WRITE', object)"),
    ],
    normalizationContext: ['groups' => ['push-subscription:read']],
)]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ApiProperty(identifier: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    /**
     * Push service URL of the browser. Unique: the id is derived from it.
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['push-subscription:read', 'push-subscription:write'])]
    #[Assert\NotBlank]
    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 2048)]
    private ?string $endpoint = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'keys.p256dh is required.')]
    #[Assert\Length(max: 255)]
    private ?string $p256dh = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'keys.auth is required.')]
    #[Assert\Length(max: 255)]
    private ?string $auth = null;

    /**
     * Language of the pushes sent to this device.
     */
    #[ORM\Column(length: 5)]
    #[Groups(['push-subscription:read', 'push-subscription:write'])]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['fr', 'en'])]
    private ?string $locale = null;

    /**
     * User-Agent of the request that subscribed, to name the device ("Chrome on Android").
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['push-subscription:read'])]
    private ?string $userAgent = null;

    #[ORM\Column]
    #[Groups(['push-subscription:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['push-subscription:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Last push accepted by the push service.
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['push-subscription:read'])]
    private ?\DateTimeImmutable $lastSuccessAt = null;

    /**
     * Id expected for an endpoint: uuid v5 of the endpoint in the URL namespace.
     */
    public static function idFor(string $endpoint): Uuid
    {
        return Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), $endpoint);
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function setId(Uuid $id): static
    {
        $this->id = $id;

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

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function setEndpoint(?string $endpoint): static
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function getP256dh(): ?string
    {
        return $this->p256dh;
    }

    public function getAuth(): ?string
    {
        return $this->auth;
    }

    /**
     * Keys of the browser PushSubscription (PushSubscription.toJSON().keys). Never exposed.
     *
     * @param array{p256dh?: string, auth?: string} $keys
     */
    #[Groups(['push-subscription:write'])]
    #[ApiProperty(example: ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'])]
    public function setKeys(array $keys): static
    {
        $this->p256dh = is_string($keys['p256dh'] ?? null) ? $keys['p256dh'] : null;
        $this->auth = is_string($keys['auth'] ?? null) ? $keys['auth'] : null;

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = null !== $userAgent ? mb_substr($userAgent, 0, 255) : null;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastSuccessAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }

    public function setLastSuccessAt(?\DateTimeImmutable $lastSuccessAt): static
    {
        $this->lastSuccessAt = $lastSuccessAt;

        return $this;
    }
}
