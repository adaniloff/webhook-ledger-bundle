<?php

declare(strict_types=1);

namespace WebhookLedger\Tests\Fixtures\Adapter;

use WebhookLedger\Domain\Contract\SourceAdapterInterface;

final class FakeSourceAdapter implements SourceAdapterInterface
{
    public const NAME = 'fake';

    public function getName(): string
    {
        return self::NAME;
    }

    public function externalEventId(array $headers, string $raw): ?string
    {
        return $headers['x-event-id'][0] ?? null;
    }

    public function verify(array $headers, string $raw): bool
    {
        return true;
    }
}
