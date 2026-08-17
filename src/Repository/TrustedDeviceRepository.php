<?php

namespace App\Repository;

use App\Entity\TrustedDevice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrustedDevice>
 */
class TrustedDeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrustedDevice::class);
    }

    public function findValidBySelector(string $selector): ?TrustedDevice
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.selector = :selector')
            ->andWhere('d.expiresAt > :now')
            ->setParameter('selector', $selector)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}
