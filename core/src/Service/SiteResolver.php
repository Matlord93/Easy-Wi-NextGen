<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Site;
use App\Repository\SiteRepository;
use Symfony\Component\HttpFoundation\Request;

final class SiteResolver
{
    public function __construct(private readonly SiteRepository $siteRepository)
    {
    }

    public function resolve(Request $request): ?Site
    {
        $host = $request->getHost();
        if ($host !== '') {
            $site = $this->siteRepository->findOneByHost($host);
            if ($site instanceof Site) {
                return $site;
            }
        }

        return $this->siteRepository->findDefault();
    }
}
