<?php

declare(strict_types=1);

namespace App\Tests\Module\Cms\UI\Http;

use App\Module\Cms\UI\Http\MaintenancePageResponseFactory;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class MaintenancePageResponseFactoryTest extends TestCase
{
    public function testAddsStrictCspHeader(): void
    {
        $twig = new Environment(new ArrayLoader([
            'public/maintenance.html.twig' => '<h1>maintenance</h1>',
        ]));
        $factory = new MaintenancePageResponseFactory($twig);

        $response = $factory->create([
            'message' => 'msg',
            'graphic_path' => '/img.png',
            'starts_at' => null,
            'ends_at' => null,
            'scope' => 'site',
        ]);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame("default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'", $response->headers->get('Content-Security-Policy'));
    }
}
