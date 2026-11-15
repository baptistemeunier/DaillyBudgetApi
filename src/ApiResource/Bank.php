<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\State\BankProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new Get(security: "is_granted('ACCOUNT_CONNECTION_READ')",),
        new GetCollection(
            security: "is_granted('ACCOUNT_CONNECTION_READ')",
            parameters: [
                'country' => new QueryParameter(
                    description: 'ISO 3166-1 alpha-2 country code',
                    constraints: [
                        new Assert\NotBlank(),
                        new Assert\AtLeastOneOf([
                            new Assert\Country(),
                            new Assert\EqualTo("XX")
                        ])
                    ]
                ),
            ],
        ),
    ],
    normalizationContext: ['groups' => ['bank:read']],
    // The institution list is already cached server-side for 24h (BankProvider::CACHE_TTL)
    cacheHeaders: ['max_age' => 3600],
    provider: BankProvider::class
)]
class Bank
{
    #[ApiProperty(example: "REVOLUT_REVOGB21")]
    #[Groups(['bank:read'])]
    private ?string $id = null;

    #[ApiProperty(example: "Revolut")]
    #[Groups(['bank:read'])]
    private ?string $name = null;

    #[ApiProperty(example: "https://storage.googleapis.com/gc-prd-institution_icons-production/UK/PNG/revolut.png")]
    #[Groups(['bank:read'])]
    private ?string $logo = null;

    #[ApiProperty(example: ["GB", "FR"])]
    #[Groups(['bank:read'])]
    private ?array $countries = null;

    public static function fromInstitution(array $institution): Bank
    {
        return new Bank()
            ->setId($institution['id'])
            ->setName($institution['name'])
            ->setCountries($institution['countries'])
            ->setLogo($institution['logo']);
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): Bank
    {
        $this->id = $id;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): Bank
    {
        $this->name = $name;
        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): Bank
    {
        $this->logo = $logo;
        return $this;
    }

    public function getCountries(): ?array
    {
        return $this->countries;
    }

    public function setCountries(?array $countries): Bank
    {
        $this->countries = $countries;
        return $this;
    }
}
