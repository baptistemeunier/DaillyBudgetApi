<?php

namespace App\Repository;

use App\Entity\NotificationSettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationSettings>
 */
class NotificationSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationSettings::class);
    }

    /**
     * Settings of the user, or the defaults when they never changed them (not persisted).
     */
    public function findOrDefault(User $owner): NotificationSettings
    {
        return $this->findOneBy(['owner' => $owner]) ?? new NotificationSettings()->setOwner($owner);
    }
}
