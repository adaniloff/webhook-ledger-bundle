<?php

declare(strict_types=1);

namespace WebhookLedger\Application\Worker\Service;

use WebhookLedger\Domain\Contract\WebhookLedgerRepositoryInterface;
use WebhookLedger\Domain\ValueObject\WebhookUuid;

final readonly class WorkerProgressTracker
{
    public function __construct(private WebhookLedgerRepositoryInterface $repository) {}

    public function markDispatched(WebhookUuid $uuid): void
    {
        $this->repository->markDispatched(uuid: $uuid);
    }

    public function markSucceeded(WebhookUuid $uuid): void
    {
        $this->repository->markSucceeded(uuid: $uuid);
    }

    public function markFailed(WebhookUuid $uuid, string $error): void
    {
        $this->repository->markFailed(uuid: $uuid, error: $error);
    }

    public function markDead(WebhookUuid $uuid, string $error): void
    {
        $this->repository->markDead(uuid: $uuid, error: $error);
    }
}
