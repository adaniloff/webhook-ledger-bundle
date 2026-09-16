<?php

declare(strict_types=1);

namespace WebhookLedger\Application\Receiver\Exception;

use WebhookLedger\Domain\ValueObject\WebhookUuid;

final class WebhookEntryDuplicationException extends \RuntimeException
{
    public function __construct(private WebhookUuid $uuid, \Throwable $previous)
    {
        parent::__construct(previous: $previous);
    }

    public function getIdentifier(): string
    {
        return (string) $this->uuid;
    }
}
