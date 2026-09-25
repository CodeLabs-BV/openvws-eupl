<?php

declare(strict_types=1);

namespace Shared\Domain\ApiKey;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Shared\Domain\Organisation\Organisation;

/**
 * @extends ServiceEntityRepository<ApiKey>
 */
class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    /**
     * @return list<ApiKey>
     */
    public function findForOrganisation(Organisation $organisation): array
    {
        return $this->findBy(['organisation' => $organisation->getId()], ['createdAt' => 'DESC']);
    }

    public function findOneByHash(string $keyHash): ?ApiKey
    {
        return $this->findOneBy(['keyHash' => $keyHash]);
    }

    public function save(ApiKey $apiKey, bool $flush = true): void
    {
        $this->getEntityManager()->persist($apiKey);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}