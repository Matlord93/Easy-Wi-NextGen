<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Gameserver\Application\Console\ConsoleStreamDiagnostics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/admin/diagnostics')]
final class AdminConsoleDiagnosticsController extends AbstractController
{
    public function __construct(private readonly ConsoleStreamDiagnostics $diagnostics)
    {
    }

    #[Route(path: '/console-stream', name: 'admin_diagnostics_console_stream', methods: ['GET'])]
    #[Route(path: '/console-stream/', name: 'admin_diagnostics_console_stream_slash', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $data = $this->diagnostics->snapshot();

        if (str_contains(strtolower((string) $request->headers->get('Accept', '')), 'application/json')) {
            return new JsonResponse($data);
        }

        return $this->render('admin/diagnostics/console_stream.html.twig', [
            'diagnostics' => $data,
        ]);
    }
}
