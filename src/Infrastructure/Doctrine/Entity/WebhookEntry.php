<?php

declare(strict_types=1);

namespace WebhookLedger\Infrastructure\Doctrine\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use WebhookLedger\Domain\Contract\WebhookEntryInterface;
use WebhookLedger\Domain\Enum\StatusEnum;
use WebhookLedger\Domain\Exception\WebhookNotReplayableException;
use WebhookLedger\Domain\ValueObject\WebhookUuid;
use WebhookLedger\Infrastructure\Doctrine\Repository\WebhookLedgerRepository;

#[ORM\Entity(repositoryClass: WebhookLedgerRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_source_external_event_id', columns: ['source', 'external_event_id'])]
class WebhookEntry implements WebhookEntryInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /* @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(type: Types::GUID)]
    /* @phpstan-ignore-next-line */
    private ?string $uuid = null;

    #[ORM\Column(length: 255)]
    /* @phpstan-ignore-next-line */
    private ?string $source = null;

    #[ORM\Column(length: 255)]
    /* @phpstan-ignore-next-line */
    private ?string $external_event_id = null;

    #[ORM\Column(type: Types::TEXT)]
    /* @phpstan-ignore-next-line */
    private ?string $payload = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSONB)]
    /* @phpstan-ignore-next-line */
    private ?array $headers = null;

    #[ORM\Column]
    /* @phpstan-ignore-next-line */
    private ?bool $signature_valid = null;

    #[ORM\Column(enumType: StatusEnum::class)]
    private ?StatusEnum $status = null;

    #[ORM\Column]
    /* @phpstan-ignore-next-line */
    private ?int $attempts = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    /* @phpstan-ignore-next-line */
    private ?string $last_error = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $received_at = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updated_at = null;

    #[ORM\Column(type: 'integer'), ORM\Version]
    private int $version = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): WebhookUuid
    {
        \assert(null !== $this->uuid);

        return WebhookUuid::fromString($this->uuid);
    }

    public function getSource(): string
    {
        \assert(null !== $this->source);

        return $this->source;
    }

    public function getExternalEventId(): string
    {
        \assert(null !== $this->external_event_id);

        return $this->external_event_id;
    }

    public function getPayload(): string
    {
        \assert(null !== $this->payload);

        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        \assert(null !== $this->headers);

        return $this->headers;
    }

    public function isSignatureValid(): bool
    {
        \assert(null !== $this->signature_valid);

        return $this->signature_valid;
    }

    public function getStatus(): StatusEnum
    {
        \assert(null !== $this->status);

        return $this->status;
    }

    public function getAttempts(): int
    {
        \assert(null !== $this->attempts);

        return $this->attempts;
    }

    public function getLastError(): ?string
    {
        return $this->last_error;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        \assert(null !== $this->received_at);

        return $this->received_at;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        \assert(null !== $this->updated_at);

        return $this->updated_at;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function isReplayable(): bool
    {
        return $this->getStatus()->isReplayable() && $this->isSignatureValid();
    }

    public function replay(): void
    {
        if (!$this->isReplayable()) {
            throw new WebhookNotReplayableException(uuid: $this->getUuid());
        }

        $now = new \DateTimeImmutable();
        $this->status = StatusEnum::RECEIVED;
        $this->updated_at = $now;
        $this->received_at = $now;
    }
}
