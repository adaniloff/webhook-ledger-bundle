<?php

declare(strict_types=1);

namespace WebhookLedger\Domain\Contract;

use WebhookLedger\Domain\ValueObject\NullOutboxWorkValueObject;
use WebhookLedger\Domain\ValueObject\OutboxWorkValueObject;
use WebhookLedger\Domain\ValueObject\WebhookUuid;

interface OutboxPersistenceInterface
{
    /**
     * @param callable(): (OutboxWorkValueObject|NullOutboxWorkValueObject) $work
     */
    public function run(callable $work): WebhookUuid;
}
