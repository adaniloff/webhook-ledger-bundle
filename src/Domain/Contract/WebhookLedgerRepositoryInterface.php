<?php

declare(strict_types=1);

namespace WebhookLedger\Domain\Contract;

use WebhookLedger\Domain\ValueObject\WebhookUuid;

interface WebhookLedgerRepositoryInterface
{
    /**
     * @param array<string, mixed> $headers
     */
    public function receive(
        string $source,
        string $externalEventId,
        string $payload,
        array $headers,
        bool $signatureValid,
        int $attempts = 0,
        int $version = 1,
    ): WebhookUuid;

    public function save(WebhookEntryInterface $entry, int $expectedVersion): void;

    public function markDispatched(WebhookUuid $uuid): void;

    public function markSucceeded(WebhookUuid $uuid): void;

    public function markFailed(WebhookUuid $uuid, string $error): void;

    public function markDead(WebhookUuid $uuid, string $error): void;
}
