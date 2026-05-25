<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\UI\Controller\Public;

use App\Module\Core\Application\Ts6\Ts6ViewerService;
use App\Repository\Ts6ViewerRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
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

    #[Route(path: '/viewer/ts6/{publicId}', name: 'ts6_viewer_page', requirements: ['publicId' => '[^/.]+'], methods: ['GET'])]
    public function page(string $publicId): Response
    {
        $viewer = $this->viewerRepository->findOneBy(['publicId' => $publicId]);
        if ($viewer === null || !$viewer->isEnabled()) {
            return $this->viewerNotFoundResponse();
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
            return $this->viewerNotFoundResponse();
        }

        $script = <<<JS
(function () {
  const container = document.getElementById('ts6-viewer');
  if (!container) { return; }
  let attempts = 0;
  const maxAttempts = 6;
  const delayMs = 1500;
  const asSnapshot = (data) => {
    if (!data || typeof data !== 'object') return {};
    if (data.snapshot && typeof data.snapshot === 'object') return data.snapshot;
    if (data.payload && typeof data.payload === 'object') return data.payload;
    if (data.data && typeof data.data === 'object') return data.data;
    return data;
  };
  const text = (value, fallback) => (typeof value === 'string' && value.trim() !== '' ? value : fallback);
  const normalizeChannels = (snapshot) => {
    const list = Array.isArray(snapshot?.channels) ? snapshot.channels
      : Array.isArray(snapshot?.payload?.channels) ? snapshot.payload.channels
      : Array.isArray(snapshot?.data?.channels) ? snapshot.data.channels
      : [];
    return list.map((channel) => {
      const id = String(channel?.id ?? channel?.cid ?? channel?.channel_id ?? '');
      const parentRaw = String(channel?.parentId ?? channel?.pid ?? channel?.cpid ?? channel?.parent_id ?? '');
      return {
        id,
        parentId: parentRaw && parentRaw !== '0' ? parentRaw : null,
        name: text(channel?.name ?? channel?.channel_name, 'Channel'),
        order: Number(channel?.order ?? channel?.channel_order ?? 0) || 0,
      };
    }).filter((channel) => channel.id !== '');
  };
  const normalizeClients = (snapshot) => {
    const list = Array.isArray(snapshot?.clients) ? snapshot.clients
      : Array.isArray(snapshot?.payload?.clients) ? snapshot.payload.clients
      : Array.isArray(snapshot?.data?.clients) ? snapshot.data.clients
      : [];
    return list
      .filter((client) => Number(client?.client_type ?? client?.type ?? 0) !== 1)
      .map((client) => ({
        id: String(client?.id ?? client?.clid ?? client?.client_id ?? ''),
        channelId: String(client?.channelId ?? client?.cid ?? client?.channel_id ?? ''),
        nickname: text(client?.nickname ?? client?.client_nickname ?? client?.name, 'User'),
      }))
      .filter((client) => client.id !== '' && client.channelId !== '');
  };
  const renderTree = (channels, clients) => {
    const tree = document.createElement('ul');
    tree.className = 'ts6-viewer-list';
    const byParent = new Map();
    channels.forEach((channel) => {
      const key = channel.parentId || 'root';
      if (!byParent.has(key)) byParent.set(key, []);
      byParent.get(key).push(channel);
    });
    byParent.forEach((list) => list.sort((a, b) => a.order - b.order || a.name.localeCompare(b.name)));
    const clientsByChannel = new Map();
    clients.forEach((client) => {
      if (!clientsByChannel.has(client.channelId)) clientsByChannel.set(client.channelId, []);
      clientsByChannel.get(client.channelId).push(client);
    });
    const renderNode = (parentId, target) => {
      const current = byParent.get(parentId) || [];
      current.forEach((channel) => {
        const item = document.createElement('li');
        item.textContent = channel.name;
        const users = clientsByChannel.get(channel.id) || [];
        if (users.length > 0) {
          const usersList = document.createElement('ul');
          users.forEach((user) => {
            const userItem = document.createElement('li');
            userItem.textContent = '👤 ' + user.nickname;
            usersList.appendChild(userItem);
          });
          item.appendChild(usersList);
        }
        const children = document.createElement('ul');
        renderNode(channel.id, children);
        if (children.childNodes.length > 0) item.appendChild(children);
        target.appendChild(item);
      });
    };
    renderNode('root', tree);
    return tree;
  };
  const loadSnapshot = () => {
    fetch('/viewer/ts6/{$publicId}.json')
      .then((response) => response.json())
      .then((data) => {
        if (!data) { return; }
        if (data.status === 'pending' && attempts < maxAttempts) {
          attempts += 1;
          setTimeout(loadSnapshot, delayMs);
          return;
        }
        if (data.status !== 'ok') {
          container.innerHTML = '<div class="ts6-muted">Agent konnte Viewer-Snapshot nicht abrufen.</div>';
          return;
        }
        const snapshot = asSnapshot(data);
        const channels = normalizeChannels(snapshot);
        const clients = normalizeClients(snapshot);
        const title = document.createElement('div');
        title.className = 'ts6-viewer-title';
        title.textContent = snapshot?.server?.name || data.server?.name || 'TS6 Server';
        container.innerHTML = '';
        container.appendChild(title);
        if (channels.length === 0) {
          container.appendChild(Object.assign(document.createElement('div'), { className: 'ts6-muted', textContent: 'Keine Channels im Snapshot gefunden.' }));
          return;
        }
        container.appendChild(renderTree(channels, clients));
      })
      .catch(() => { container.innerHTML = '<div class="ts6-muted">Viewer-Daten werden geladen...</div>'; });
  };
  loadSnapshot();
})();
JS;

        return new Response($script, Response::HTTP_OK, [
            'Content-Type' => 'application/javascript',
        ]);
    }

    private function viewerNotFoundResponse(): Response
    {
        return new Response('Viewer not found.', Response::HTTP_NOT_FOUND, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
