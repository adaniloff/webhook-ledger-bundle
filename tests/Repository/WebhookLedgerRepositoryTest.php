<?php

declare(strict_types=1);

namespace WebhookLedger\Tests\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WebhookLedger\Application\Receiver\Exception\WebhookEntryDuplicationException;
use WebhookLedger\Application\Receiver\Exception\WebhookOutdatedException;
use WebhookLedger\Domain\Enum\StatusEnum;
use WebhookLedger\Infrastructure\Doctrine\Entity\WebhookEntry;
use WebhookLedger\Infrastructure\Doctrine\Repository\WebhookLedgerRepository;
use WebhookLedger\Tests\Factory\WebhookEntryFactory;
use WebhookLedger\Tests\Fixtures\Adapter\FakeSourceAdapter;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class WebhookLedgerRepositoryTest extends KernelTestCase
{
    private WebhookLedgerRepository $repository;

    public function setUp(): void
    {
        $this->repository = static::getContainer()->get(WebhookLedgerRepository::class);
    }

    public function testReceiveOnceSucceed(): void
    {
        // Act
        WebhookEntryFactory::assert()->empty();
        $this->repository->receive(
            source: FakeSourceAdapter::NAME,
            externalEventId: 'some-external-id',
            payload: '{"id":"some-external-id"}',
            headers: ['content-type' => 'application/json'],
            signatureValid: true,
        );

        // Assert
        WebhookEntryFactory::assert()
            ->count(1)
            ->exists(criteria: [
                'external_event_id' => 'some-external-id',
                'source' => FakeSourceAdapter::NAME,
                'status' => StatusEnum::RECEIVED,
                'attempts' => 0,
                'version' => 1,
                'signature_valid' => true,
                'payload' => '{"id":"some-external-id"}',
            ]);

        $event = WebhookEntryFactory::repository()->findOneBy(['external_event_id' => 'some-external-id']);
        $this->assertSame(['content-type' => 'application/json'], $event->getHeaders());
    }

    public function testReceiveAlreadyExistingThrowsDuplicationException(): void
    {
        // Arrange
        WebhookEntryFactory::assert()->empty();
        $uuid = $this->repository->receive(
            source: FakeSourceAdapter::NAME,
            externalEventId: 'some-external-id',
            payload: '{"id":"some-external-id"}',
            headers: ['content-type' => 'application/json'],
            signatureValid: true,
        );
        WebhookEntryFactory::assert()->count(1);

        try {
            // Act
            $this->repository->receive(
                source: FakeSourceAdapter::NAME,
                externalEventId: 'some-external-id',
                payload: '{"id":"some-external-id"}',
                headers: ['content-type' => 'application/json'],
                signatureValid: true,
            );
        } catch (WebhookEntryDuplicationException $e) {
            // Assert
            $this->assertSame((string) $uuid, $e->getIdentifier());
            WebhookEntryFactory::assert()->count(1);

            return;
        }

        $this->fail('Expected WebhookEntryDuplicationException to be thrown.');
    }

    public function testMarkDispatched(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne([
            'status' => StatusEnum::RECEIVED,
            'attempts' => $attempts = random_int(0, 15),
        ]);

        // Act
        $this->repository->markDispatched(uuid: $webhook->getUuid());

        // Assert
        WebhookEntryFactory::assert()->exists([
            'uuid' => (string) $webhook->getUuid(),
            'status' => StatusEnum::DISPATCHED,
            'attempts' => ++$attempts,
        ]);
    }

    public function testMarkSucceeded(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne([
            'status' => StatusEnum::RECEIVED,
            'attempts' => $attempts = random_int(0, 15),
        ]);

        // Act
        $this->repository->markSucceeded(uuid: $webhook->getUuid());

        // Assert
        WebhookEntryFactory::assert()->exists([
            'uuid' => (string) $webhook->getUuid(),
            'status' => StatusEnum::SUCCEEDED,
            'attempts' => $attempts,
        ]);
    }

    public function testMarkFailed(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne(['status' => StatusEnum::DISPATCHED, 'last_error' => null]);

        // Act
        $this->repository->markFailed(uuid: $webhook->getUuid(), error: $errorMessage = 'boom');

        // Assert
        WebhookEntryFactory::assert()->exists([
            'uuid' => (string) $webhook->getUuid(),
            'status' => StatusEnum::FAILED,
            'last_error' => $errorMessage,
        ]);
    }

    public function testMarkDead(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne(['status' => StatusEnum::DISPATCHED, 'last_error' => null]);

        // Act
        $this->repository->markDead(uuid: $webhook->getUuid(), error: $errorMessage = 'dead-boom');

        // Assert
        WebhookEntryFactory::assert()->exists([
            'uuid' => (string) $webhook->getUuid(),
            'status' => StatusEnum::DEAD,
            'last_error' => $errorMessage,
        ]);
    }

    public function testMarkIsNoopWhenSameStatus(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne([
            'status' => StatusEnum::DISPATCHED,
            'attempts' => $attempts = random_int(0, 13),
        ]);

        // Act
        $this->repository->markDispatched(uuid: $webhook->getUuid());

        // Assert
        WebhookEntryFactory::assert()->exists([
            'uuid' => (string) $webhook->getUuid(),
            'status' => StatusEnum::DISPATCHED,
            'attempts' => $attempts,
        ]);
    }

    public function testMarkDoesNotAffectOptimisticLockVersion(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne(['status' => StatusEnum::RECEIVED]);
        $version = $webhook->getVersion();

        // Act
        $this->repository->markDispatched(uuid: $webhook->getUuid());
        $this->repository->markSucceeded(uuid: $webhook->getUuid());

        // Assert
        WebhookEntryFactory::assert()->exists([
            'uuid' => (string) $webhook->getUuid(),
            'status' => StatusEnum::SUCCEEDED,
            'version' => $version,
        ]);
    }

    public function testSavePersistsAReplayedEntry(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne([
            'status' => StatusEnum::DEAD,
            'signature_valid' => true,
            'version' => $version = 1,
        ]);
        $webhook->replay();

        // Act
        $this->repository->save($webhook, expectedVersion: $version);

        // Assert
        WebhookEntryFactory::assert()->exists([
            'uuid' => (string) $webhook->getUuid(),
            'status' => StatusEnum::RECEIVED,
            'version' => ++$version,
        ]);
    }

    public function testSaveThrowsOutdatedExceptionOnStaleVersion(): void
    {
        // Arrange
        $uuid = WebhookEntryFactory::createOne(['status' => StatusEnum::DEAD, 'signature_valid' => true])->getUuid();

        //
        // Doctrine override version number set through Foundry
        // --> must update or insert through Doctrine directly
        //
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $em->getClassMetadata(WebhookEntry::class);
        $rowCount = $em->getConnection()
            ->executeStatement("UPDATE {$metadata->getTableName()} SET version = 2");
        $this->assertEquals(1, $rowCount);

        // Act
        try {
            $webhook = $em->getRepository(WebhookEntry::class)->findOneBy(['uuid' => (string) $uuid]);
            $webhook->replay();
            $this->repository->save($webhook, expectedVersion: 1);
        } catch (WebhookOutdatedException $e) {
            // Assert
            $this->assertEquals(1, $e->getOutdatedVersion());
            $this->assertEquals((string) $webhook->getUuid(), $e->getIdentifier());
            WebhookEntryFactory::assert()->exists([
                'uuid' => (string) $webhook->getUuid(),
                'status' => StatusEnum::DEAD,
                'version' => 2,
            ]);

            return;
        }

        $this->fail('A WebhookOutdatedException should have been thrown.');
    }
}
