<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class DatabaseRowEditTemplateAssertionsTest extends TestCase
{
    public function testEditTemplateContainsCustomerLayoutAndNavigationAndReadonlyHints(): void
    {
        $tpl = (string) file_get_contents(__DIR__ . '/../../templates/customer/databases/table_row_edit.html.twig');
        self::assertStringContainsString("{% extends 'customer/base.html.twig' %}", $tpl);
        self::assertStringContainsString("customer_databases_back_to_rows", $tpl);
        self::assertStringContainsString("save", $tpl);
        self::assertStringContainsString("cancel", $tpl);
        self::assertStringContainsString("readonly", $tpl);
        self::assertStringContainsString("customer_databases_field_not_editable", $tpl);
    }

    public function testRowsTemplateContainsPkStateMessagesAndNoHardcodedNewTexts(): void
    {
        $tpl = (string) file_get_contents(__DIR__ . '/../../templates/customer/databases/table_rows.html.twig');
        self::assertStringContainsString("customer_databases_edit_no_primary_key", $tpl);
        self::assertStringContainsString("customer_databases_edit_composite_not_supported", $tpl);
        self::assertStringContainsString("t('edit'", $tpl);
    }
}
