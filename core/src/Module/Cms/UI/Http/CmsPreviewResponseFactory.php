<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CmsPreviewResponseFactory
{
    public function create(string $html, Request $request, string $scheme = 'dark'): Response
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new \RuntimeException('Unable to render CMS preview.');
        }
        foreach (['script', 'base', 'iframe', 'object', 'embed'] as $tag) {
            $nodes = $document->getElementsByTagName($tag);
            for ($index = $nodes->length - 1; $index >= 0; --$index) {
                $node = $nodes->item($index);
                if (null !== $node) {
                    $node->parentNode?->removeChild($node);
                }
            }
        }
        $elements = $document->getElementsByTagName('*');
        for ($index = 0; $index < $elements->length; ++$index) {
            $element = $elements->item($index);
            if (!$element instanceof \DOMElement) {
                continue;
            }
            $attributes = $element->attributes;
            for ($attributeIndex = ($attributes?->length ?? 0) - 1; $attributeIndex >= 0; --$attributeIndex) {
                $attribute = $attributes?->item($attributeIndex);
                if (!$attribute instanceof \DOMAttr) {
                    continue;
                }
                $name = strtolower($attribute->name);
                if (str_starts_with($name, 'on') || (in_array($name, ['href', 'xlink:href', 'action', 'formaction', 'ping', 'autofocus'], true) && 'link' !== $element->tagName)) {
                    $element->removeAttribute($attribute->name);
                }
            }
            if ('meta' === $element->tagName && 'refresh' === strtolower(trim($element->getAttribute('http-equiv')))) {
                $element->removeAttribute('http-equiv');
            }
        }
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body instanceof \DOMElement) {
            $body->setAttribute('inert', '');
            $body->setAttribute('data-cms-preview', 'true');
        }
        $root = $document->documentElement;
        if (null !== $root) {
            $classes = preg_split('/\s+/', trim($root->getAttribute('class'))) ?: [];
            $classes = array_values(array_filter($classes, static fn (string $value): bool => '' !== $value && 'dark' !== $value));
            if ('dark' === $scheme) {
                $classes[] = 'dark';
            }
            $root->setAttribute('class', implode(' ', $classes));
        }
        $rendered = $document->saveHTML();
        if (false === $rendered) {
            throw new \RuntimeException('Unable to serialize CMS preview.');
        }
        $origin = $request->getSchemeAndHttpHost();
        // Only static local resources may load. In particular, CSS or image URLs
        // cannot call a panel action such as /logout while opening a preview.
        $response = new Response($rendered);
        $response->headers->set('Content-Security-Policy', "sandbox; default-src 'none'; script-src 'none'; style-src 'unsafe-inline' $origin/themes/ $origin/assets/ $origin/ui.css $origin/workspace-public.css $origin/cms-themes.css $origin/cms-shop.css $origin/cookie-consent.css; img-src data: $origin/uploads/ $origin/images/ $origin/themes/ $origin/assets/ $origin/easywi-nextgen-logo.svg; font-src $origin/themes/ $origin/assets/; form-action 'none'; base-uri 'none'; object-src 'none'; frame-ancestors 'self'");
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
