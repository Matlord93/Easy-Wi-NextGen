<?php

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Module\Billing\UI\Controller\Customer\CustomerPaymentController;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BillingControllerAutoloadTest extends KernelTestCase
{
    public function testCustomerPaymentControllerAutoloadsFromExpectedFile(): void
    {
        self::bootKernel();

        self::assertTrue(class_exists(CustomerPaymentController::class));

        $reflection = new \ReflectionClass(CustomerPaymentController::class);
        $filename = $reflection->getFileName();

        self::assertIsString($filename);
        self::assertStringEndsWith(
            '/src/Module/Billing/UI/Controller/Customer/CustomerPaymentController.php',
            str_replace('\\', '/', $filename)
        );
    }
}
