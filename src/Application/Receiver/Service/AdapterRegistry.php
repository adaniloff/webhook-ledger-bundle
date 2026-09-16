<?php

declare(strict_types=1);

namespace WebhookLedger\Application\Receiver\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use WebhookLedger\Domain\Contract\SourceAdapterInterface;

final class AdapterRegistry
{
    /** @var array<string, SourceAdapterInterface> */
    private readonly array $adapters;

    /**
     * @param iterable<SourceAdapterInterface> $adapters
     */
    public function __construct(
        #[AutowireIterator('webhook_ledger.source_adapter')]
        iterable $adapters,
    ) {
        $indexed = [];
        foreach ($adapters as $adapter) {
            $indexed[$adapter->getName()] = $adapter;
        }
        $this->adapters = $indexed;
    }

    public function get(string $name): ?SourceAdapterInterface
    {
        return $this->adapters[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->adapters);
    }
}
