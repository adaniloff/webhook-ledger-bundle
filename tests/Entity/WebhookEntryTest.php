<?php

declare(strict_types=1);

namespace WebhookLedger\Tests\Entity;

use PHPUnit\Framework\TestCase;
use WebhookLedger\Domain\Enum\StatusEnum;
use WebhookLedger\Domain\Exception\WebhookNotReplayableException;
use WebhookLedger\Infrastructure\Doctrine\Entity\WebhookEntry;
use WebhookLedger\Tests\Fixtures\Adapter\FakeSourceAdapter;

final class WebhookEntryTest extends TestCase
{
    public function testReplayTransitionsDeadEntryToReceived(): void
    {
        $entity = $this->deadEntity();

        $entity->replay();

        $this->assertSame(StatusEnum::RECEIVED, $entity->getStatus());
    }

    public function testReplayRefusesWhenStatusIsNotDead(): void
    {
        $entity = $this->deadEntity(status: StatusEnum::FAILED);

        $this->expectException(WebhookNotReplayableException::class);

        $entity->replay();
    }

    public function testReplayRefusesWhenSignatureInvalid(): void
    {
        $entity = $this->deadEntity(signatureValid: false);

        $this->expectException(WebhookNotReplayableException::class);

        $entity->replay();
    }

    private function deadEntity(StatusEnum $status = StatusEnum::DEAD, bool $signatureValid = true): WebhookEntry
    {
        $now = new \DateTimeImmutable();
        $entity = new WebhookEntry();

        $reflection = new \ReflectionClass($entity);
        foreach ([
            'uuid' => '0199a000-0000-7000-8000-000000000001',
            'source' => FakeSourceAdapter::NAME,
            'external_event_id' => 'evt-1',
            'payload' => '{}',
            'headers' => [],
            'signature_valid' => $signatureValid,
            'status' => $status,
            'attempts' => 1,
            'received_at' => $now,
            'updated_at' => $now,
            'version' => 1,
        ] as $property => $value) {
            $reflection->getProperty($property)->setValue($entity, $value);
        }

        return $entity;
    }
}
