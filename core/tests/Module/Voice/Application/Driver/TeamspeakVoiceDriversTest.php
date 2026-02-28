<?php

declare(strict_types=1);

namespace App\Tests\Module\Voice\Application\Driver;

use App\Module\Teamspeak\Application\Query\QueryCommandBuilder;
use App\Module\Teamspeak\Application\Query\QueryContext;
use App\Module\Teamspeak\Application\Query\QueryRequest;
use App\Module\Teamspeak\Application\Query\QueryResponse;
use App\Module\Teamspeak\Application\Query\SshConnectionConfig;
use App\Module\Teamspeak\Application\Query\TeamSpeakQueryClientInterface;
use App\Module\Voice\Application\Driver\Teamspeak3Driver;
use App\Module\Voice\Application\Driver\Teamspeak6Driver;
use App\Module\Voice\Application\Model\PermissionSet;
use App\Module\Voice\Application\Model\VoiceServer;
use App\Module\Voice\Application\Query\VoiceQueryEngine;
use PHPUnit\Framework\TestCase;

final class TeamspeakVoiceDriversTest extends TestCase
{
    public function testTs3AndTs6UseDifferentTokenCommandsForSameFlow(): void
    {
        $client = new class implements TeamSpeakQueryClientInterface {
            /** @var list<string> */
            public array $commands = [];

            public function execute(QueryRequest $request, QueryContext $context): QueryResponse
            {
                foreach ($request->commands() as $command) {
                    $this->commands[] = $command->command();
                }

                return new QueryResponse(true, 'ok', ['provider' => $context->tsVersion()]);
            }
        };

        $builder = new QueryCommandBuilder();
        $context3 = new QueryContext('ts3', 1, '127.0.0.1', 10011, 'serveradmin', 'pw', new SshConnectionConfig('127.0.0.1', 22, 'root', 'pw'));
        $context6 = new QueryContext('ts6', 1, '127.0.0.1', 10022, 'serveradmin', 'pw', new SshConnectionConfig('127.0.0.1', 22, 'root', 'pw'));

        $driver3 = new Teamspeak3Driver($client, $builder, $context3);
        $driver6 = new Teamspeak6Driver($client, $builder, $context6);
        $engine = new VoiceQueryEngine();
        $server3 = new VoiceServer('a', 'ts3', '127.0.0.1', 10011, 9987);
        $server6 = new VoiceServer('b', 'ts6', '127.0.0.1', 10022, 9987);

        $driver3->createToken($server3, new PermissionSet(['i_channel_join_power']), $engine);
        $driver6->createToken($server6, new PermissionSet(['i_channel_join_power']), $engine);

        self::assertContains('tokenadd', $client->commands);
        self::assertContains('privilegekeyadd', $client->commands);
    }
}
