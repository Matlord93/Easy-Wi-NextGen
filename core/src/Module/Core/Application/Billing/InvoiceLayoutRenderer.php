<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\Invoice;
use App\Repository\CustomerProfileRepository;
use App\Repository\InvoicePreferencesRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Sandbox\Sandbox;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

use Twig\Node\Node;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\TestExpression;
use Twig\Node\Expression\Binary\RangeBinary;
use Twig\Source;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Template;

final class InvoiceLayoutRenderer
{
    private const TAGS = ['if', 'for', 'set'];
    private const FILTERS = ['escape', 'e', 'date', 'number_format', 'default', 'replace', 'title', 'upper', 'lower', 'nl2br', 'length', 'join', 'trim', 'round', 'abs'];
    private const TESTS = ['defined', 'empty', 'null', 'none', 'true', 'false', 'even', 'odd', 'iterable', 'same as', 'sequence', 'mapping', 'number', 'string'];
    public function __construct(
        private readonly CustomerProfileRepository $profileRepository,
        private readonly SiteResolver $siteResolver,
        private readonly BillingSettings $settings,
        private readonly TranslatorInterface $translator,
        private readonly InvoicePreferencesRepository $preferences,
    ) {
    }

    public function snapshot(Invoice $invoice, Request $request, array $tax, string $description): void
    {
        $parties = $this->parties($invoice, $request);
        $invoice->setBillingDetails(array_merge($tax, $parties, ['description' => $description, 'document' => [
            'number' => $invoice->getNumber(), 'currency' => $invoice->getCurrency(), 'total_cents' => $invoice->getAmountTotalCents(),
            'issue_date' => $invoice->getCreatedAt()->format('Y-m-d'), 'due_date' => $invoice->getDueDate()->format('Y-m-d'),
        ]]));
    }

    public function render(Invoice $invoice, Request $request, string $layout): string
    {
        if (strlen($layout) > 100000) {
            throw new \InvalidArgumentException('billing.layout_too_large');
        }
        $details = $invoice->getBillingDetails();
        $parties = isset($details['customer'], $details['company']) ? $details : $this->parties($invoice, $request);
        $context = [
            'invoice' => array_merge($details, [
                'id' => $invoice->getId(), 'number' => $invoice->getNumber(), 'status' => $invoice->getStatus()->value,
                'currency' => $invoice->getCurrency(), 'amount_total' => $invoice->getAmountTotalCents(),
                'amount_due' => $invoice->getOutstandingCents(), 'amount_paid' => $invoice->getPaidAmountCents(),
                'due_date' => $invoice->getDueDate(), 'paid_at' => $invoice->getPaidAt(), 'created_at' => $invoice->getCreatedAt(),
                'cancelled_at' => $invoice->getCancelledAt(), 'cancellation_reason' => $invoice->getCancellationReason(),
                'description' => $details['description'] ?? $invoice->getNumber(),
                'items' => InvoiceItems::forInvoice($invoice),
                'tax_breakdown' => $details['tax_breakdown'] ?? [['tax_rate_bps' => $details['tax_rate_bps'] ?? 0, 'tax_cents' => $details['tax_cents'] ?? 0]],
                'net_cents' => $details['net_cents'] ?? $invoice->getAmountTotalCents(), 'tax_cents' => $details['tax_cents'] ?? 0,
                'tax_rate_bps' => $details['tax_rate_bps'] ?? 0, 'tax_mode' => $details['tax_mode'] ?? 'legacy',
            ]),
            'customer' => $parties['customer'], 'company' => $parties['company'],
        ];
        $locale = $details['locale'] ?? $this->preferences->findOneByCustomer($invoice->getCustomer())?->getLocale() ?? 'de';
        $labels = [];
        foreach (['invoice', 'number', 'date', 'due_date', 'bill_to', 'description', 'net', 'tax', 'total', 'outstanding', 'vat_id', 'cancelled', 'quantity', 'unit_price', 'line_total', 'unit_price_short', 'line_total_short', 'gross', 'tax_short'] as $label) {
            $labels[$label] = $this->translator->trans('billing.'.$label, [], 'billing', $locale);
        }
        $context['labels'] = $labels;
        $context['decimal_separator'] = str_starts_with($locale, 'de') ? ',' : '.';
        $context['thousands_separator'] = str_starts_with($locale, 'de') ? '.' : ',';
        $context['date_format'] = str_starts_with($locale, 'de') ? 'd.m.Y' : 'Y-m-d';
        $context['invoice']['tax_notice'] = $this->translator->trans('billing.notice.'.$context['invoice']['tax_mode'], [], 'billing', $locale);
        try {
            return $this->sandbox()->createTemplate($this->checkedLayout($layout))->render($context);
        } catch (\Twig\Error\Error $exception) {
            throw new \InvalidArgumentException('billing.invalid_layout', 0, $exception);
        }
    }

