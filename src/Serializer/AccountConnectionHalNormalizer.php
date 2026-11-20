<?php
namespace App\Serializer;

use App\Entity\AccountConnection;
use App\Security\Voter\AccountConnectionVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AutoconfigureTag('serializer.normalizer', ['priority' => 64])]
class AccountConnectionHalNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string ALREADY_CALLED = 'ACCOUNT_CONNECTION_NORMALIZER_ALREADY_CALLED';

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $router)
    {

    }

    public function normalize($data, $format = null, array $context = []): float|int|bool|\ArrayObject|array|string|null
    {
        if(!$data instanceof AccountConnection) {
            throw new \LogicException("object must be an instance of AccountConnection");
        }

        $context[self::ALREADY_CALLED] = true;
        $normalized = $this->normalizer->normalize($data, $format, $context);

        // Where the user finishes the connection (GoCardless consent page). Only for its owner
        // (the voter also requires a connection that needs the user)
        if($this->security->isGranted(AccountConnectionVoter::CONFIGURE, $data)) {
            $normalized['_links']['configure'] = [
                'href' => $data->getExternalLink()
            ];
        }

        // Actions reserved to the owner of the connection: the client shows their buttons only when the link is present
        $self = $this->router->generate('_api_/account_connections/{id}{._format}_get', ['id' => $data->getId()]);
        if($this->security->isGranted(AccountConnectionVoter::SYNC, $data)) {
            $normalized['_links']['sync'] = ['href' => $self.'/sync'];
        }
        if($this->security->isGranted(AccountConnectionVoter::DELETE, $data)) {
            $normalized['_links']['delete'] = ['href' => $self];
        }

        if($data->getBankId()) {
            $normalized['_links']['bank'] = [
                'href' => $this->router->generate(
                    '_api_/banks/{id}{._format}_get',
                    ['id' => $data->getBankId()],
                )
            ];
        }

        if($this->security->isGranted('ACCOUNT_READ')) {
            $normalized['_links']['accounts'] = [
                'href' => $this->router->generate(
                    '_api_/accounts{._format}_get_collection',
                    ['accountConnection' => $data->getId()],
                )
            ];
        }
        return $normalized;
    }

    public function supportsNormalization($data, $format = null, array $context = []): bool
    {
        // Make sure we're not called twice
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof AccountConnection;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            AccountConnection::class => false,
        ];
    }
}
