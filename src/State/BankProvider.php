<?php

namespace App\State;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\ArrayPaginator;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Bank;
use App\Client\GoCardlessClient;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

class BankProvider implements ProviderInterface
{
    public function __construct(
        private GoCardlessClient $goCardlessClient,
        private CacheInterface $cache,
        private Pagination $pagination
    )
    {

    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $banks = $this->getBanks();

        if($operation instanceof GetCollection) {
            $offset = $this->pagination->getOffset($operation, $context);
            $limit = $this->pagination->getLimit($operation, $context);

            $country = $context['filters']['country'];

            if(!empty($country)) {
                $banks = array_filter($banks, function (Bank $bank) use ($country) {
                    return in_array($country, $bank->getCountries());
                });
            }

            return new ArrayPaginator($banks, $offset, $limit);
        }

        $bank = array_find($banks, function (Bank $bank) use ($uriVariables) {
            return $bank->getId() === $uriVariables['id'];
        });

        if(empty($bank)) {
            throw new NotFoundHttpException('Bank not found');
        }

        return $bank;
    }

    /**
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function getBanks(): mixed
    {
        $banks = $this->cache->get("BANKS_ALL", function (ItemInterface $item) {
            $institutions = $this->goCardlessClient->getInstitutions();

            $item->expiresAfter(1200);

            return array_map(function ($institution) {
                return Bank::fromInstitution($institution);
            }, $institutions);
        });
        return $banks;
    }
}
