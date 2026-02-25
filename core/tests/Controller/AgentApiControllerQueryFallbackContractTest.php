<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class AgentApiControllerQueryFallbackContractTest extends TestCase
{
    public function testControllerContainsA2sFallbackRetryGuardAndQueue(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Module/PanelCustomer/UI/Controller/Api/AgentApiController.php');

        self::assertStringContainsString('shouldQueueA2sFallbackAttempt', $controller);
        self::assertStringContainsString("new Job('instance.query.check', \$fallbackPayload)", $controller);
        self::assertStringContainsString("'fallback_attempted'", $controller);
        self::assertStringContainsString("'instance.query.retry_queued'", $controller);
        self::assertStringContainsString("['steam_a2s', 'a2s']", $controller);
        self::assertStringContainsString('resolveFallbackQueryPort', $controller);
        self::assertStringContainsString('fallback_query_ports', $controller);
        self::assertStringContainsString('query_timeout', $controller);
        self::assertStringContainsString('i/o timeout', $controller);
    }

    public function testJobStartEndpointIsIdempotentForTerminalStates(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Module/PanelCustomer/UI/Controller/Api/AgentApiController.php');

        self::assertStringContainsString('$job->getStatus()->isTerminal()', $controller);
        self::assertStringContainsString('Job is already completed by another agent.', $controller);
    }



    public function testAgentAuthenticationFailuresReturnJsonUnauthorizedResponse(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Module/PanelCustomer/UI/Controller/Api/AgentApiController.php');

        self::assertStringContainsString('private function unauthorizedAgentResponse', $controller);
        self::assertStringContainsString('catch (UnauthorizedHttpException $exception)', $controller);
        self::assertStringContainsString("JsonResponse::HTTP_UNAUTHORIZED", $controller);
        self::assertStringContainsString('\'error\' => $reason', $controller);
    }

    public function testHeartbeatPrefersForwardedIpOverLoopbackClientIp(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Module/PanelCustomer/UI/Controller/Api/AgentApiController.php');

        self::assertStringContainsString('resolveAgentIpFromRequest', $controller);
        self::assertStringContainsString('X-Forwarded-For', $controller);
        self::assertStringContainsString('X-Real-IP', $controller);
    }
}
