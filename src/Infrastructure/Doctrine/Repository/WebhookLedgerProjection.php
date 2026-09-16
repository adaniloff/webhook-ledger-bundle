<?php

declare(strict_types=1);

namespace WebhookLedger\Infrastructure\Doctrine\Repository;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use WebhookLedger\Domain\Contract\WebhookEntryInterface;
use WebhookLedger\Domain\Contract\WebhookLedgerProjectionInterface;
use WebhookLedger\Domain\Enum\StatusEnum;
use WebhookLedger\Domain\ValueObject\WebhookCriteria;
use WebhookLedger\Infrastructure\Doctrine\Entity\WebhookEntry;

final readonly class WebhookLedgerProjection implements WebhookLedgerProjectionInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    /**
     * @return list<WebhookEntryInterface>
     */
    public function paginate(WebhookCriteria $criteria = new WebhookCriteria()): array
    {
        /** @var list<WebhookEntryInterface> $result */
        $result = $this->queryBuilder()
            ->addCriteria($this->doctrineCriteria($criteria, paginated: true))
            ->orderBy('w.updated_at', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function countBy(WebhookCriteria $criteria = new WebhookCriteria()): int
    {
        return (int) $this->queryBuilder()
            ->addCriteria($this->doctrineCriteria($criteria, paginated: false))
            ->select('COUNT(w.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        /** @var list<array{status: StatusEnum, count: string}> $rows */
        $rows = $this->queryBuilder()
            ->select('w.status AS status', 'COUNT(w.id) AS count')
            ->groupBy('w.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['count'];
        }

        return $counts;
    }

    public function findOneBy(array $criteria, ?array $orderBy = null): ?WebhookEntryInterface
    {
        return $this->em->getRepository(WebhookEntry::class)->findOneBy($criteria, $orderBy);
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->em->getRepository(WebhookEntry::class)->findBy($criteria, $orderBy, $limit, $offset);
    }

    private function doctrineCriteria(WebhookCriteria $criteria, bool $paginated): Criteria
    {
        $doctrineCriteria = Criteria::create();

        if (null !== $criteria->source) {
            $doctrineCriteria->andWhere(Criteria::expr()->eq('source', $criteria->source));
        }

        if (null !== $criteria->status) {
            $doctrineCriteria->andWhere(Criteria::expr()->eq('status', $criteria->status));
        }

        if (true === $paginated && null !== $criteria->page && null !== $criteria->limit) {
            $doctrineCriteria->setFirstResult(($criteria->page - 1) * $criteria->limit);
            $doctrineCriteria->setMaxResults($criteria->limit);
        }

        return $doctrineCriteria;
    }

    private function queryBuilder(): QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('w')->from(WebhookEntry::class, 'w');
    }
}
