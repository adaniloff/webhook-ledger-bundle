<?php

declare(strict_types=1);

namespace WebhookLedger\Application\Receiver\Dto;

final readonly class WebhookInputDto
{
    /**
     * @param array<string, mixed> $headers
     */
    public function __construct(
        public string $external_event_id,
        public string $payload,
        public array $headers,
        public bool $signature_valid,
    ) {}

    public function isDispatchable(): bool
    {
        return $this->signature_valid && [] === $this->violations();
    }

    /**
     * @return array<string, string>
     */
    public function violations(): array
    {
        $errors = [];
        if ('' === trim($this->external_event_id)) {
            $errors['external_event_id'] = 'This value should not be blank.';
        }
        if ('' === trim($this->payload)) {
            $errors['payload'] = 'This value should not be blank.';
        }

        return $errors;
    }
}
