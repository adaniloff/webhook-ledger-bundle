# Webhook Ledger Bundle

Idempotent webhook reception with transactional outbox, retries and replay, built on Doctrine and Symfony Messenger.

## Requirements

- PHP >= 8.2
- Symfony ^7.0 || ^8.0
- Doctrine ORM ^3.0

## Installation

```bash
composer require webhook-ledger/webhook-ledger-bundle
```

You need to register the bundle yourself in `config/bundles.php`:

```php
WebhookLedger\WebhookLedgerBundle::class => ['all' => true],
```

It then exposes:

- a `POST /wl/webhook/{source}` route (webhook reception);
- a `bin/console webhook-ledger:replay {uuid} {version}` console command (manual replay).

This bundle provides an application service `Receiver::replay()`, which you can call 
if you want to use the *replay* feature your own way.

*Note that you can easily reuse the command behavior with 
[Symfony's command in controller](https://symfony.com/doc/current/console/command_in_controller.html)*.

### Declaring a webhook provider

Each provider (Stripe, GitHub, ...) must implement `Domain\Contract\SourceAdapterInterface` and be registered by the container as a service.

The bundle's auto-configuration automatically tags it as a `webhook_ledger.source_adapter`:

```php
final class StripeSourceAdapter implements SourceAdapterInterface
{
    // your code :-)
}
```

### Doctrine mapping

The `Infrastructure\Doctrine\Entity\WebhookEntry` entity must be added to the project's Doctrine mapping (`config/packages/doctrine.yaml`), and the Messenger transport used for `Application\Worker\Message\ProcessWebhookEvent` **must** be a Doctrine transport (`doctrine://...`) **pointing to the same connection** as the EntityManager.

Be aware that **this is what guarantees the transactional outbox**!

### Migration

The Doctrine transport **must** run with `auto_setup=0` (see `MESSENGER_TRANSPORT_DSN` and the `failed` transport in `config/packages/messenger.yaml`).

-> letting Messenger create the `messenger_messages` table on its own (default behavior, on the first dispatched message) means an implicit DDL statement **could break the atomicity** guarantee made by this package (depending on your DB engine).

The `messenger_messages` table must be created upfront, through a regular migration, **before** the endpoint receives its first webhook. 

Below is a migration that I used for [my live instance demo project](https://github.com/adaniloff/webhook-ledger):

```php
final class Version20260907204611 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generate the transports tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<EOF
            CREATE TABLE messenger_messages (
            id BIGINT AUTO_INCREMENT NOT NULL,
            body LONGTEXT NOT NULL,
            headers LONGTEXT NOT NULL,
            queue_name VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL,
            available_at DATETIME NOT NULL,
            delivered_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
EOF);
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
    }
}
```

## Guarantees

- **Idempotence**: unique constraint `(source, external_event_id)`, a violation triggers a `WebhookEntryDuplicationException`.
- **Transactional outbox**: receiving a webhook generates both a `webhook_entry` and a `messenger_messages` row in an **atomic transaction**; they both succeed or fail. Then the combo `Symfony Messenger` + Doctrine Messenger transport acts as the relay.
- **Controlled replay**: a webhook is only replayable if it is `DEAD` and its signature was valid (see `WebhookEntry::isReplayable()`), with optimistic-locking (a `WebhookOutdatedException` is thrown on a stale version).

## Decisions

- **Raw DBAL `INSERT` for the ledger write (not the ORM).** Catching
`UniqueConstraintViolationException` through Doctrine's `EntityManager` closes it, which forces
rebuilding it mid-HTTP-request just to keep going. A simple DBAL `INSERT`, catch the violation, 
respond `202` either way. Deduplication is enforced by a unique index on `(source, external_event_id)`.

- **No home-grown poller reading the ledger.** The default design is often a worker doing
`SELECT ... WHERE status='received' ... FOR UPDATE SKIP LOCKED`. What's built here: `Receiver`
writes the ledger row *and* dispatches the `ProcessWebhookEvent` message **in the same DBAL
transaction**. 

    Symfony's Doctrine Messenger transport (`messenger_messages`) plays the role of the
    outbox - it already has its own `SKIP LOCKED`-style concurrent consumption, retry strategy and
    `failure_transport`, so there was no reason to hand-roll it.

- **Replay is restricted to `dead`, not `failed`.** A `failed` webhook already has an automatic
retry scheduled by Symfony's Messenger component. Only `dead` - retries exhausted - is safe to replay.

- **Optimistic locking on replay.** The `version` column (Doctrine `#[ORM\Version]`) guards the
replay path: two simultaneous replay clicks on the same event, one succeeds, the other gets an
`OptimisticLockException` translated into a clear rejection (`WebhookOutdatedException`) rather
than a second dispatch.

## Architecture

The bundle follows a strict hexagonal architecture, enforced by `deptrac`:

```
Domain/           # contracts, value objects, enums
Application/      # orchestration (Receiver, Worker)
Infrastructure/   # Doctrine, Messenger
Presentation/     # HTTP controller, console command
```

The business handler (what happens when the webhook is processed) is up to you to implement.

**Special warning:** since the *Transactional Outbox* pattern is an `at-least-once` strategy, your business handler **SHOULD BE idempotent**, otherwise you will end up with unexpected behavior.

See this article for more information:
- [french version](https://adaniloff.dev/fr/articles/webhooks-5xx/)
- [english version](https://adaniloff.dev/en/articles/webhooks-5xx/)
