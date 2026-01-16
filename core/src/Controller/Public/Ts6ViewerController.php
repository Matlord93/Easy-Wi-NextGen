<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\Ts6ViewerRepository;
use App\Service\Ts6\Ts6ViewerService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Twig\Environment;

final class Ts6ViewerController
{
    public function __construct(
        private readonly Ts6ViewerService $viewerService,
        private readonly Ts6ViewerRepository $viewerRepository,
        #[Autowire(service: 'limiter.ts6_viewer')]
        private readonly RateLimiterFactory $viewerLimiter,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/viewer/ts6/{publicId}.json', name: 'ts6_viewer_snapshot', methods: ['GET'])]
    public function snapshot(Request $request, string $publicId): Response
    {
        $limiter = $this->viewerLimiter->create(($request->getClientIp() ?? 'public') . ':' . $publicId);
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            $response = new Response('Too Many Requests.', Response::HTTP_TOO_MANY_REQUESTS);
            $retryAfter = $limit->getRetryAfter();
            if ($retryAfter !== null) {
                $response->headers->set('Retry-After', (string) max(1, $retryAfter->getTimestamp() - time()));
            }

            return $response;
        }

        $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer');

        try {
            $payload = $this->viewerService->getPublicSnapshot($publicId, $origin);
        } catch (\Throwable $exception) {
            return new JsonResponse(['status' => 'error', 'message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        $response = new JsonResponse($payload, Response::HTTP_OK);
        $response->setPublic();
        $response->setMaxAge(1);

        return $response;
    }

    #[Route(path: '/viewer/ts6/{publicId}', name: 'ts6_viewer_page', methods: ['GET'])]
    public function page(string $publicId): Response
    {
        $viewer = $this->viewerRepository->findOneBy(['publicId' => $publicId]);
        if ($viewer === null || !$viewer->isEnabled()) {
            throw new NotFoundHttpException('Viewer not found.');
        }

        $server = $viewer->getVirtualServer();

        return new Response($this->twig->render('viewer/ts6/viewer.html.twig', [
            'public_id' => $publicId,
            'server' => $server,
        ]));
    }

    #[Route(path: '/viewer/ts6/{publicId}.js', name: 'ts6_viewer_widget', methods: ['GET'])]
    public function widget(string $publicId): Response
    {
        $viewer = $this->viewerRepository->findOneBy(['publicId' => $publicId]);
        if ($viewer === null || !$viewer->isEnabled()) {
            throw new NotFoundHttpException('Viewer not found.');
        }

        $script = <<<JS
(function () {
  const container = document.getElementById('ts6-viewer');
  if (!container) { return; }
  fetch('/viewer/ts6/{$publicId}.json')
    .then((response) => response.json())
    .then((data) => {
      if (!data || data.status !== 'ok') { return; }
      const title = document.createElement('div');
      title.className = 'ts6-viewer-title';
      title.textContent = data.server?.name || 'TS6 Server';
      const list = document.createElement('ul');
      list.className = 'ts6-viewer-list';
      (data.channels || []).forEach((channel) => {
        const item = document.createElement('li');
        item.textContent = channel.name || 'Channel';
        list.appendChild(item);
      });
      container.innerHTML = '';
      container.appendChild(title);
      container.appendChild(list);
    });
})();
JS;

        return new Response($script, Response::HTTP_OK, [
            'Content-Type' => 'application/javascript',
        ]);
    }
}
