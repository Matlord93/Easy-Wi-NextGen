<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Notification;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DiscordWebhookProvider implements BotProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return 'discord';
    }

    public function send(BotMessage $message, BotChannel $channel): void
    {
        if ($channel->endpoint === '') {
            throw new \InvalidArgumentException('Discord webhook endpoint is required.');
        }

        $embed = [
            'title' => $message->title,
            'description' => $message->body,
        ];

        if ($message->url !== null) {
            $embed['url'] = $message->url;
        }

        if ($message->fields !== []) {
            $embed['fields'] = array_map(
                static fn (array $field): array => [
                    'name' => $field['label'],
                    'value' => $field['value'],
                    'inline' => true,
                ],
                $message->fields,
            );
        }

        $color = $this->resolveColor($message->severity);
        if ($color !== null) {
            $embed['color'] = $color;
        }

        $this->httpClient->request('POST', $channel->endpoint, [
            'json' => [
                'embeds' => [$embed],
            ],
        ]);
    }

    private function resolveColor(?string $severity): ?int
    {
        return match ($severity) {
            'info' => 0x3498db,
            'warning' => 0xf1c40f,
            'critical' => 0xe74c3c,
            default => null,
        };
    }
}
