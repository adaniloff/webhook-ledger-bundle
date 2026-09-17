<?php

declare(strict_types=1);

namespace WebhookLedger\Presentation\Controller;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use WebhookLedger\Application\Receiver\Dto\WebhookInputDto;
use WebhookLedger\Application\Receiver\Exception\WebhookEntryDuplicationException;
use WebhookLedger\Application\Receiver\Service\AdapterRegistry;
use WebhookLedger\Application\Receiver\Service\Receiver;

final class WebhookController extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    #[Route(path: '/wl/webhook/{source}', name: 'webhook_hook', methods: ['POST'], format: 'json')]
    public function hook(
        string $source,
        Request $request,
        Receiver $receiver,
        AdapterRegistry $adapters,
    ): Response {
        if (!$adapter = $adapters->get($source)) {
            throw new NotFoundHttpException(sprintf('Unknown webhook source: %s', $source));
        }

        $raw = $request->getContent();
        $dto = new WebhookInputDto(
            external_event_id: (string) $adapter->externalEventId($headers = $request->headers->all(), $raw),
            payload: $raw,
            headers: $headers,
            signature_valid: $withValidSignature = $receiver->sign(adapter: $adapter, headers: $headers, raw: $raw),
        );

        $this->logger?->debug(sprintf('REQUEST BODY <source: %s, payload: %s>', $source, $raw));

        try {
            $uuid = $receiver->capture(adapter: $adapter, dto: $dto);
        } catch (WebhookEntryDuplicationException $e) {
            $uuid = $e->getIdentifier();
            $this->logger?->debug(
                sprintf('Duplication exception: source %s with ext_id %s', $source, $dto->external_event_id),
            );
        }

        if (true !== $withValidSignature) {
            return $this->json(data: ['error' => 'Invalid signature.', 'fields' => []], status: 401);
        }

        if ($failureResponse = $this->validationFailureResponse($dto->violations(), $source)) {
            return $failureResponse;
        }

        return new Response(content: '', headers: ['X-Evt-Id' => $uuid], status: 202);
    }

    /**
     * @param array<string, string> $violations
     */
    private function validationFailureResponse(array $violations, string $source): ?Response
    {
        if ([] === $violations) {
            return null;
        }

        $this->logger?->debug(
            sprintf('Validation error <source: %s, errors: %s>', $source, json_encode($violations)),
        );

        return $this->json(
            data: ['error' => 'Invalid and/or missing fields.', 'fields' => $violations],
            status: 422,
        );
    }
}
