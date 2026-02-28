<?php

declare(strict_types=1);

namespace App\Extension\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class BbcodeTwigExtension extends AbstractExtension
{
    private const MAX_INPUT_LENGTH = 50000;

    /** @var string[] */
    private array $urlHostAllowlist;

    /**
     * @param string[] $urlHostAllowlist
     */
    public function __construct(array $urlHostAllowlist = [])
    {
        $this->urlHostAllowlist = array_values(array_filter(array_map(
            static fn (string $host): string => mb_strtolower(trim($host)),
            $urlHostAllowlist,
        )));
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('bbcode', [$this, 'toHtml'], ['is_safe' => ['html']]),
        ];
    }

    public function toHtml(?string $input): string
    {
        if ($input === null || $input === '') {
            return '';
        }

        $input = mb_substr($input, 0, self::MAX_INPUT_LENGTH);

        $text = htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $segments = $this->splitCodeSegments($text);
        $result = '';

        foreach ($segments as $segment) {
            if ($segment['is_code']) {
                $result .= sprintf('<pre><code>%s</code></pre>', $segment['text']);

                continue;
            }

            $chunk = $this->replaceSimpleTags($segment['text']);
            $chunk = $this->replaceUrlTags($chunk);
            $result .= nl2br($chunk, false);
        }

        return $result;
    }

    private function replaceSimpleTags(string $text): string
    {
        $map = [
            'b' => 'strong',
            'i' => 'em',
            'u' => 'u',
            's' => 's',
            'quote' => 'blockquote',
        ];

        foreach ($map as $bbTag => $htmlTag) {
            $text = $this->replacePairedTag($text, $bbTag, sprintf('<%s>', $htmlTag), sprintf('</%s>', $htmlTag));
        }

        return preg_replace('/\[br\]/i', '<br>', $text) ?? $text;
    }

    private function replacePairedTag(string $text, string $tag, string $openHtml, string $closeHtml): string
    {
        $openTag = '['.$tag.']';
        $closeTag = '[/'.$tag.']';
        $offset = 0;

        while (true) {
            $start = stripos($text, $openTag, $offset);
            if ($start === false) {
                break;
            }

            $contentStart = $start + strlen($openTag);
            $end = stripos($text, $closeTag, $contentStart);
            if ($end === false) {
                break;
            }

            $inner = substr($text, $contentStart, $end - $contentStart);
            $replacement = $openHtml.$inner.$closeHtml;
            $text = substr_replace($text, $replacement, $start, ($end + strlen($closeTag)) - $start);
            $offset = $start + strlen($replacement);
        }

        return $text;
    }

    private function replaceUrlTags(string $text): string
    {
        $text = $this->replaceExplicitUrlTags($text);

        return $this->replaceImplicitUrlTags($text);
    }

    private function replaceExplicitUrlTags(string $text): string
    {
        $offset = 0;

        while (true) {
            $start = stripos($text, '[url=', $offset);
            if ($start === false) {
                break;
            }

            $valueStart = $start + 5;
            $valueEnd = strpos($text, ']', $valueStart);
            if ($valueEnd === false) {
                break;
            }

            $closeStart = stripos($text, '[/url]', $valueEnd + 1);
            if ($closeStart === false) {
                break;
            }

            $url = substr($text, $valueStart, $valueEnd - $valueStart);
            $label = substr($text, $valueEnd + 1, $closeStart - ($valueEnd + 1));
            $sanitized = $this->sanitizeUrl($url);
            $replacement = $sanitized === null
                ? $label
                : sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', $sanitized, $label);

            $text = substr_replace($text, $replacement, $start, ($closeStart + 6) - $start);
            $offset = $start + strlen($replacement);
        }

        return $text;
    }

    private function replaceImplicitUrlTags(string $text): string
    {
        $offset = 0;

        while (true) {
            $start = stripos($text, '[url]', $offset);
            if ($start === false) {
                break;
            }

            $contentStart = $start + 5;
            $closeStart = stripos($text, '[/url]', $contentStart);
            if ($closeStart === false) {
                break;
            }

            $url = substr($text, $contentStart, $closeStart - $contentStart);
            $sanitized = $this->sanitizeUrl($url);
            $replacement = $sanitized === null
                ? $url
                : sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', $sanitized, $url);

            $text = substr_replace($text, $replacement, $start, ($closeStart + 6) - $start);
            $offset = $start + strlen($replacement);
        }

        return $text;
    }

    /** @return list<array{is_code:bool,text:string}> */
    private function splitCodeSegments(string $text): array
    {
        $segments = [];
        $offset = 0;

        while (true) {
            $openPos = stripos($text, '[code]', $offset);
            if ($openPos === false) {
                $segments[] = ['is_code' => false, 'text' => substr($text, $offset)];

                break;
            }

            if ($openPos > $offset) {
                $segments[] = ['is_code' => false, 'text' => substr($text, $offset, $openPos - $offset)];
            }

            $contentStart = $openPos + 6;
            $closePos = stripos($text, '[/code]', $contentStart);
            if ($closePos === false) {
                $segments[] = ['is_code' => false, 'text' => substr($text, $openPos)];

                break;
            }

            $segments[] = ['is_code' => true, 'text' => substr($text, $contentStart, $closePos - $contentStart)];
            $offset = $closePos + 7;
        }

        return array_values(array_filter($segments, static fn (array $segment): bool => $segment['text'] !== ''));
    }

    private function sanitizeUrl(string $value): ?string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $decoded = preg_replace('/[\x00-\x1F\x7F]+/u', '', $decoded) ?? '';
        $decoded = trim($decoded);
        if ($decoded === '') {
            return null;
        }

        if (str_starts_with($decoded, '//')) {
            return null;
        }

        if (str_starts_with($decoded, '/') || str_starts_with($decoded, '#')) {
            return htmlspecialchars($decoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (!preg_match('/^(https?:|mailto:|tel:)/i', $decoded)) {
            return null;
        }

        if (!$this->isAllowedExternalUrl($decoded)) {
            return null;
        }

        return htmlspecialchars($decoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isAllowedExternalUrl(string $decoded): bool
    {
        $parts = parse_url($decoded);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === 'mailto' || $scheme === 'tel') {
            return true;
        }

        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        if ($this->urlHostAllowlist === []) {
            return true;
        }

        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        foreach ($this->urlHostAllowlist as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }
}
