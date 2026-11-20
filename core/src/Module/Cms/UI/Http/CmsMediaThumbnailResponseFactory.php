<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CmsMediaThumbnailResponseFactory
{
    /** @param array{data:string,mime:string} $image */
    public function create(Request $request, array $image): Response
    {
        $response = new Response($image['data'], Response::HTTP_OK, [
            'Content-Type' => $image['mime'],
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Vary' => 'Cookie',
        ]);
        $response->setEtag(hash('sha256', $image['data']));
        $response->isNotModified($request);

        return $response;
    }
}
