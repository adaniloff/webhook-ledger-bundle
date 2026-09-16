<?php

declare(strict_types=1);

namespace WebhookLedger\Tests\Worker\Listener;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use WebhookLedger\Application\Worker\Message\ProcessWebhookEvent;
use WebhookLedger\Domain\Enum\StatusEnum;
use WebhookLedger\Infrastructure\Messenger\WorkerProgressListener;
use WebhookLedger\Tests\Factory\WebhookEntryFactory;
use Zenstruck\Foundry\Attribute\ResetDatabase;

use function Zenstruck\Foundry\Persistence\refresh;

#[ResetDatabase]
final class WorkerProgressListenerTest extends KernelTestCase
{
    private WorkerProgressListener $listener;

    public function setUp(): void
    {
        $this->listener = self::getContainer()->get(WorkerProgressListener::class);
    }

    public function testOnDispatched(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne(['status' => StatusEnum::RECEIVED, 'attempts' => 0]);
        $event = new WorkerMessageReceivedEvent($this->envelope((string) $webhook->getUuid()), 'async');

        // Act
        $this->listener->onDispatched($event);

        // Assert
        WebhookEntryFactory::assert()->exists([
            'uuid' => (string) $webhook->getUuid(),
            'status' => StatusEnum::DISPATCHED,
            'attempts' => 1,
        ]);
    }

    public function testOnSucceeded(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne(['status' => StatusEnum::DISPATCHED, 'attempts' => 1]);
        $event = new WorkerMessageHandledEvent($this->envelope((string) $webhook->getUuid()), 'async');

        // Act
        $this->listener->onSucceeded($event);

        // Assert
        WebhookEntryFactory::assert()->exists([
            'uuid' => (string) $webhook->getUuid(),
            'status' => StatusEnum::SUCCEEDED,
            'attempts' => 1,
        ]);
    }

    public function testOnFailureWhenWillRetry(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne(['status' => StatusEnum::DISPATCHED, 'last_error' => null]);
        $event = new WorkerMessageFailedEvent(
            $this->envelope((string) $webhook->getUuid()),
            'async',
            new \RuntimeException($expectedException = 'boom'),
        );
        $event->setForRetry();

        // Act
        $this->listener->onFailure($event);

        // Assert
        $entity = WebhookEntryFactory::find(['uuid' => (string) $webhook->getUuid()]);
        refresh($entity);
        $this->assertSame(StatusEnum::FAILED, $entity->getStatus());
        $this->assertStringContainsString($expectedException, (string) $entity->getLastError());
    }

    public function testOnFailureDoesNothingWhenWillNotRetry(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne(['status' => StatusEnum::DISPATCHED, 'last_error' => null]);
        $event = new WorkerMessageFailedEvent($this->envelope((string) $webhook->getUuid()), 'async', new \RuntimeException());

        // Act
        $this->listener->onFailure($event);

        // Assert
        WebhookEntryFactory::assert()->exists([
            'uuid' => (string) $webhook->getUuid(),
            'status' => StatusEnum::DISPATCHED,
            'last_error' => null,
        ]);
    }

    public function testOnDeadWhenWillRetry(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne(['status' => StatusEnum::DISPATCHED, 'last_error' => null]);
        $event = new WorkerMessageFailedEvent(
            $this->envelope((string) $webhook->getUuid()),
            'async',
            new \RuntimeException($expectedException = 'dead'),
        );

        // Act
        $this->listener->onDead($event);

        // Assert
        $entity = WebhookEntryFactory::find(['uuid' => (string) $webhook->getUuid()]);
        refresh($entity);
        $this->assertSame(StatusEnum::DEAD, $entity->getStatus());
        $this->assertStringContainsString($expectedException, (string) $entity->getLastError());
    }

    public function testOnDeadDoesNothingWhenWillRetry(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne(['status' => StatusEnum::DISPATCHED, 'last_error' => null]);
        $event = new WorkerMessageFailedEvent($this->envelope((string) $webhook->getUuid()), 'async', new \RuntimeException());
        $event->setForRetry();

        // Act
        $this->listener->onDead($event);

        // Assert
        WebhookEntryFactory::assert()->exists([
            'uuid' => (string) $webhook->getUuid(),
            'status' => StatusEnum::DISPATCHED,
            'last_error' => null,
        ]);
    }

    private function envelope(string $uuid): Envelope
    {
        return new Envelope(new ProcessWebhookEvent(uuid: $uuid));
    }
}
