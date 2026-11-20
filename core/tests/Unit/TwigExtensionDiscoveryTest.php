<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Extension\Twig\CmsThemeTwigExtension;
use App\Module\Core\Application\Contracts\RentalCapacityTwigExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class TwigExtensionDiscoveryTest extends TestCase
{
    public function testAnalysisCanDiscoverCmsAndRentalExtensionsWithoutTheServiceContainer(): void
    {
        $twig = new Environment(new ArrayLoader());
        // Psalm creates Twig extensions without their constructor dependencies.
        $twig->addExtension((new \ReflectionClass(CmsThemeTwigExtension::class))->newInstanceWithoutConstructor());
        $twig->addExtension((new \ReflectionClass(RentalCapacityTwigExtension::class))->newInstanceWithoutConstructor());

        self::assertNotNull($twig->getFilter('cms_trans'));
        self::assertNotNull($twig->getFunction('cms_site_context'));
        self::assertNotNull($twig->getFunction('cms_shop_preview'));
        self::assertNotNull($twig->getFunction('rental_availability'));
        self::assertNotNull($twig->getFunction('rental_readiness'));
    }
}
