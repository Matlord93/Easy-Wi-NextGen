<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Raw SQL and SSE bytes must never be interpreted as HTML or HTML attributes. */
final class TypedStreamResponse extends StreamedResponse
{
    private const TYPES = ['application/sql', 'text/event-stream'];

    /** @param \Closure(\Closure(string): void): void $produce */
    public function __construct(string $type, \Closure $produce)
    {
        self::checkType($type);
        parent::__construct(static function () use ($produce): void {
            $produce(static function (string $bytes): void {
                self::write($bytes);
            });
        }, 200, ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff']);
    }

    #[\Override]
    public function sendContent(): static
    {
        self::checkType($this->headers->get('Content-Type', ''));
        if ('nosniff' !== $this->headers->get('X-Content-Type-Options')) {
            throw new \LogicException('Typed streams require nosniff.');
        }

        return parent::sendContent();
    }

    private static function checkType(string $type): void
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $type) || !in_array(strtolower(trim(explode(';', $type)[0])), self::TYPES, true)) {
            throw new \InvalidArgumentException('Only SQL downloads and event streams are permitted.');
        }
    }

    private static function write(string $bytes): void
    {
        $output = fopen('php://output', 'wb');
        if (false === $output) {
            throw new \RuntimeException('Cannot open response output.');
        }
        try {
            while ('' !== $bytes) {
                // This private sink is reachable only through the MIME-checked,
                // nosniff response above. SQL/SSE quotes are format syntax;
                // HTML-escaping them would corrupt downloads and console data.
                /**
                 * @psalm-suppress TaintedHtml
                 * @psalm-suppress TaintedTextWithQuotes
                 */
                $written = fwrite($output, $bytes);
                if (false === $written || 0 === $written) {
                    throw new \RuntimeException('Cannot write response output.');
                }
                $bytes = substr($bytes, $written);
            }
        } finally {
            fclose($output);
        }
    }
}
