<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\GameTemplateSeedCatalog;
use App\Module\Core\Application\GameTemplateSeedSyncService;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelAdmin\UI\Controller\Admin\AdminTemplateController;
use App\Repository\TemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class AdminTemplateSharedPathsTest extends TestCase
{
    public function testUpdatedPathsAreAuditedWithTheCurrentActor(): void
    {
        $actor = new User('admin@example.test', UserType::Admin);
        $template = new Template(
            gameKey: 'cs2', displayName: 'CS2', description: null, steamAppId: 730,
            sniperProfile: null, requiredPorts: [], startParams: '', envVars: [], configFiles: [],
            pluginPaths: [], fastdlSettings: [], installCommand: '', updateCommand: '',
            installResolver: [], allowedSwitchFlags: [], requirements: ['custom' => 'preserved'],
        );
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getClassMetadata')->with(Template::class)->willReturn(new ClassMetadata(Template::class));
        $em->expects(self::once())->method('find')->with(Template::class, 7)->willReturn($template);
        $em->expects(self::once())->method('persist')->with($template);
        $em->expects(self::once())->method('flush');
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('log')->with($actor, 'template.shared_paths_synced', ['template_id' => null, 'game_key' => 'cs2']);
        $controller = new AdminTemplateController(
            new TemplateRepository($registry), $em, $audit,
            new GameTemplateSeedSyncService(new GameTemplateSeedCatalog()),
            new Environment(new ArrayLoader()), new Translator('en'),
        );
        $request = Request::create('/admin/templates/7/sync-shared-paths', 'POST');
        $request->attributes->set('current_user', $actor);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $response = $controller->syncSharedPaths($request, '7');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('templates-changed', $response->headers->get('HX-Trigger'));
        self::assertSame('preserved', $template->getRequirements()['custom']);
        self::assertNotEmpty($template->getRequirements()['shared_paths']);
        self::assertTrue($request->getSession()->getFlashBag()->has('success'));
    }
}
