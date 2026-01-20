<?php

declare(strict_types=1);

namespace App\Message;

final class SendTemplateMailMessage
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private readonly string $to,
        private readonly string $templateKey,
        private readonly array $context,
        private readonly string $locale,
    ) {
    }

    public function getTo(): string
    {
        return $this->to;
    }

    public function getTemplateKey(): string
    {
        return $this->templateKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }
}
