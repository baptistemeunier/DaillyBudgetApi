<?php
namespace App\Serializer;

use App\Entity\Category;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AutoconfigureTag('serializer.normalizer', ['priority' => 64])]
class CategoryNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string ALREADY_CALLED = 'CATEGORY_ALREADY_CALLED';

    public function __construct(private UrlGeneratorInterface $router)
    {

    }

    public function normalize($data, $format = null, array $context = []): float|int|bool|\ArrayObject|array|string|null
    {
        if(!$data instanceof Category) {
            throw new \LogicException("object must be an instance of Category");
        }

        $context[self::ALREADY_CALLED] = true;
        $normalized = $this->normalizer->normalize($data, $format, $context);

        if($data->getParent()) {
            $normalized['_links']['parent'] = [
                'href' => $this->router->generate(
                    '_api_/categories/{id}{._format}_get',
                    ['id' => $data->getParent()->getId()],
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

        return $data instanceof Category;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Category::class => false,
        ];
    }
}
