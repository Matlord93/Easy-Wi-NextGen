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
        ];

        foreach ($templates as $template) {
            $tpl = (string) file_get_contents(__DIR__ . '/../../templates/customer/databases/' . $template);
            self::assertStringContainsString("{% extends 'customer/base.html.twig' %}", $tpl, $template);
            self::assertStringContainsString("{% set activeNav = 'databases' %}", $tpl, $template);
            self::assertStringContainsString('{% block content %}', $tpl, $template);
            self::assertStringNotContainsString("{% extends 'layouts/customer.html.twig' %}", $tpl, $template);
        }
    }
}
