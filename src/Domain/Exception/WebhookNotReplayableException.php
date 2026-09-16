<?php

declare(strict_types=1);

namespace WebhookLedger\Domain\Exception;

use WebhookLedger\Domain\ValueObject\WebhookUuid;

final class WebhookNotReplayableException extends \RuntimeException
{
    public function __construct(private WebhookUuid $uuid, ?\Throwable $previous = null)
    {
        parent::__construct(previous: $previous);
    }

    public function getIdentifier(): string
    {
        return (string) $this->uuid;
    }
}
