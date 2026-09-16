<?php

declare(strict_types=1);

namespace WebhookLedger\Tests\Factory;

use Symfony\Component\Uid\Uuid;
use WebhookLedger\Domain\Enum\StatusEnum;
use WebhookLedger\Infrastructure\Doctrine\Entity\WebhookEntry;
use WebhookLedger\Tests\Fixtures\Adapter\FakeSourceAdapter;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<WebhookEntry>
 */
final class WebhookEntryFactory extends PersistentObjectFactory
{
    public function __construct() {}

    public static function class(): string
    {
        return WebhookEntry::class;
    }

    protected function defaults(): array|callable
    {
        $headers = [
            'content-type' => 'application/json',
            'x-number' => 'AC347D212341XR',
        ];

        $receivedAt = self::faker()->dateTime();

        return [
            'attempts' => self::faker()->randomNumber(),
            'external_event_id' => self::faker()->text(255),
            'headers' => $headers,
            'payload' => self::faker()->text(),
            'received_at' => \DateTimeImmutable::createFromMutable($receivedAt),
            'signature_valid' => self::faker()->boolean(),
            'source' => FakeSourceAdapter::NAME,
            'status' => self::faker()->randomElement(StatusEnum::cases()),
            'updated_at' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween($receivedAt)),
            'uuid' => (string) Uuid::v7(),
            'version' => self::faker()->randomNumber(),
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::withoutConstructor()->alwaysForce());
    }
}
