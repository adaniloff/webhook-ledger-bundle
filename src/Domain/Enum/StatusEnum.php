<?php

declare(strict_types=1);

namespace WebhookLedger\Domain\Enum;

enum StatusEnum: string
{
    case RECEIVED = 'r';
    case DISPATCHED = 'dis';
    case SUCCEEDED = 's';
    case FAILED = 'f';
    case DEAD = 'dead';

    public function isReplayable(): bool
    {
        return self::DEAD === $this;
    }
}
