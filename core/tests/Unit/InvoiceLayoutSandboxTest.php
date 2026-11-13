<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Application\Billing\InvoiceDesign;
use App\Module\Core\Application\Billing\InvoiceLayoutRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceLayoutSandboxTest extends TestCase
{
    private function renderer(): InvoiceLayoutRenderer
    {
        // Validation has no dependency on database state or a customer account.
        return (new \ReflectionClass(InvoiceLayoutRenderer::class))->newInstanceWithoutConstructor();
    }

    #[DataProvider('attacks')]
    public function testDisallowedSyntaxIsRejectedBeforeRendering(string $source): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->renderer()->validate($source);
    }

    public static function attacks(): iterable
    {
        yield 'file read' => ["{{ source('/etc/passwd') }}"];
        yield 'include' => ["{% include '/etc/passwd' %}"];
        yield 'inheritance' => ["{% extends '/etc/passwd' %}"];
        yield 'trait' => ["{% use '/etc/passwd' %}"];
        yield 'callback' => ["{{ ['id']|map('system')|join }}"];
        yield 'constant' => ["{{ constant('PHP_VERSION') }}"];
        yield 'raw data' => ["{{ customer.name|raw }}"];
        yield 'object method in inactive branch' => ["{% if invoice %}{{ invoice.created_at.modify('+1 day') }}{% endif %}"];
        yield 'environment access' => ["{{ _self.env.registerUndefinedFilterCallback('system') }}"];
        yield 'attribute function' => ["{{ attribute(invoice, 'created_at') }}"];
        yield 'large range' => ["{% for item in 1..1000000000 %}{{ item }}{% endfor %}"];
        yield 'inactive file read' => ["{% if invoice %}{{ source('/etc/passwd') }}{% endif %}"];
        yield 'oversize' => [str_repeat('x', 100001)];
    }

    public function testDefaultDesignAndSupportedCustomLayoutsRemainValid(): void
    {
        $renderer = $this->renderer();
        $renderer->validate(InvoiceDesign::layout(InvoiceDesign::defaults()));
        $renderer->validate('{% set heading = "Invoice" %}<h1>{{ heading }}</h1>{% if invoice.items is defined %}{% for item in invoice.items %}{{ item.description|e }} {{ (item.gross_cents / 100)|number_format(2) }}{% endfor %}{% endif %}');
        self::addToAssertionCount(2);
    }
}
