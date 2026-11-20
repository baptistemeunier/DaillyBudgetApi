<?php
namespace App\Serializer;

use App\Entity\CategorizationRule;
use App\Security\Voter\CategorizationRuleVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Rules are shared: anyone edits one, only its owner deletes it.
 * _links.delete is present only when the current user can delete the rule (the client shows the button from it).
 */
#[AutoconfigureTag('serializer.normalizer', ['priority' => 64])]
class CategorizationRuleNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string ALREADY_CALLED = 'CATEGORIZATION_RULE_NORMALIZER_ALREADY_CALLED';

    public function __construct(
        private UrlGeneratorInterface $router,
        private Security $security,
    ) {
    }

    public function normalize($data, $format = null, array $context = []): float|int|bool|\ArrayObject|array|string|null
    {
        if (!$data instanceof CategorizationRule) {
            throw new \LogicException('object must be an instance of CategorizationRule');
        }

        $context[self::ALREADY_CALLED] = true;
        $normalized = $this->normalizer->normalize($data, $format, $context);

        if (is_array($normalized) && $this->security->isGranted(CategorizationRuleVoter::DELETE, $data)) {
            $normalized['_links']['delete'] = [
                'href' => $this->router->generate('_api_/categorization_rules/{id}{._format}_get', ['id' => $data->getId()]),
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

        return $data instanceof CategorizationRule;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            CategorizationRule::class => false,
        ];
    }
}
