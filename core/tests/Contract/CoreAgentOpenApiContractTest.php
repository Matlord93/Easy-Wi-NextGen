<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CoreAgentOpenApiContractTest extends TestCase
{
    private array $spec;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/docs/api/core-agent.v1.openapi.yaml';
        $this->spec = Yaml::parseFile($path);
    }

    public function testSpecContainsProductionEndpoints(): void
    {
        $expectedPaths = [
            '/api/v1/agent/bootstrap',
            '/api/v1/agent/register',
            '/api/v1/agent/heartbeat',
            '/api/v1/agent/metrics-batch',
            '/api/v1/agent/mail/logs-batch',
            '/api/v1/agent/jobs',
            '/api/v1/agent/jobs/{id}/start',
            '/api/v1/agent/jobs/{id}/result',
            '/api/v1/agent/jobs/{id}/logs',
        ];

        foreach ($expectedPaths as $path) {
            self::assertArrayHasKey($path, $this->spec['paths']);
        }
    }

    public function testHeartbeatHappyAndErrorResponsesMatchSpec(): void
    {
        $happySchema = $this->responseSchema('/api/v1/agent/heartbeat', 'post', '200');
        $errorSchema = $this->responseSchema('/api/v1/agent/heartbeat', 'post', '403');

        $this->assertMatchesSchema($happySchema, ['status' => 'ok']);
        $this->assertMatchesSchema($errorSchema, ['error' => 'agent_decommissioned']);
    }

    public function testJobsHappyAndErrorResponsesMatchSpec(): void
    {
        $happySchema = $this->responseSchema('/api/v1/agent/jobs', 'get', '200');
        $errorSchema = $this->responseSchema('/api/v1/agent/jobs', 'get', '403');

        $this->assertMatchesSchema($happySchema, ['jobs' => [], 'max_concurrency' => 4]);
        $this->assertMatchesSchema($errorSchema, ['error' => 'agent_decommissioned']);
    }

    public function testBootstrapHappyAndErrorResponsesMatchSpec(): void
    {
        $happySchema = $this->responseSchema('/api/v1/agent/bootstrap', 'post', '200');
        $errorSchema = $this->responseSchema('/api/v1/agent/bootstrap', 'post', '401');

        $this->assertMatchesSchema($happySchema, [
            'register_url' => 'https://panel.example.com/api/v1/agent/register',
            'register_token' => 'rtok_x',
            'agent_id' => 'agent-1',
        ]);
        $this->assertMatchesSchema($errorSchema, ['error' => 'Invalid or expired bootstrap token.']);
    }

    private function responseSchema(string $path, string $method, string $status): array
    {
        $schema = $this->spec['paths'][$path][$method]['responses'][$status]['content']['application/json']['schema'] ?? null;
        self::assertIsArray($schema, sprintf('Missing schema for %s %s %s', strtoupper($method), $path, $status));

        return $schema;
    }

    private function assertMatchesSchema(array $schema, mixed $value): void
    {
        if (isset($schema['$ref'])) {
            $schema = $this->resolveSchemaRef($schema['$ref']);
        }

        $type = $schema['type'] ?? null;
        if ($type === 'object') {
            self::assertIsArray($value);
            $required = $schema['required'] ?? [];
            foreach ($required as $requiredField) {
                self::assertArrayHasKey($requiredField, $value);
            }
            foreach (($schema['properties'] ?? []) as $field => $childSchema) {
                if (!array_key_exists($field, $value)) {
                    continue;
                }
                $this->assertMatchesSchema($childSchema, $value[$field]);
            }
            return;
        }

        if ($type === 'array') {
            self::assertIsArray($value);
            $itemSchema = $schema['items'] ?? null;
            if (is_array($itemSchema)) {
                foreach ($value as $entry) {
                    $this->assertMatchesSchema($itemSchema, $entry);
                }
            }
            return;
        }

        if ($type === 'integer') {
            self::assertIsInt($value);
            return;
        }

        if ($type === 'boolean') {
            self::assertIsBool($value);
            return;
        }

        if ($type === 'string') {
            self::assertIsString($value);
        }
    }

    private function resolveSchemaRef(string $ref): array
    {
        $prefix = '#/components/schemas/';
        self::assertStringStartsWith($prefix, $ref);
        $key = substr($ref, strlen($prefix));
        self::assertArrayHasKey($key, $this->spec['components']['schemas']);

        return $this->spec['components']['schemas'][$key];
    }
}
