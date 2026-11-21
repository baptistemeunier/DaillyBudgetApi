<?php

namespace App\Security;

use App\Entity\User;
use App\Enum\Role;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

readonly class OAuthUserProvider implements UserProviderInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager
    )
    {

    }

    public function loadUserByIdentifier(string $identifier, array $claims = []): UserInterface
    {
        // Recherche par subject OAuth (sub)
        $user = $this->userRepository->findOneBy(['username' => $identifier]);

        if (!$user) {
            $user = new User();
            $user
                ->setUsername($identifier);

            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }

        // Scopes come from the `scp` claim (array) of the Authelia JWT access token
        $roles = ['ROLE_USER'];
        foreach ((array) ($claims['scp'] ?? []) as $scope) {
            $roles[] = 'ROLE_' . $scope;
        }
        $user->setRoles($roles);
        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class;
    }
}
