<?php

declare(strict_types=1);

namespace WebhookLedger\Domain\Contract;

use WebhookLedger\Domain\ValueObject\WebhookCriteria;

interface WebhookLedgerProjectionInterface
{
    /**
     * @return array<string, int>
     */
    public function countByStatus(): array;

    /**
     * @return list<WebhookEntryInterface>
     */
    public function paginate(WebhookCriteria $criteria = new WebhookCriteria()): array;

    public function countBy(WebhookCriteria $criteria = new WebhookCriteria()): int;

    /**
     * @param array<string, mixed>                          $criteria
     * @param array<string, 'ASC'|'asc'|'DESC'|'desc'>|null $orderBy
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?WebhookEntryInterface;

    /**
     * @param array<string, mixed>                          $criteria
     * @param array<string, 'ASC'|'asc'|'DESC'|'desc'>|null $orderBy
     *
     * @return list<WebhookEntryInterface>
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array;
}
