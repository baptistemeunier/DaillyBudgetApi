<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\NotificationSettings;
use App\Entity\User;
use App\Repository\NotificationSettingsRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Settings created through the API always belong to the current user, who can only have one.
 * (The owner is set after validation, so UniqueEntity cannot catch it.)
 */
final readonly class NotificationSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private Security $security,
        private NotificationSettingsRepository $repository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NotificationSettings
    {
        if (!$data instanceof NotificationSettings) {
            throw new \LogicException('data must be an instance of NotificationSettings');
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('A user is required to create notification settings.');
        }

        if ($this->repository->count(['owner' => $user]) > 0) {
            $message = 'The user already has notification settings.';
            throw new ValidationException(new ConstraintViolationList([
                new ConstraintViolation($message, $message, [], $data, 'owner', null),
            ]));
        }

        $data->setOwner($user);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
