<?php

namespace Nlincs\MarketingCloudLaravel;

class Subscriber
{
    public function __construct(
        protected string $subscriberKey,
        protected array $attributes = []
    ) {}

    public static function fromEmail(string $email): self
    {
        return new self(
            subscriberKey: strtolower($email),
            attributes: [
                'Email address' => $email,
            ]
        );
    }

    public static function fromKey(string $subscriberKey, array $attributes = []): self
    {
        return new self($subscriberKey, $attributes);
    }

    public function subscriberKey(): string
    {
        return $this->subscriberKey;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    public function email(): ?string
    {
        return $this->attributes['Email address'] ?? null;
    }
}
