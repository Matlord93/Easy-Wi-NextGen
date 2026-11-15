<?php

declare(strict_types=1);

namespace App\Module\Nodes\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Module\Nodes\Application\Console\VmConsoleDiagnostics;
use App\Module\Nodes\Application\Console\VmConsoleBroker;
use App\Repository\AgentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AdminVmConsoleController extends AbstractController
{
    public function __construct(private readonly VmConsoleDiagnostics $diagnostics, private readonly AgentRepository $agents, private readonly VmConsoleBroker $broker, #[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    #[Route('/admin/vm-console', name: 'admin_vm_console', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new AccessDeniedHttpException();
        }
        $core = realpath($this->projectDir) ?: $this->projectDir;
        $helper = $core.'/tools/console/setup-vm-console.sh';
        $nodes = [];
        foreach ($this->agents->findBy([], ['name' => 'ASC'], 100) as $node) {
            $metadata = $node->getMetadata()['kvm_managed'] ?? [];
            if (true === ($metadata['supported'] ?? false)) {
                $nodes[] = ['id' => $node->getId(), 'name' => $node->getName() ?: $node->getId(), 'console' => 1 === ($metadata['console_version'] ?? null), 'transport' => $this->broker->kvmTransportAvailable($node)];
            }
        }

        return $this->render('admin/vm_console/index.html.twig', [
            'activeNav' => 'kvm', 'gateway' => $this->diagnostics->status(), 'nodes' => $nodes,
            'helperAvailable' => is_file($helper), 'commandPrefix' => 'sudo bash '.escapeshellarg($helper).' '.escapeshellarg($core).' ',
        ], new Response(headers: ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']));
    }
}
