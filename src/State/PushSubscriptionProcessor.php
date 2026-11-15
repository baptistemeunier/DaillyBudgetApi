<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * PUT /push_subscriptions/{id}: creates or replaces the subscription of the device and gives it to the current user.
 *
 * Persisted here rather than by the Doctrine processor: its "standard PUT" would reset the fields not sent
 * (owner, createdAt...), and the row may belong to another user (same browser, other account), which
 * CurrentUserExtension hides from the read.
 */
final readonly class PushSubscriptionProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private PushSubscriptionRepository $repository,
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PushSubscription
    {
        if (!$data instanceof PushSubscription) {
            throw new \LogicException('data must be an instance of PushSubscription');
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('A user is required to subscribe.');
        }

        $id = $uriVariables['id'] ?? null;
        $id = $id instanceof Uuid ? $id : (is_string($id) && Uuid::isValid($id) ? Uuid::fromString($id) : null);

        if (null === $id || !$id->equals(PushSubscription::idFor((string) $data->getEndpoint()))) {
            $message = 'The id must be the uuid v5 of the endpoint (URL namespace).';
            throw new ValidationException(new ConstraintViolationList([
                new ConstraintViolation($message, $message, [], $data, 'id', $id?->toRfc4122()),
            ]));
        }

        // Found without the owner filter: the device may have been subscribed by another user
        $subscription = $this->repository->find($id);
        if (null === $subscription) {
            $subscription = new PushSubscription()->setId($id);
            $this->entityManager->persist($subscription);
        }

        $subscription
            ->setOwner($user)
            ->setEndpoint($data->getEndpoint())
            ->setKeys(['p256dh' => $data->getP256dh(), 'auth' => $data->getAuth()])
            ->setLocale($data->getLocale())
            ->setUserAgent($this->requestStack->getCurrentRequest()?->headers->get('User-Agent'));

        $this->entityManager->flush();

        return $subscription;
    }
}
