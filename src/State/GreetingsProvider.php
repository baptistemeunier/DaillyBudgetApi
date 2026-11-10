<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Greetings;

class GreetingsProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $a = new Greetings();
        $a->hello = 22;
        return [$a];

        // Retrieve the state from somewhere
    }
}
