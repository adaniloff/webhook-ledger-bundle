<?php

declare(strict_types=1);

namespace WebhookLedger\Domain\ValueObject;

use WebhookLedger\Domain\Enum\StatusEnum;

final readonly class WebhookCriteria
{
    public function __construct(
        public ?string $source = null,
        public ?StatusEnum $status = null,
        public ?int $page = null,
        public ?int $limit = null,
    ) {}
}
