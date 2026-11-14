<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Budget;
use App\Entity\User;
use App\Repository\BudgetRepository;
use ApiPlatform\Validator\Exception\ValidationException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A budget created through the API always belongs to the current user, who can only have one.
 * (The owner is set after validation, so UniqueEntity cannot catch it.)
 */
final readonly class BudgetProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
        private BudgetRepository $budgetRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Budget
    {
        if (!$data instanceof Budget) {
            throw new \LogicException('data must be an instance of Budget');
        }

        if ($operation instanceof Post) {
            $user = $this->security->getUser();
            if (!$user instanceof User) {
                throw new \LogicException('A user is required to create a budget.');
            }

            if ($this->budgetRepository->count(['owner' => $user]) > 0) {
                $message = 'The user already has a budget.';
                throw new ValidationException(new ConstraintViolationList([
                    new ConstraintViolation($message, $message, [], $data, 'owner', null),
                ]));
            }

            $data->setOwner($user);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
