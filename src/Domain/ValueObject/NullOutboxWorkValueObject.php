<?php

declare(strict_types=1);

namespace WebhookLedger\Domain\ValueObject;

final readonly class NullOutboxWorkValueObject
{
    public function __construct(public WebhookUuid $uuid) {}
}
