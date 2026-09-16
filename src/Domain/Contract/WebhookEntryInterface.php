<?php

declare(strict_types=1);

namespace WebhookLedger\Domain\Contract;

use WebhookLedger\Domain\Enum\StatusEnum;
use WebhookLedger\Domain\ValueObject\WebhookUuid;

interface WebhookEntryInterface
{
    public function getUuid(): WebhookUuid;

    public function getSource(): string;

    public function getExternalEventId(): string;

    public function getPayload(): string;

    public function getHeaders(): mixed;

    public function isSignatureValid(): bool;

    public function getStatus(): StatusEnum;

    public function getAttempts(): int;

    public function getLastError(): ?string;

    public function getReceivedAt(): \DateTimeImmutable;

    public function getUpdatedAt(): \DateTimeImmutable;

    public function getVersion(): int;

    public function isReplayable(): bool;

    public function replay(): void;
}
