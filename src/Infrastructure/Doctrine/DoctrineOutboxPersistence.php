<?php

declare(strict_types=1);

namespace WebhookLedger\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use WebhookLedger\Domain\Contract\OutboxPersistenceInterface;
use WebhookLedger\Domain\ValueObject\OutboxWorkValueObject;
use WebhookLedger\Domain\ValueObject\WebhookUuid;

final readonly class DoctrineOutboxPersistence implements OutboxPersistenceInterface
{
    private Connection $conn;

    public function __construct(
        EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
        $this->conn = $entityManager->getConnection();
    }

    public function run(callable $work): WebhookUuid
    {
        return $this->conn->transactional(function () use ($work): WebhookUuid {
            $outboxWork = $work();

            if ($outboxWork instanceof OutboxWorkValueObject) {
                $before = $this->messengerMessagesCount();
                $this->bus->dispatch($outboxWork->event);

                if ($this->messengerMessagesCount() <= $before) {
                    throw new \RuntimeException(sprintf('Outbox connection mismatch: dispatching the message for webhook %s did not become visible on the same Doctrine connection as the ledger write. The transport routed for %s must be a Doctrine transport (doctrine://...) pointing at the same connection as the entity manager used by this bundle, or the transactional outbox guarantee does not hold.', $outboxWork->uuid, $outboxWork->event::class));
                }
            }

            return $outboxWork->uuid;
        });
    }

    private function messengerMessagesCount(): int
    {
        try {
            $count = $this->conn->fetchOne('SELECT COUNT(*) FROM messenger_messages');

            return is_numeric($count) ? (int) $count : -1;
        } catch (\Throwable) {
            return -1;
        }
    }
}
