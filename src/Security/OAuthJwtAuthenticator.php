<?php

namespace App\Security;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class OAuthJwtAuthenticator extends AbstractAuthenticator
{
    const int CACHE_DURATION = 600;
    // Suffixed with a hash of the URL: changing OAUTH2_CONFIGURATION_URL never reuses the old server's cached config/keys
    const string CONFIG_CACHE_KEY = 'oauth.configuration.';
    const string JWKS_CACHE_KEY = 'oauth.jwks.';

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly CacheInterface $cache,
        private readonly OAuthUserProvider $userProvider,
        #[Autowire(env: 'OAUTH2_CONFIGURATION_URL')] public string $oauthConfigurationUrl,
        #[Autowire(env: 'OAUTH2_AUDIENCE')] public string $audience,
    )
    {
    }

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        if (!preg_match('/^Bearer\s+(\S+)$/i', $request->headers->get('Authorization', ''), $matches)) {
            throw new AuthenticationException('Invalid Bearer token');
        }

        $token = $matches[1];

        if(empty($this->oauthConfigurationUrl) || empty($this->audience)) {
            throw new AuthenticationException("OAuth configuration url or audience is empty");
        }

        $configuration = $this->getConfiguration();
        $keys = $this->getJwks($configuration);

        // Only signed JWT access tokens are accepted (the OIDC client must issue them, e.g. Authelia
        // access_token_signed_response_alg: RS256): an opaque token fails here
        try {
            $decoded = JWT::decode($token, $keys);
        } catch (Throwable $e) {
            throw new AuthenticationException("Invalid token", previous: $e);
        }

        # Control issuer
        if(empty($decoded->iss) || $decoded->iss !== $configuration['issuer']) {
            throw new AuthenticationException("Invalid token");
        }

        # Audience: the token must be issued for this API
        if (!in_array($this->audience, (array) ($decoded->aud ?? []), true)) {
            throw new AuthenticationException("Invalid token audience");
        }

        // Subject
        if (empty($decoded->sub)) {
            throw new AuthenticationException('Missing subject');
        }

        return new SelfValidatingPassport(
            new UserBadge(
                $decoded->sub,
                fn (string $identifier) => $this->userProvider->loadUserByIdentifier(
                    $identifier,
                    (array) $decoded
                )
            )
        );
    }

    public function onAuthenticationSuccess(
        Request $request,
        TokenInterface $token,
        string $firewallName
    ): ?Response {
        return null;
    }

    public function onAuthenticationFailure(
        Request $request,
        AuthenticationException $exception
    ): ?Response {
        return new JsonResponse([
            'error' => 'unauthorized',
            'message' => 'Invalid authentication credentials',
            'details' => $exception->getMessageKey(),
        ], Response::HTTP_UNAUTHORIZED);
    }

//    public function start(Request $request, ?AuthenticationException $authException = null): Response
//    {
//        /*
//         * If you would like this class to control what happens when an anonymous user accesses a
//         * protected page (e.g. redirect to /login), uncomment this method and make this class
//         * implement Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface.
//         *
//         * For more details, see https://symfony.com/doc/current/security/experimental_authenticators.html#configuring-the-authentication-entry-point
//         */
//    }
    /**
     * @return mixed|string
     * @throws AuthenticationException
     */
    public function getConfiguration(): mixed
    {
        try {
            return $this->cache->get(self::CONFIG_CACHE_KEY . hash('xxh128', $this->oauthConfigurationUrl), function (ItemInterface $item): array {
                $response = $this->client->request('GET', $this->oauthConfigurationUrl);

                $item->expiresAfter(self::CACHE_DURATION);

                return $response->toArray();
            });
        } catch (Throwable $e) {
            throw new AuthenticationException("OAuth configuration url is not responding", previous: $e);
        }
    }

    /**
     * @return array<string, Key> the signing keys, by kid
     */
    private function getJwks(mixed $configuration): array
    {
        if(empty($configuration["jwks_uri"])) {
            throw new AuthenticationException("JWKS URI is empty");
        }

        try {
            $jwks = $this->cache->get(self::JWKS_CACHE_KEY . hash('xxh128', $configuration["jwks_uri"]), function (ItemInterface $item) use ($configuration): array {
                $response = $this->client->request('GET', $configuration["jwks_uri"]);

                $item->expiresAfter(self::CACHE_DURATION);

                return $response->toArray();
            });
        } catch (Throwable $e) {
            throw new AuthenticationException("JWKS URI is not responding", previous: $e);
        }

        try {
            return JWK::parseKeySet($jwks);
        } catch (Throwable $e) {
            throw new AuthenticationException("JWKS is not valid", previous: $e);
        }
    }
}
