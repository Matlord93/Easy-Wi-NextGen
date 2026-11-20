<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\Registrar\RegistrarSettings;
use App\Module\Core\Domain\Entity\RegistrarOperation;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final readonly class AdminRegistrarController
{
    public function __construct(private EntityManagerInterface $em, private RegistrarSettings $settings, private AuditLoggerInterface $audit, private CsrfTokenManagerInterface $csrf, private Environment $twig)
    {
    }

    #[Route('/admin/shop/registrar', name: 'admin_shop_registrar', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $actor = RequestActor::user($request);
        if (null === $actor || !$actor->isAdmin()) {
            return new Response('Forbidden.', 403);
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('registrar', $request->request->getString('_token')))) {
                return new Response('Invalid CSRF token.', 403);
            }
            try {
                if ('reconcile' === $request->request->getString('action')) {
                    $operation = $this->em->find(RegistrarOperation::class, $request->request->getString('operation_id'));
                    if (null === $operation) {
                        throw new \InvalidArgumentException('Auftrag nicht gefunden.');
                    }
                    $this->em->wrapInTransaction(function () use ($operation, $request): void {
                        $this->em->refresh($operation, LockMode::PESSIMISTIC_WRITE);
                        $operation->reconcile($request->request->getString('job_id'));
                    });
                    $this->audit->log($actor, 'registrar.operation.reconcile', ['operation_id' => $operation->getId(), 'job_id' => $operation->getJobId()]);
                } else {
                    $input = $request->request->all();
                    foreach (['enabled', 'sandbox', 'clear_user', 'clear_password'] as $key) {
                        $input[$key] = $request->request->getBoolean($key);
                    }
                    $this->settings->save($input);
                    $this->audit->log($actor, 'registrar.settings.changed', ['provider' => 'autodns', 'sandbox' => $request->request->getBoolean('sandbox')]);
                }
                $this->em->flush();

                return new RedirectResponse('/admin/shop/registrar?saved=1', 303);
            } catch (\DomainException|\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }
        $page = max(1, min(10000, $request->query->getInt('page', 1)));

        return new Response($this->twig->render('admin/shop/registrar.html.twig', ['activeNav' => 'shop-contracts', 'settings' => $this->settings->publicSettings(), 'operations' => $this->em->getRepository(RegistrarOperation::class)->findBy([], ['createdAt' => 'DESC'], 50, ($page - 1) * 50), 'page' => $page, 'error' => $error]), null !== $error ? 422 : 200);
    }
}
