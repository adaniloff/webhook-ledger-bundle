<?php

declare(strict_types=1);

namespace WebhookLedger\Infrastructure\Doctrine\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use WebhookLedger\Application\Receiver\Exception\WebhookEntryDuplicationException;
use WebhookLedger\Application\Receiver\Exception\WebhookOutdatedException;
use WebhookLedger\Domain\Contract\WebhookEntryInterface;
use WebhookLedger\Domain\Contract\WebhookLedgerRepositoryInterface;
use WebhookLedger\Domain\Enum\StatusEnum;
use WebhookLedger\Domain\ValueObject\WebhookUuid;
use WebhookLedger\Infrastructure\Doctrine\Entity\WebhookEntry;

/**
 * @extends ServiceEntityRepository<WebhookEntry>
 */
final class WebhookLedgerRepository extends ServiceEntityRepository implements WebhookLedgerRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookEntry::class);
    }

    public function receive(
        string $source,
        string $externalEventId,
        string $payload,
        array $headers,
        bool $signatureValid,
        int $attempts = 0,
        int $version = 1,
    ): WebhookUuid {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $em = $this->getEntityManager();
        $metadata = $em->getClassMetadata(WebhookEntry::class);
        $table = $metadata->getTableName();

        $metaId = $metadata->getColumnName('id');
        $metaUuid = $metadata->getColumnName('uuid');
        $metaSource = $metadata->getColumnName('source');
        $metaExternalEventId = $metadata->getColumnName('external_event_id');
        $metaPayload = $metadata->getColumnName('payload');
        $metaHeaders = $metadata->getColumnName('headers');
        $metaSignatureValid = $metadata->getColumnName('signature_valid');
        $metaStatus = $metadata->getColumnName('status');
        $metaAttempts = $metadata->getColumnName('attempts');
        $metaReceivedAt = $metadata->getColumnName('received_at');
        $metaUpdatedAt = $metadata->getColumnName('updated_at');
        $metaVersion = $metadata->getColumnName('version');

        $query = "
            INSERT INTO $table (
            $metaId,
            $metaUuid,
            $metaSource,
            $metaExternalEventId,
            $metaPayload,
            $metaHeaders,
            $metaSignatureValid,
            $metaStatus,
            $metaAttempts,
            $metaReceivedAt,
            $metaUpdatedAt,
            $metaVersion
            ) VALUES (null, ?,?,?,?,?,?,?,?,?,?,?)
        ";

        $uuid = WebhookUuid::generate();

        try {
            $em->getConnection()->executeStatement($query, [
                (string) $uuid,
                $source,
                $externalEventId,
                $payload,
                json_encode($headers),
                $signatureValid ? '1' : '0',
                StatusEnum::RECEIVED->value,
                $attempts,
                $now,
                $now,
                $version,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            /** @var string $existingUuid */
            $existingUuid = $em->getConnection()
                ->executeQuery("SELECT $metaUuid FROM $table WHERE source = ? AND external_event_id = ?", [
                    $source,
                    $externalEventId,
                ])->fetchOne();
            throw new WebhookEntryDuplicationException(uuid: WebhookUuid::fromString($existingUuid), previous: $e);
        }

        return $uuid;
    }

    public function save(WebhookEntryInterface $entry, int $expectedVersion): void
    {
        try {
            $this->getEntityManager()->lock($entry, LockMode::OPTIMISTIC, $expectedVersion);
            $this->getEntityManager()->flush();
        } catch (OptimisticLockException $e) {
            throw new WebhookOutdatedException(uuid: $entry->getUuid(), outdatedVersion: $expectedVersion, previous: $e);
        }
    }

    public function markDispatched(WebhookUuid $uuid): void
    {
        $this->mark(uuid: $uuid, status: StatusEnum::DISPATCHED, incrementAttempts: true);
    }

    public function markSucceeded(WebhookUuid $uuid): void
    {
        $this->mark(uuid: $uuid, status: StatusEnum::SUCCEEDED);
    }

    public function markFailed(WebhookUuid $uuid, string $error): void
    {
        $this->mark(uuid: $uuid, status: StatusEnum::FAILED, error: $error);
    }

    public function markDead(WebhookUuid $uuid, string $error): void
    {
        $this->mark(uuid: $uuid, status: StatusEnum::DEAD, error: $error);
    }

    private function mark(
        WebhookUuid $uuid,
        StatusEnum $status,
        bool $incrementAttempts = false,
        ?string $error = null,
    ): void {
        $em = $this->getEntityManager();
        $metadata = $em->getClassMetadata(WebhookEntry::class);
        $table = $metadata->getTableName();

        $metaUuid = $metadata->getColumnName('uuid');
        $count = (int) $incrementAttempts;

        $em->getConnection()->executeStatement(
            "UPDATE $table SET status = :status,
               attempts = attempts + $count,
               updated_at = :now,
               last_error = :last_error
             WHERE $metaUuid = :uuid AND status != :status",
            [
                'uuid' => (string) $uuid,
                'status' => $status->value,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'last_error' => $error,
            ],
        );
    }
}
