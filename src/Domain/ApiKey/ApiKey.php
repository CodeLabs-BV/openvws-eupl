<?php

declare(strict_types=1);

namespace Shared\Domain\ApiKey;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Shared\Doctrine\TimestampableTrait;
use Shared\Domain\HasId;
use Shared\Domain\Organisation\Organisation;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

use function hash;
use function random_bytes;
use function sprintf;
use function substr;

#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ApiKey implements HasId
{
    use TimestampableTrait;

    public const string TOKEN_PREFIX = 'ovws_';
    public const int KEY_PREFIX_LENGTH = 12;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organisation $organisation;

    #[ORM\Column(length: 255)]
    private string $name;

    /** Short, non-secret prefix used to display the key in the admin UI. */
    #[ORM\Column(length: self::KEY_PREFIX_LENGTH)]
    private string $keyPrefix;

    /** SHA-256 hash of the full token; the plaintext token is only shown once. */
    #[ORM\Column(length: 64, unique: true)]
    private string $keyHash;

    #[ORM\Column(type: 'boolean')]
    private bool $enabled = true;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    public function __construct(Organisation $organisation, string $name, string $plainToken, ?DateTimeImmutable $expiresAt = null)
    {
        $this->id = Uuid::v6();
        $this->organisation = $organisation;
        $this->name = $name;
        $this->keyPrefix = substr($plainToken, 0, self::KEY_PREFIX_LENGTH);
        $this->keyHash = self::hashToken($plainToken);
        $this->expiresAt = $expiresAt;
        $this->createdAt = new CarbonImmutable();
        $this->updatedAt = new CarbonImmutable();
    }

    public static function generateToken(): string
    {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(24));
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrganisation(): Organisation
    {
        return $this->organisation;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getKeyPrefix(): string
    {
        return $this->keyPrefix;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function disable(): void
    {
        $this->enabled = false;
        $this->updatedAt = new CarbonImmutable();
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(DateTimeImmutable $now): void
    {
        $this->lastUsedAt = $now;
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return $this->expiresAt === null || $this->expiresAt > $now;
    }

    public function getExpiresAgoLabel(): string
    {
        return sprintf('%s', $this->expiresAt?->format('Y-m-d H:i') ?? '—');
    }
}