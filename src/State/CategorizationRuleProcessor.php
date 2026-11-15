<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\CategorizationRule;
use App\Entity\User;
use App\Repository\CategorizationRuleRepository;
use App\Service\TransactionCategorizer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST / PATCH /categorization_rules: the rule belongs to the current user, one rule per pattern and direction,
 * then it is applied to the existing transactions (async).
 */
final readonly class CategorizationRuleProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
        private CategorizationRuleRepository $ruleRepository,
        private TransactionCategorizer $categorizer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CategorizationRule
    {
        if (!$data instanceof CategorizationRule) {
            throw new \LogicException('data must be an instance of CategorizationRule');
        }

        if ($operation instanceof Post) {
            $user = $this->security->getUser();
            if (!$user instanceof User) {
                throw new \LogicException('A user is required to create a categorization rule.');
            }

            $data->setOwner($user);
        }

        $same = $this->ruleRepository->findOneSame($data->getOwner(), $data->getPattern(), $data->getDirection());
        if (null !== $same && $same !== $data) {
            throw new UnprocessableEntityHttpException('A rule already exists for this pattern and direction: update it instead.');
        }

        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        // Rule flushed: the existing transactions are categorized in the background
        $this->categorizer->requestOwnerCategorization($data->getOwner());

        return $result;
    }
}
