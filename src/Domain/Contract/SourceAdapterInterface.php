<?php

declare(strict_types=1);

namespace WebhookLedger\Domain\Contract;

interface SourceAdapterInterface
{
    public function getName(): string;

    /**
     * @param array<string, list<string|null>> $headers
     */
    public function externalEventId(array $headers, string $raw): ?string;

    /**
     * @param array<string, list<string|null>> $headers
     */
    public function verify(array $headers, string $raw): bool;
}
