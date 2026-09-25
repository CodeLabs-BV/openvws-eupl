<?php

declare(strict_types=1);

namespace Shared\Domain\Setting;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $setting = $this->find($key);

        return $setting?->getValue() ?? $default;
    }

    public function set(string $key, ?string $value): void
    {
        $setting = $this->find($key);
        if ($setting === null) {
            $setting = new Setting($key, $value);
            $this->getEntityManager()->persist($setting);
        } else {
            $setting->setValue($value);
        }

        $this->getEntityManager()->flush();
    }
}