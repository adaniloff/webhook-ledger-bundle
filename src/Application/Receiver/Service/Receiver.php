<?php

declare(strict_types=1);

namespace WebhookLedger\Application\Receiver\Service;

use WebhookLedger\Application\Receiver\Dto\WebhookInputDto;
use WebhookLedger\Application\Receiver\Exception\WebhookNotFoundException;
use WebhookLedger\Application\Worker\Message\ProcessWebhookEvent;
use WebhookLedger\Domain\Contract\OutboxPersistenceInterface;
use WebhookLedger\Domain\Contract\SourceAdapterInterface;
use WebhookLedger\Domain\Contract\WebhookLedgerProjectionInterface;
use WebhookLedger\Domain\Contract\WebhookLedgerRepositoryInterface;
use WebhookLedger\Domain\ValueObject\NullOutboxWorkValueObject;
use WebhookLedger\Domain\ValueObject\OutboxWorkValueObject;
use WebhookLedger\Domain\ValueObject\WebhookUuid;

final readonly class Receiver
{
    public function __construct(
        private OutboxPersistenceInterface $outbox,
        private WebhookLedgerRepositoryInterface $writer,
        private WebhookLedgerProjectionInterface $reader,
    ) {}

    /**
     * @param array<string, list<string|null>> $headers
     */
    public function sign(SourceAdapterInterface $adapter, array $headers, string $raw): bool
    {
        return $adapter->verify(headers: $headers, raw: $raw);
    }

    public function capture(SourceAdapterInterface $adapter, WebhookInputDto $dto): WebhookUuid
    {
        return $this->outbox->run(function () use ($adapter, $dto): OutboxWorkValueObject|NullOutboxWorkValueObject {
            $uuid = $this->writer->receive(
                source: $adapter->getName(),
                externalEventId: $dto->external_event_id,
                payload: $dto->payload,
                headers: $dto->headers,
                signatureValid: $dto->signature_valid,
            );

            if (!$dto->isDispatchable()) {
                return new NullOutboxWorkValueObject(uuid: $uuid);
            }

            return new OutboxWorkValueObject(uuid: $uuid, event: new ProcessWebhookEvent(uuid: (string) $uuid));
        });
    }

    public function replay(WebhookUuid $uuid, int $version): void
    {
        $this->outbox->run(function () use ($uuid, $version): OutboxWorkValueObject {
            $entry = $this->reader->findOneBy(['uuid' => (string) $uuid])
                ?? throw new WebhookNotFoundException(uuid: $uuid);

            $entry->replay();
            $this->writer->save($entry, expectedVersion: $version);

            return new OutboxWorkValueObject(uuid: $uuid, event: new ProcessWebhookEvent(uuid: (string) $uuid));
        });
    }
}
