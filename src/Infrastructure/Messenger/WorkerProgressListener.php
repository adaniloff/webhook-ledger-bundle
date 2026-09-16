<?php

declare(strict_types=1);

namespace WebhookLedger\Infrastructure\Messenger;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use WebhookLedger\Application\Worker\Service\WorkerProgressTracker;
use WebhookLedger\Domain\ValueObject\WebhookUuid;

final readonly class WorkerProgressListener
{
    public function __construct(private WorkerProgressTracker $tracker) {}

    #[AsEventListener]
    public function onDispatched(WorkerMessageReceivedEvent $event): void
    {
        /* @phpstan-ignore-next-line */
        $this->tracker->markDispatched(uuid: WebhookUuid::fromString($event->getEnvelope()->getMessage()->uuid));
    }

    #[AsEventListener]
    public function onSucceeded(WorkerMessageHandledEvent $event): void
    {
        /* @phpstan-ignore-next-line */
        $this->tracker->markSucceeded(uuid: WebhookUuid::fromString($event->getEnvelope()->getMessage()->uuid));
    }

    #[AsEventListener]
    public function onFailure(WorkerMessageFailedEvent $event): void
    {
        if (!$event->willRetry()) {
            return;
        }
        $this->tracker->markFailed(
            /* @phpstan-ignore-next-line */
            uuid: WebhookUuid::fromString($event->getEnvelope()->getMessage()->uuid),
            error: $event->getThrowable()->__toString(),
        );
    }

    #[AsEventListener]
    public function onDead(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }
        $this->tracker->markDead(
            /* @phpstan-ignore-next-line */
            uuid: WebhookUuid::fromString($event->getEnvelope()->getMessage()->uuid),
            error: $event->getThrowable()->__toString(),
        );
    }
}
