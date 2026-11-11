<?php

namespace App\Client;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

readonly class GoCardlessClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[Autowire(env: "GOCARDLESS_BASE_URL")] private string $baseUrl,
        #[Autowire(env: "GOCARDLESS_CLIENT_ID")] private string $secretId,
        #[Autowire(env: "GOCARDLESS_CLIENT_SECRET")] private string $secretKey,
    ) {}

    public function getAccessToken(): string
    {
        return $this->cache->get('GoCardlessClient_access_token', function () {
            $response = $this->httpClient->request(
                'POST',
                $this->baseUrl . '/api/v2/token/new/',
                [
                    'json' => [
                        'secret_id' => $this->secretId,
                        'secret_key' => $this->secretKey,
                    ],
                ]
            );

            $data = $response->toArray();

            return $data['access'];
        });
    }

    private function request(
        string $method,
        string $endpoint,
        array $options = []
    ): array {
        $options['headers']['Authorization'] =
            'Bearer ' . $this->getAccessToken();

        try {
            $response = $this->httpClient->request(
                $method,
                $this->baseUrl . $endpoint,
                $options
            );

            return $response->toArray();

        } catch (HttpExceptionInterface $exception) {
            if ($exception->getResponse()->getStatusCode() === 401) {
                $options['headers']['Authorization'] =
                    'Bearer ' . $this->getAccessToken();

                $response = $this->httpClient->request(
                    $method,
                    $this->baseUrl . $endpoint,
                    $options
                );

                return $response->toArray();
            }

            throw $exception;
        }
    }

    public function getInstitutions(array $filters = []): array {
        return $this->request(
            'GET',
            '/api/v2/institutions/',
            ['query' => $filters]
        );
    }

    public function createRequisition(array $data): array
    {
        return $this->request(
            'POST',
            '/api/v2/requisitions/',
            ['json' => $data]
        );
    }

    public function getRequisition(Uuid $id): array
    {
        return $this->request(
            'GET',
            "/api/v2/requisitions/{$id}/"
        );
    }

    public function deleteRequisition(Uuid $id): array
    {
        return $this->request(
            'DELETE',
            "/api/v2/requisitions/{$id->toString()}/"
        );
    }

    public function getAccount(string $id): array
    {
        return $this->request(
            'GET',
            "/api/v2/accounts/{$id}/"
        );
    }

    public function getAccountBalances(string $id): array
    {
        return $this->request(
            'GET',
            "/api/v2/accounts/{$id}/balances/"
        );
    }

    public function getAccountDetails(string $id): array
    {
        return $this->request(
            'GET',
            "/api/v2/accounts/{$id}/details/"
        );
    }

    public function getAccountTransactions(
        string $id,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $query = array_filter([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        return $this->request(
            'GET',
            "/api/v2/accounts/{$id}/transactions/",
            ['query' => $query]
        );
    }
}
