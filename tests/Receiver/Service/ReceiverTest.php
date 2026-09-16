<?php

declare(strict_types=1);

namespace WebhookLedger\Tests\Receiver\Service;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use WebhookLedger\Application\Receiver\Dto\WebhookInputDto;
use WebhookLedger\Application\Receiver\Exception\WebhookEntryDuplicationException;
use WebhookLedger\Application\Receiver\Exception\WebhookNotFoundException;
use WebhookLedger\Application\Receiver\Exception\WebhookOutdatedException;
use WebhookLedger\Application\Receiver\Service\Receiver;
use WebhookLedger\Domain\Enum\StatusEnum;
use WebhookLedger\Domain\Exception\WebhookNotReplayableException;
use WebhookLedger\Domain\ValueObject\WebhookUuid;
use WebhookLedger\Tests\Factory\WebhookEntryFactory;
use WebhookLedger\Tests\Fixtures\Adapter\FakeSourceAdapter;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class ReceiverTest extends KernelTestCase
{
    private FakeSourceAdapter $adapter;

    public function setUp(): void
    {
        $this->adapter = new FakeSourceAdapter();
    }

    public function testHappyCapturePath(): void
    {
        // Arrange
        $container = self::getContainer();
        WebhookEntryFactory::assert()->count(0);

        // Act
        $uuid = $container->get(Receiver::class)->capture($this->adapter, new WebhookInputDto(
            external_event_id: 'some-id',
            payload: '{"some-payload": false}',
            headers: [],
            signature_valid: true,
        ));

        // Assert
        WebhookEntryFactory::assert()
                ->count(1)
                ->exists(['uuid' => (string) $uuid]);
        $this->assertSame(1, $this->countMessengerMessages());
    }

    public function testCaptureWithInvalidPayloadDoesNotDispatch(): void
    {
        // Arrange
        $container = self::getContainer();
        WebhookEntryFactory::assert()->count(0);

        // Act
        $uuid = $container->get(Receiver::class)->capture($this->adapter, new WebhookInputDto(
            external_event_id: 'some-id',
            payload: '',
            headers: [],
            signature_valid: true,
        ));

        // Assert
        WebhookEntryFactory::assert()
                ->count(1)
                ->exists(['uuid' => (string) $uuid]);
        $this->assertSame(0, $this->countMessengerMessages());
    }

    public function testBrokenCapturePathAtomicity(): void
    {
        // Arrange
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException());

        $container = self::getContainer();
        $container->set(MessageBusInterface::class, $bus);

        WebhookEntryFactory::assert()->count(0);

        // Act
        try {
            $container->get(Receiver::class)->capture($this->adapter, new WebhookInputDto(
                external_event_id: 'some-id',
                payload: '{"some-payload": false}',
                headers: [],
                signature_valid: true,
            ));
        } catch (\Throwable) {
            // Assert
            WebhookEntryFactory::assert()->count(0);

            return;
        }

        $this->fail('An exception should have been thrown.');
    }

    public function testCaptureOnDuplicateDoesNotDispatch(): void
    {
        // Arrange
        WebhookEntryFactory::assert()->count(0);
        $container = self::getContainer();
        $uuid = $container->get(Receiver::class)->capture($this->adapter, $dto = new WebhookInputDto(
            external_event_id: 'some-id',
            payload: '{"some-payload": false}',
            headers: [],
            signature_valid: true,
        ));

        // Act
        try {
            $container->get(Receiver::class)->capture($this->adapter, $dto);
        } catch (WebhookEntryDuplicationException $e) {
            // Assert
            $this->assertEquals((string) $uuid, $e->getIdentifier());
            WebhookEntryFactory::assert()
                ->count(1)
                ->exists(['uuid' => (string) $uuid]);
            $this->assertSame(1, $this->countMessengerMessages());

            return;
        }

        $this->fail('A WebhookEntryDuplicationException should have been thrown.');
    }

    public function testHappyReplayPath(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne([
            'status' => StatusEnum::DEAD,
            'signature_valid' => true,
            'version' => 1,
        ]);

        // Act
        self::getContainer()->get(Receiver::class)->replay(uuid: $webhook->getUuid(), version: 1);

        // Assert
        WebhookEntryFactory::assert()->exists([
            'uuid' => (string) $webhook->getUuid(),
            'status' => StatusEnum::RECEIVED,
            'version' => 2,
        ]);
        $this->assertSame(1, $this->countMessengerMessages());
    }

    public function testReplayThrowsNotFound(): void
    {
        $this->expectException(WebhookNotFoundException::class);
        self::getContainer()->get(Receiver::class)->replay(uuid: WebhookUuid::fromString((string) Uuid::v7()), version: 1);
    }

    public function testReplayThrowsNotReplayableWhenStatusIsInvalid(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne([
            'status' => StatusEnum::FAILED,
            'signature_valid' => true,
            'version' => 1,
        ]);

        // Assert
        $this->expectException(WebhookNotReplayableException::class);

        // Act
        try {
            self::getContainer()->get(Receiver::class)->replay(uuid: $webhook->getUuid(), version: 1);
        } finally {
            WebhookEntryFactory::assert()->exists([
                'uuid' => (string) $webhook->getUuid(),
                'status' => StatusEnum::FAILED,
                'version' => 1,
            ]);
            $this->assertSame(0, $this->countMessengerMessages());
        }
    }

    public function testReplayThrowsNotReplayableWhenSignatureInvalid(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne([
            'status' => StatusEnum::DEAD,
            'signature_valid' => false,
            'version' => 1,
        ]);

        // Assert
        $this->expectException(WebhookNotReplayableException::class);

        // Act
        try {
            self::getContainer()->get(Receiver::class)->replay(uuid: $webhook->getUuid(), version: 1);
        } finally {
            WebhookEntryFactory::assert()->exists([
                'uuid' => (string) $webhook->getUuid(),
                'status' => StatusEnum::DEAD,
                'version' => 1,
            ]);
            $this->assertSame(0, $this->countMessengerMessages());
        }
    }

    public function testReplayThrowsOutdatedOnStaleVersion(): void
    {
        // Arrange
        $webhook = WebhookEntryFactory::createOne([
            'status' => StatusEnum::DEAD,
            'signature_valid' => true,
            'version' => 1,
        ]);

        // Assert
        $this->expectException(WebhookOutdatedException::class);

        // Act
        try {
            self::getContainer()->get(Receiver::class)->replay(uuid: $webhook->getUuid(), version: 999);
        } finally {
            WebhookEntryFactory::assert()->exists([
                'uuid' => (string) $webhook->getUuid(),
                'status' => StatusEnum::DEAD,
                'version' => 1,
            ]);
            $this->assertSame(0, $this->countMessengerMessages());
        }
    }

    private function countMessengerMessages(): int
    {
        return (int) self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT COUNT(*) FROM messenger_messages');
    }
}
