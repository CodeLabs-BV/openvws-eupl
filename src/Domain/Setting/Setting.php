<?php

declare(strict_types=1);

namespace Shared\Domain\Setting;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Shared\Doctrine\TimestampableTrait;

#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Setting
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 100)]
    private string $key;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;

    public function __construct(string $key, ?string $value = null)
    {
        $this->key = $key;
        $this->value = $value;
        $this->createdAt = new CarbonImmutable();
        $this->updatedAt = new CarbonImmutable();
    }

    public function getId(): string
    {
        return $this->key;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): void
    {
        $this->value = $value;
        $this->updatedAt = new CarbonImmutable();
    }
}