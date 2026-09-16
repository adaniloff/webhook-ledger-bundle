<?php

declare(strict_types=1);

namespace WebhookLedger\Domain\ValueObject;

use Symfony\Component\Uid\Uuid;

final readonly class WebhookUuid implements \Stringable
{
    private function __construct(private string $value) {}

    public static function generate(): self
    {
        return new self((string) Uuid::v7());
    }

    public static function fromString(string $value): self
    {
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid webhook uuid.', $value));
        }

        return new self((string) Uuid::fromString($value));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
