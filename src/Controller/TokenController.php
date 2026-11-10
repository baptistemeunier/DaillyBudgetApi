<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TokenController extends AbstractController
{
    #[Route('/oauth/token', methods: ['POST'])]
    public function token(
        Request $request,
        HttpClientInterface $client
    ): Response {
        $response = $client->request(
            'POST',
            'https://auth.meunier.ovh/oauth/access_token',
            [
                'body' => $request->request->all(),
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]
        );

        return new Response(
            $response->getContent(false),
            $response->getStatusCode(),
            ['Content-Type' => 'application/json']
        );
    }
}
