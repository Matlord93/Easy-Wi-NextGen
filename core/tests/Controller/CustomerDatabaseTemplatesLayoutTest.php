<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class CustomerDatabaseTemplatesLayoutTest extends TestCase
{
    public function testDatabaseSubpagesExtendCustomerBaseLayout(): void
    {
        $templates = [
            'tables.html.twig',
            'table_structure.html.twig',
            'table_rows.html.twig',
            'table_create.html.twig',
            'table_row_edit.html.twig',
            'details.html.twig',
        ];

        foreach ($templates as $template) {
            $tpl = (string) file_get_contents(__DIR__ . '/../../templates/customer/databases/' . $template);
            self::assertStringContainsString("{% extends 'customer/base.html.twig' %}", $tpl, $template);
            self::assertStringContainsString("{% set activeNav = 'databases' %}", $tpl, $template);
            self::assertStringContainsString('{% block content %}', $tpl, $template);
            self::assertStringNotContainsString("{% extends 'layouts/customer.html.twig' %}", $tpl, $template);
        }
    }

    public function testDatabaseOverviewDetailsLinkUsesHtmlPage(): void
    {
        $tpl = (string) file_get_contents(__DIR__ . '/../../templates/customer/databases/index.html.twig');

        self::assertStringContainsString('/databases/{{ database.id }}/details', $tpl);
        self::assertStringNotContainsString('/api/v1/customer/databases/{{ database.id }}/jobs', $tpl);
    }

    public function testDetailsTemplateRendersConnectionFieldsAndCredentialStates(): void
    {
        $tpl = (string) file_get_contents(__DIR__ . '/../../templates/customer/databases/details.html.twig');

        foreach (['database.host', 'database.port', 'database.name', 'database.username', 'database.engine', 'database.status', 'database.node.name'] as $field) {
            self::assertStringContainsString($field, $tpl);
        }

        self::assertStringContainsString('customer_databases_password_show_once', $tpl);
        self::assertStringContainsString('customer_databases_password_once_warning', $tpl);
        self::assertStringContainsString('customer_databases_password_already_consumed', $tpl);
        self::assertStringContainsString('customer_databases_password_not_available', $tpl);
        self::assertStringContainsString('db_credential_already_consumed', $tpl);
        self::assertStringContainsString('<code id="db-password-value"', $tpl);
    }

}
