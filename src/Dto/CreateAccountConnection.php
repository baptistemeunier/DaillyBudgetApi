<?php

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

class CreateAccountConnection
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 50)]
    #[ApiProperty(example: "REVOLUT_REVOGB21")]
    public ?string $bankId = null;

    #[Assert\NotBlank]
    #[Assert\Url(protocols: ["http", "https"], requireTld: false)]
    #[ApiProperty(example: "http://www.yourwebpage.com")]
    public ?string $redirectUri = null;
}
