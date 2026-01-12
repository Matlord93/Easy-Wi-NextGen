# Payments — Provider Interface & Manual Flow

## Goals
- Keep billing functional without external gateways.
- Provide a clear provider interface for future integrations (Stripe/SEPA).
- Allow manual status changes with audit logging.

## Provider Interface
Implementations live in `core/src/Service/Billing/` and must implement:

- `createPaymentIntent(Invoice $invoice, int $amountCents): PaymentInstruction`
- `webhookHandle(Request $request): ?Response`
- `reconcile(?DateTimeImmutable $since = null): void`
- `supportedMethods(): array`

### Dummy Provider (TEST ONLY)
- Provider name: `dummy`
- Intended for non-production testing.
- In production, enabling requires `APP_PAYMENT_DUMMY_ENABLED=1`.

### Manual Provider
- Provider name: `manual`
- Default method for MVP billing without gateways.
- No webhooks; payments are marked manually by admins.

## Future Integrations (Stripe/SEPA)
When adding a real provider:
1. Implement `PaymentProviderInterface` in `core/src/Service/Billing/StripePaymentProvider.php` (or `SepaPaymentProvider.php`).
2. `createPaymentIntent()` should return a `PaymentInstruction` with a hosted checkout URL or SEPA mandate info.
3. `webhookHandle()` must verify signatures and record payments via `App\Service\Billing\PaymentRecorder`.
4. `reconcile()` should backfill missing or delayed settlements.
5. Expose supported methods in `supportedMethods()` (e.g. `card`, `sepa_debit`).
6. Add UI labels for new methods in `App\Controller\CustomerProfileController`.

## Manual Test Steps
1. Create an invoice in `/admin/billing/invoices/new`.
2. Open the invoice detail page and use **Payment actions**:
   - **Mark as paid** should set status to paid and write an AuditLog entry.
   - **Mark as unpaid** should restore status to open/past_due and write an AuditLog entry.
3. Export invoices/payments from `/admin/billing` and verify CSV downloads.
