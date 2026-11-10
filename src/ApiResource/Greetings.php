<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\State\GreetingsProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new Get(
            provider: GreetingsProvider::class,
        ),
        new GetCollection(
            provider: GreetingsProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['greetings:read']],
)]
class Greetings
{
    #[Groups(['greetings:read'])]
    public int $id = 1;

    #[Groups(['greetings:read'])]
    public string $hello = '';

    /**
     * @return array<Greetings>
     */
    public static function provide(): array
    {
        dump("dddd");
        $greeting = new self();
        $greeting->hello = 'World!';

        return [$greeting];
    }
}
