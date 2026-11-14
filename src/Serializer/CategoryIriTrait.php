<?php

namespace App\Serializer;

use App\Entity\Category;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * For objects embedded in a resource without being resources themselves (statement lines, splits, budget lines):
 * API Platform serializes their Category with the parent's context, so in HAL its _links.self is the parent IRI.
 * The categories are output as HAL links instead: _links.<attribute>.href = "/categories/<uuid>".
 *
 * Requires $this->router (UrlGeneratorInterface) and $this->normalizer (NormalizerAwareTrait).
 */
trait CategoryIriTrait
{
    /**
     * @param array<string, ?Category> $categories attribute => category
     */
    private function normalizeWithCategoryIris(object $data, ?string $format, array $context, string $alreadyCalled, array $categories): array
    {
        $context[$alreadyCalled] = true;
        $context[AbstractNormalizer::IGNORED_ATTRIBUTES] = [
            ...$context[AbstractNormalizer::IGNORED_ATTRIBUTES] ?? [],
            ...array_keys($categories),
        ];

        $normalized = $this->normalizer->normalize($data, $format, $context);

        // HAL links, as everywhere else; no link for a missing category (uncategorized split)
        foreach ($categories as $attribute => $category) {
            if ($category?->getId()) {
                $normalized['_links'][$attribute] = [
                    'href' => $this->router->generate('_api_/categories/{id}{._format}_get', ['id' => $category->getId()]),
                ];
            }
        }

        return $normalized;
    }
}
