<?php

declare(strict_types=1);

namespace WebhookLedger\Application\Worker\Message;

use WebhookLedger\Domain\ValueObject\IntegrationEventInterface;

final readonly class ProcessWebhookEvent implements IntegrationEventInterface
{
    public function __construct(public string $uuid) {}
}
