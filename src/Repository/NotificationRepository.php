<?php

namespace App\Repository;

use App\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Deletes the notifications read before the given date (unread ones are kept). Returns the number deleted.
     */
    public function deleteReadBefore(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('n')
            ->delete()
            ->andWhere('n.readAt IS NOT NULL')
            ->andWhere('n.readAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
