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
                $this->bus->dispatch($outboxWork->event);
            }

            return $outboxWork->uuid;
        });
    }
}
