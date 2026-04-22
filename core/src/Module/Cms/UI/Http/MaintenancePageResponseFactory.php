<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Http;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class MaintenancePageResponseFactory
{
    private const CSP_POLICY = "default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'";

    public function __construct(private readonly Environment $twig)
    {
    }

    /** @param array{message:string,graphic_path:string,starts_at:?\DateTimeImmutable,ends_at:?\DateTimeImmutable,scope:?string} $maintenance */
    public function create(array $maintenance): Response
    {
        $response = new Response($this->twig->render('public/maintenance.html.twig', [
            'message' => $maintenance['message'],
            'graphic_path' => $maintenance['graphic_path'],
            'starts_at' => $maintenance['starts_at'],
            'ends_at' => $maintenance['ends_at'],
            'scope' => $maintenance['scope'],
        ]), Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Content-Security-Policy', self::CSP_POLICY);

        return $response;
    }
}
