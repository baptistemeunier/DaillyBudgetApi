<?php

namespace App\Client;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
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

    private const string ACCESS_TOKEN_CACHE_KEY = 'GoCardlessClient_access_token';
    private const int DEFAULT_RETRY_AFTER = 60;

    public function getAccessToken(): string
    {
        return $this->cache->get(self::ACCESS_TOKEN_CACHE_KEY, function (ItemInterface $item) {
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

            // Expire the cached token a minute before GoCardless does (access_expires is in seconds, 24h by default).
            $item->expiresAfter(max(60, (int) ($data['access_expires'] ?? 86400) - 60));

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
            if ($exception->getResponse()->getStatusCode() !== 401) {
                throw $this->convertException($exception);
            }

            // Expired End User Agreement: a new token won't help, the bank must be reconnected.
            $summary = $exception->getResponse()->toArray(false)['summary'] ?? '';
            if (str_contains($summary, 'End User Agreement')) {
                throw new GoCardlessAccessExpiredException($summary, $exception);
            }
        }

        // Token expired or revoked: drop it from the cache and retry once with a fresh one.
        $this->cache->delete(self::ACCESS_TOKEN_CACHE_KEY);
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
            throw $this->convertException($exception);
        }
    }

    private function convertException(HttpExceptionInterface $exception): \Throwable
    {
        $response = $exception->getResponse();

        if ($response->getStatusCode() !== 429) {
            return $exception;
        }

        // GoCardless exposes the reset delay (seconds) under non-standard header names.
        $headers = $response->getHeaders(false);
        $retryAfter = self::DEFAULT_RETRY_AFTER;
        foreach (['retry-after', 'x-ratelimit-reset', 'http_x_ratelimit_reset'] as $name) {
            if (isset($headers[$name][0]) && is_numeric($headers[$name][0])) {
                $retryAfter = max(1, (int) $headers[$name][0]);
                break;
            }
        }

        return new GoCardlessRateLimitException($retryAfter, $exception);
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

    public function getAgreement(string $id): array
    {
        return $this->request(
            'GET',
            "/api/v2/agreements/enduser/{$id}/"
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
