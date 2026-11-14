<?php

declare(strict_types=1);

namespace App\Module\Billing\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\Billing\BillingSettings;
use App\Module\Core\Application\Billing\InvoiceDesign;
use App\Module\Core\Application\Billing\InvoiceItems;
use App\Module\Core\Application\Billing\InvoiceLayoutRenderer;
use App\Module\Core\Domain\Entity\Invoice;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[Route('/admin/billing/design')]
final readonly class AdminInvoiceDesignController
{
    public function __construct(private InvoiceDesign $design, private InvoiceLayoutRenderer $renderer, private BillingSettings $settings, private AuditLogger $audit, private EntityManagerInterface $em, private Environment $twig, private CsrfTokenManagerInterface $csrf, private TranslatorInterface $translator)
    {
    }

    #[Route('', name: 'admin_billing_design', methods: ['GET', 'POST'])]
    public function editor(Request $request): Response
    {
        $actor = $this->actor($request, $request->isMethod('POST'));
        $state = $this->design->current();
        $errors = [];
        if ($request->isMethod('POST')) {
            try {
                $state = $this->submitted($request, $state);
                $this->renderer->validate($state['layout']);
                $this->preview($request, $state['layout']);
                \App\Module\Core\Application\Billing\InvoicePdfRenderer::assertCapabilities($state['layout']);
                $this->design->save($state['options'], $state['layout']);
                $this->audit->log($actor, 'billing.design.updated', ['mode' => $state['mode']]);
                $this->em->flush();

                return new RedirectResponse('/admin/billing/design?saved=1', 303);
            } catch (\InvalidArgumentException $error) {
                $errors[] = $this->translator->trans($error->getMessage(), [], 'billing');
                if ('custom' === $request->request->get('mode')) {
                    $state['mode'] = 'custom';
                    $raw = $request->request->get('invoice_layout', '');
                    if (is_string($raw) && strlen($raw) <= 100000) {
                        $state['layout'] = $raw;
                    }
                }
            }
        }
        try {
            $preview = $this->preview($request, $state['layout']);
        } catch (\InvalidArgumentException $error) {
            $preview = '';
            $errors[] = $this->translator->trans($error->getMessage(), [], 'billing');
        }

        return new Response($this->twig->render('admin/billing/design.html.twig', $state + ['activeNav' => 'billing', 'errors' => $errors, 'preview' => $preview]), $errors ? 422 : 200, ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/preview', name: 'admin_billing_design_preview', methods: ['POST'])]
    public function previewAction(Request $request): JsonResponse
    {
        $this->actor($request, true);
        try {
            $state = $this->submitted($request, $this->design->current());

            return new JsonResponse(['html' => $this->preview($request, $state['layout'])], 200, ['Cache-Control' => 'private, no-store']);
        } catch (\InvalidArgumentException $error) {
            return new JsonResponse(['error' => $this->translator->trans($error->getMessage(), [], 'billing')], 422, ['Cache-Control' => 'private, no-store']);
        }
    }

    private function submitted(Request $request, array $current): array
    {
        $mode = $request->request->get('mode');
        if (!in_array($mode, ['visual', 'custom'], true)) {
            throw new \InvalidArgumentException('billing.invalid_design');
        }
        $options = $current['options'];
        if ('visual' === $mode) {
            $input = $request->request->all('design');
            $input['logo_data_uri'] = $options['logo_data_uri'];
            if ('1' === $request->request->get('remove_logo')) {
                $input['logo_data_uri'] = '';
            } elseif ($file = $request->files->get('logo')) {
                if (!$file instanceof UploadedFile || !$file->isValid() || ($file->getSize() ?? 0) > 49152) {
                    throw new \InvalidArgumentException('billing.invalid_logo');
                }
                $mime = $file->getMimeType();
                if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
                    throw new \InvalidArgumentException('billing.invalid_logo');
                }
                $input['logo_data_uri'] = 'data:'.$mime.';base64,'.base64_encode($file->getContent());
            }
            $options = InvoiceDesign::normalize($input);
            $layout = InvoiceDesign::layout($options);
        } else {
            $layout = (string) $request->request->get('invoice_layout', '');
        }
        $this->renderer->validate($layout);

        return ['mode' => $mode, 'options' => $options, 'layout' => $layout];
    }

    private function preview(Request $request, string $layout): string
    {
        $customer = new User('kunde@example.test', UserType::Customer);
        $tax = InvoiceItems::calculate([
            ['description' => 'Cloud Server · Beispiel', 'quantity' => '2', 'unit_price' => '29.75', 'tax_rate' => '19'],
            ['description' => 'Einrichtung · Beispiel', 'quantity' => '1.5', 'unit_price' => '11.90', 'tax_rate' => '19'],
        ], 'standard');
        $invoice = new Invoice($customer, 'VORSCHAU-001', $tax['gross_cents'], 'EUR', new \DateTimeImmutable('+14 days'));
        $company = $this->settings->get();
        $invoice->setBillingDetails($tax + ['description' => 'Beispielrechnung', 'locale' => $request->getLocale(),
            'customer' => ['name' => 'Max Muster', 'address' => 'Beispielstraße 12', 'postal' => '12345', 'city' => 'Musterstadt', 'country' => 'DE', 'email' => 'kunde@example.test', 'vat_id' => ''],
            'company' => ['name' => $company['company_name'] ?: 'Meine Firma', 'address' => $company['company_address'], 'email' => $company['company_email'], 'vat_id' => $company['company_vat_id'], 'footer' => $company['company_footer']]]);

        return InvoiceLayoutRenderer::previewDocument($this->renderer->render($invoice, $request, $layout));
    }

    private function actor(Request $request, bool $write): User
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin() || ($write && !$this->csrf->isTokenValid(new CsrfToken('billing', (string) $request->request->get('_token'))))) {
            throw new AccessDeniedHttpException();
        }

        return $actor;
    }
}