    public function validate(string $layout): void
    {
        if ('' === trim($layout) || strlen($layout) > 100000) {
            throw new \InvalidArgumentException('billing.invalid_layout');
        }
        try {
            $this->sandbox()->createTemplate($this->checkedLayout($layout))->render([]);
        } catch (\Twig\Error\Error $exception) {
            throw new \InvalidArgumentException('billing.invalid_layout', 0, $exception);
        }
    }

    public static function previewDocument(string $html): string
    {
        return '<!doctype html><html><head><meta charset="UTF-8"><meta http-equiv="Content-Security-Policy" content="default-src &apos;none&apos;; style-src &apos;unsafe-inline&apos;; img-src data:; font-src data:; form-action &apos;none&apos;; base-uri &apos;none&apos;"><style>body{background:#fff;color:#18212f;font-family:sans-serif;padding:20px;overflow-wrap:anywhere}</style></head><body>'.$html.'</body></html>';
    }

    private function sandbox(): Sandbox
    {
        $twig = new Environment(new ArrayLoader(), ['autoescape' => 'html', 'strict_variables' => false]);
        $policy = new SecurityPolicy(
            ['if', 'for', 'set'],
            ['escape', 'e', 'date', 'number_format', 'default', 'replace', 'title', 'upper', 'lower', 'nl2br', 'length', 'join', 'trim', 'round', 'abs'],
            [],
            [],
            [],
            ['defined', 'empty', 'null', 'none'],
        );
        $policy->setStrict(true);

        return new Sandbox($twig, $policy);
    }

    /**
     * Only the parsed, allow-listed invoice language may reach Twig's compiler.
     * This removes PHP-evaluation taint only: HTML and invoice values remain
     * subject to autoescaping and the always-enabled runtime sandbox.
     *
     * @psalm-taint-escape eval
     */
    private function checkedLayout(string $layout): string
    {
        if ('' === trim($layout) || strlen($layout) > 100000) {
            throw new \InvalidArgumentException('billing.invalid_layout');
        }
        $twig = new Environment(new ArrayLoader(), ['autoescape' => 'html']);
        $tree = $twig->parse($twig->tokenize(new Source($layout, 'invoice-layout')));
        $this->checkNode($tree);

        return $layout;
    }

    private function checkNode(Node $node): void
    {
        $tag = $node->getNodeTag();
        $allowed = !$tag || in_array($tag, self::TAGS, true);
        if ($node instanceof FilterExpression) {
            $allowed = $allowed && in_array($node->getAttribute('name'), self::FILTERS, true);
        }
        if ($node instanceof TestExpression) {
            $allowed = $allowed && in_array($node->getAttribute('name'), self::TESTS, true);
        }
        if ($node instanceof FunctionExpression || $node instanceof RangeBinary || $node->hasAttribute('sandboxed_function_name')) {
            $allowed = false;
        }
        if ($node instanceof GetAttrExpression && Template::METHOD_CALL === $node->getAttribute('type')) {
            $allowed = false;
        }
        if (!$allowed) {
            throw new \Twig\Error\SyntaxError('This construct is not permitted in invoice layouts.', $node->getTemplateLine());
        }
        foreach ($node as $child) {
            $this->checkNode($child);
        }
    }

    private function parties(Invoice $invoice, Request $request): array
    {
        $customer = $invoice->getCustomer();
        $profile = $this->profileRepository->findOneByCustomer($customer);
        $name = trim((string) $profile?->getCompany());
        if ('' === $name) {
            $name = trim(($profile?->getFirstName() ?? '').' '.($profile?->getLastName() ?? '')) ?: $customer->getEmail();
        }
        $site = $this->siteResolver->resolve($request);
        $settings = $this->settings->get();

        return [
            'customer' => [
                'id' => $customer->getId(), 'email' => $customer->getEmail(), 'name' => $name,
                'company' => $profile?->getCompany(), 'address' => $profile?->getAddress(), 'postal' => $profile?->getPostal(),
                'city' => $profile?->getCity(), 'country' => $profile?->getCountry(), 'phone' => $profile?->getPhone(), 'vat_id' => $profile?->getVatId(),
            ],
            'company' => [
                'name' => $settings['company_name'] ?: ($site?->getName() ?? 'Easy-Wi'),
                'host' => $site?->getHost() ?? $request->getHost(), 'address' => $settings['company_address'],
                'email' => $settings['company_email'], 'vat_id' => $settings['company_vat_id'], 'footer' => $settings['company_footer'],
                'street' => $settings['company_street'], 'postal' => $settings['company_postal'], 'city' => $settings['company_city'], 'country' => $settings['company_country'],
                'contact' => $settings['company_contact'], 'phone' => $settings['company_phone'], 'tax_number' => $settings['company_tax_number'], 'iban' => $settings['company_iban'], 'bic' => $settings['company_bic'],
            ],
            'locale' => $this->preferences->findOneByCustomer($customer)?->getLocale() ?? 'de',
        ];
    }
}
