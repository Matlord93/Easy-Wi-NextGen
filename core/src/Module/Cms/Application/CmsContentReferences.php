<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Config\ScalarValue;
use App\Module\Core\Domain\Entity\CmsBlock;

/** Extract saved references without rendering blocks or executing embedded code. */
final class CmsContentReferences
{
    /** @return array{references:list<array{kind:string,url:string}>,truncated:bool} */
    public function extract(CmsBlock $block): array
    {
        $references = [];
        $html = '';
        if (2 === $block->getVersion() && null !== $block->getPayloadJson()) {
            $payload = $block->getPayloadJson() ?? [];
            switch ($block->getType()) {
                case 'hero':
                    $references[] = ['kind' => 'link', 'url' => ScalarValue::text($payload['ctaUrl'] ?? null)];
                    $references[] = ['kind' => 'media', 'url' => ScalarValue::text($payload['backgroundImagePath'] ?? null)];
                    break;
                case 'image':
                    $references[] = ['kind' => 'media', 'url' => ScalarValue::text($payload['path'] ?? null)];
                    break;
                case 'cards':
                    $cards = JsonArray::orEmpty($payload['cards'] ?? []);
                    foreach (array_keys($cards) as $key) {
                        $card = JsonMap::fromValue($cards[$key]);
                        if (null !== $card) {
                            $references[] = ['kind' => 'link', 'url' => ScalarValue::text($card['url'] ?? null)];
                            if (count($references) > 500) {
                                break;
                            }
                        }
                    }
                    break;
                case 'rich_text':
                    $html = ScalarValue::text($payload['content'] ?? null);
                    break;
            }
        } elseif (!in_array($block->getType(), ['server_list', 'server_featured'], true)) {
            $html = $block->getContent();
        }

        $truncated = strlen($html) > 262144 || count($references) > 500;
        if ('' !== $html) {
            $document = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try {
                $document->loadHTML('<?xml encoding="UTF-8">'.substr($html, 0, 262144), LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $elements = $document->getElementsByTagName('*');
            for ($index = 0; $index < $elements->length; ++$index) {
                $element = $elements->item($index);
                if (!$element instanceof \DOMElement) {
                    continue;
                }
                if (in_array($element->tagName, ['a', 'area'], true)) {
                    $references[] = ['kind' => 'link', 'url' => $element->getAttribute('href')];
                }
                if (in_array($element->tagName, ['img', 'source', 'audio', 'video'], true)) {
                    $references[] = ['kind' => 'media', 'url' => $element->getAttribute('src')];
                }
                if ('video' === $element->tagName) {
                    $references[] = ['kind' => 'media', 'url' => $element->getAttribute('poster')];
                }
                if (count($references) > 500) {
                    $truncated = true;
                    break;
                }
            }
        }

        return ['references' => array_slice($references, 0, 500), 'truncated' => $truncated];
    }
}
