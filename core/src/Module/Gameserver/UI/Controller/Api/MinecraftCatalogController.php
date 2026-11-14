<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Api;

use App\Infrastructure\Http\RequestActor;

use App\Module\Core\Attribute\RequiresModule;
use App\Module\Core\Domain\Entity\User;
use App\Module\Gameserver\Application\MinecraftCatalogService;
use App\Module\Gameserver\Application\MinecraftCatalogSynchronizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[RequiresModule('game')]
final readonly class MinecraftCatalogController
{
    public function __construct(private MinecraftCatalogService $catalog, private MinecraftCatalogSynchronizer $synchronizer)
    {
    }

    #[Route('/api/v1/minecraft/catalog', name: 'minecraft_catalog', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        if (!RequestActor::user($request) instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        return new JsonResponse(['data' => ['catalog' => $this->catalog->getUiCatalog(), 'sync' => $this->synchronizer->publicStatus()]], 200, ['Cache-Control' => 'private, no-store']);
    }
}
