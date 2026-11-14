<?php

namespace App\Command;

use App\Entity\Category;
use App\Enum\CategoryType;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Upserts the system categories (owner = null) from config/categories.json, matched by code.
 * Idempotent: run it on every deploy. System categories no longer in the catalog are soft-deleted.
 */
#[AsCommand(
    name: 'app:categories:sync',
    description: 'Synchronise the system categories from config/categories.json',
)]
final readonly class CategorySyncCommand
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/config/categories.json')]
        private string $catalogPath,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $catalog = json_decode(file_get_contents($this->catalogPath), true, flags: JSON_THROW_ON_ERROR);

        /** @var array<string, Category> $existing */
        $existing = [];
        foreach ($this->categoryRepository->findBy(['owner' => null]) as $category) {
            if (null !== $category->getCode()) {
                $existing[$category->getCode()] = $category;
            }
        }

        $created = 0;
        $seen = [];

        $upsert = function (string $code, ?Category $parent) use (&$existing, &$created, &$seen): Category {
            if (isset($seen[$code])) {
                throw new \LogicException(sprintf('Duplicate category code "%s" in catalog.', $code));
            }
            $seen[$code] = true;

            $category = $existing[$code] ?? null;
            if (!$category) {
                $category = new Category()->setCode($code);
                $this->entityManager->persist($category);
                $existing[$code] = $category;
                ++$created;
            }

            return $category
                ->setName(null)
                ->setOwner(null)
                ->setParent($parent)
                ->setDeletedAt(null);
        };

        foreach ($catalog['categories'] as $item) {
            // Roots carry the type; sub-categories inherit it in setParent()
            $category = $upsert($item['id'], null)->setType(CategoryType::from($item['type']));

            foreach ($item['subcategories'] ?? [] as $subItem) {
                $upsert($subItem['id'], $category);
            }
        }

        // User sub-categories follow the type of their (system) parent
        foreach ($this->categoryRepository->findAll() as $category) {
            if (null !== $category->getOwner() && $category->getParent()) {
                $category->setType($category->getParent()->getType());
            }
        }

        $deleted = 0;
        foreach ($existing as $code => $category) {
            if (!isset($seen[$code]) && null === $category->getDeletedAt()) {
                $category->setDeletedAt(new \DateTimeImmutable());
                ++$deleted;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Catalog v%s: %d categories (%d created, %d soft-deleted).',
            $catalog['version'] ?? '?',
            count($seen),
            $created,
            $deleted,
        ));

        return Command::SUCCESS;
    }
}
