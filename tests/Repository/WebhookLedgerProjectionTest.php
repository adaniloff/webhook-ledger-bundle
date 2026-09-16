<?php

declare(strict_types=1);

namespace WebhookLedger\Tests\Repository;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WebhookLedger\Domain\Contract\WebhookEntryInterface;
use WebhookLedger\Domain\Enum\StatusEnum;
use WebhookLedger\Domain\ValueObject\WebhookCriteria;
use WebhookLedger\Infrastructure\Doctrine\Entity\WebhookEntry;
use WebhookLedger\Infrastructure\Doctrine\Repository\WebhookLedgerProjection;
use WebhookLedger\Tests\Factory\WebhookEntryFactory;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class WebhookLedgerProjectionTest extends KernelTestCase
{
    private const SOURCE_A = 'fake-a';
    private const SOURCE_B = 'fake-b';

    private WebhookLedgerProjection $projection;

    public function setUp(): void
    {
        $this->projection = static::getContainer()->get(WebhookLedgerProjection::class);
    }

    public function testCountThrough(): void
    {
        // Arrange
        $stripeReceived = WebhookEntryFactory::createOne(['source' => self::SOURCE_A, 'status' => StatusEnum::RECEIVED]);
        WebhookEntryFactory::createOne(['source' => self::SOURCE_A, 'status' => StatusEnum::DEAD]);
        WebhookEntryFactory::createOne(['source' => self::SOURCE_B, 'status' => StatusEnum::RECEIVED]);

        // Act
        // Assert
        $this->assertSame(3, $this->projection->countBy(new WebhookCriteria()));
        $this->assertSame(2, $this->projection->countBy(new WebhookCriteria(source: self::SOURCE_A)));
        $this->assertSame(1, $this->projection->countBy(new WebhookCriteria(source: self::SOURCE_A, status: StatusEnum::RECEIVED)));
    }

    public function testPaginate(): void
    {
        // Arrange
        $stripeReceived = WebhookEntryFactory::createOne(['source' => self::SOURCE_A, 'status' => StatusEnum::RECEIVED]);
        WebhookEntryFactory::createOne(['source' => self::SOURCE_A, 'status' => StatusEnum::DEAD]);
        WebhookEntryFactory::createOne(['source' => self::SOURCE_B, 'status' => StatusEnum::RECEIVED]);

        // Act
        // Assert
        $this->assertCount(3, $this->projection->paginate(new WebhookCriteria(page: 1, limit: 10)));
        $this->assertCount(2, $this->projection->paginate(new WebhookCriteria(source: self::SOURCE_A, page: 1, limit: 10)));

        $result = $this->projection->paginate(new WebhookCriteria(source: self::SOURCE_A, status: StatusEnum::RECEIVED, page: 1, limit: 10));
        $this->assertCount(1, $result);
        $this->assertSame((string) $stripeReceived->getUuid(), (string) $result[0]->getUuid());
    }

    public function testPaginateOnMultiplePages(): void
    {
        // Arrange
        /** @var list<WebhookEntry> $webhooks */
        $webhooks = WebhookEntryFactory::createMany(number: 25);
        usort($webhooks, fn($a, $b) => $b->getUpdatedAt() <=> $a->getUpdatedAt() ?: $b->getId() <=> $a->getId());
        $expectedFirstPage = array_slice($webhooks, 0, 20);
        $expectedSecondPage = array_slice($webhooks, 20, 5);

        // Act
        $firstPage = $this->projection->paginate(new WebhookCriteria(page: 1, limit: 20));
        $secondPage = $this->projection->paginate(new WebhookCriteria(page: 2, limit: 20));

        // Assert
        $this->assertCount(20, $firstPage);
        $this->assertCount(5, $secondPage);
        $this->assertSame(
            array_map(static fn(WebhookEntry $w) => (string) $w->getUuid(), $expectedFirstPage),
            array_map(static fn(WebhookEntryInterface $w) => (string) $w->getUuid(), $firstPage),
        );
        $this->assertSame(
            array_map(static fn(WebhookEntry $w) => (string) $w->getUuid(), $expectedSecondPage),
            array_map(static fn(WebhookEntryInterface $w) => (string) $w->getUuid(), $secondPage),
        );
    }

    public function testPaginateWithCollisionKeepsSameOrder(): void
    {
        // Arrange
        $sameInstant = new \DateTimeImmutable('2024-01-01 12:00:00');
        $webhooks = [];
        for ($i = 0; $i < 5; ++$i) {
            $webhooks[] = WebhookEntryFactory::createOne(['updated_at' => $sameInstant]);
        }
        $expected = array_reverse($webhooks);

        // Act
        $firstPage = $this->projection->paginate(new WebhookCriteria(page: 1, limit: 3));
        $secondPage = $this->projection->paginate(new WebhookCriteria(page: 2, limit: 3));

        // Assert
        $this->assertSame(
            array_map(static fn(WebhookEntry $w) => (string) $w->getUuid(), array_slice($expected, 0, 3)),
            array_map(static fn(WebhookEntryInterface $w) => (string) $w->getUuid(), $firstPage),
        );
        $this->assertSame(
            array_map(static fn(WebhookEntry $w) => (string) $w->getUuid(), array_slice($expected, 3, 2)),
            array_map(static fn(WebhookEntryInterface $w) => (string) $w->getUuid(), $secondPage),
        );
    }
}
