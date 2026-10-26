<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\GameTemplateSeeder;
use App\Module\Core\Domain\Entity\MinecraftVersionCatalog;
use App\Module\Gameserver\Application\InstanceJobPayloadBuilder;
use App\Module\Gameserver\Application\MinecraftConfigurationPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\Process;

final class MinecraftManagedPanelTest extends AbstractWebTestCase
{
    public function testManagedInstallCommandsRejectMissingArtifactsInsteadOfReportingSuccess(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $version = 'artifact-check-'.bin2hex(random_bytes(8));
        $instance->setLockedVersion($version);
        $instance->getNode()->recordHeartbeat(['os' => 'linux'], 'test', '127.0.0.1', [], ['minecraft_managed_install' => true]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $builder = self::getContainer()->get(InstanceJobPayloadBuilder::class);
        $dir = sys_get_temp_dir().'/minecraft-install-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $entries = [];
        try {
            foreach (['vanilla' => 'minecraft_vanilla', 'paper' => 'papermc_paper', 'spigot' => 'minecraft_spigot', 'bungeecord' => 'minecraft_bungeecord', 'bedrock' => 'minecraft_bedrock'] as $channel => $resolver) {
                $instance->getTemplate()->setInstallResolver(['type' => $resolver]);
                $entry = new MinecraftVersionCatalog($channel, $version, null, 'https://example.com/server');
                $entries[] = $entry;
                $entry->setJavaVersion('21');
                $em->persist($entry);
                $em->flush();
                $payload = $builder->buildSniperInstallPayload($instance);
                self::assertSame('true', $payload['minecraft_managed']);
                self::assertSame($payload['install_command'], $payload['update_command']);
                $artifact = $dir.'/'.($channel === 'bedrock' ? 'bedrock_server' : 'server.jar');
                foreach (['missing', 'directory', 'empty', 'present'] as $state) {
                    if ($state === 'directory') { mkdir($artifact); }
                    if ($state === 'empty') { file_put_contents($artifact, ''); }
                    if ($state === 'present') { file_put_contents($artifact, 'installed artifact'); }
                    if ($channel === 'bedrock' && is_file($artifact)) { chmod($artifact, 0700); }
                    // A trailing successful shell command must not mask the failure.
                    $process = new Process(['sh', '-c', $payload['install_command'].'; true'], $dir);
                    $process->run();
                    self::assertSame($state === 'present' ? 0 : 1, $process->getExitCode(), $channel.': '.$state);
                    if ($state !== 'present') {
                        self::assertStringContainsString('MINECRAFT_ARTIFACT_MISSING', $process->getErrorOutput());
                    }
                    if (is_dir($artifact)) { rmdir($artifact); }
                    elseif (is_file($artifact)) { unlink($artifact); }
                }
                if ($channel === 'bedrock') {
                    file_put_contents($artifact, 'non executable');
                    chmod($artifact, 0600);
                    $process = new Process(['sh', '-c', $payload['install_command']], $dir);
                    $process->run();
                    self::assertSame(1, $process->getExitCode());
                    unlink($artifact);
                }
            }
        } finally {
            foreach ($entries as $entry) { $em->remove($entry); }
            $em->flush();
            foreach (glob($dir.'/*') ?: [] as $path) {
                if (is_dir($path)) { rmdir($path); } else { unlink($path); }
            }
            rmdir($dir);
        }
    }

    public function testAdminCreationOffersNewTypesAndCatalogSelection(): void
    {
        $this->seedSite();
        $this->seedInstance();
        self::getContainer()->get(GameTemplateSeeder::class)->seedTemplatesOnly();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $entry = new MinecraftVersionCatalog('spigot', '1.21.11', null, 'https://hub.spigotmc.org/BuildTools.jar');
        $entry->setJavaVersion('21');
        $entry->setSource('sync');
        $em->persist($entry);
        $em->flush();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginAsRole($client, 'admin');
        $client->request('GET', '/admin/instances/form');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-minecraft-channel="spigot"', $html);
        self::assertStringContainsString('data-minecraft-channel="bungeecord"', $html);
        self::assertStringContainsString('name="minecraft_version"', $html);
        self::assertStringContainsString('name="bungee_backend"', $html);
        self::assertStringContainsString('1.21.11', $html);
        $client->request('GET', '/admin/minecraft-versions');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/admin/minecraft-versions/sync', (string) $client->getResponse()->getContent());
        $client->request('POST', '/admin/minecraft-versions/sync', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRuntimeKeepsInstalledJavaWhileUpdateUsesSelectedCatalogVersion(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->getTemplate()->setInstallResolver(['type' => 'minecraft_vanilla']);
        $instance->getNode()->recordHeartbeat(['os' => 'linux'], 'test', '127.0.0.1', [], ['os' => 'linux', 'minecraft_managed_install' => true]);
        $instance->setInstalledVersion('1.20.4');
        $instance->setInstalledJavaVersion('17');
        $instance->setLockedVersion('1.21.11');
        $entry = new MinecraftVersionCatalog('vanilla', '1.21.11', null, 'https://piston-data.mojang.com/new.jar');
        $entry->setJavaVersion('21');
        $entry->setMetadata(['sha1' => str_repeat('a', 40)]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($entry);
        $em->flush();
        $builder = self::getContainer()->get(InstanceJobPayloadBuilder::class);
        $runtime = $builder->buildRuntimePayload($instance);
        self::assertSame('true', $runtime['minecraft_managed']);
        self::assertSame('1.20.4', $runtime['minecraft_version']);
        self::assertSame('17', $runtime['minecraft_java_version']);
        $update = $builder->buildSniperUpdatePayload($instance, null, '1.21.11');
        self::assertSame('1.21.11', $update['minecraft_version']);
        self::assertSame('21', $update['minecraft_java_version']);
        self::assertSame(str_repeat('a', 40), $update['minecraft_sha1']);
        self::assertStringNotContainsString('set_property', $runtime['start_params']);
        $env = array_column($runtime['env_vars'], 'value', 'key');
        self::assertSame('384M', $env['JAVA_XMX']);
        self::assertSame('384M', $env['JAVA_XMS']);
    }

    public function testCustomerCanEditGameSettingsButCannotChangeReservedPorts(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->getTemplate()->setInstallResolver(['type' => 'minecraft_vanilla']);
        $instance->getNode()->recordHeartbeat(['os' => 'linux'], 'test', '127.0.0.1', [], ['os' => 'linux', 'minecraft_managed_install' => true]);
        $old = "server-port=25565\nmotd=Old\ndifficulty=easy\n";
        MinecraftConfigurationPolicy::assertEditable($instance, 'server.properties', $old, "server-port=25565\nmotd=New\ndifficulty=hard\n");
        $this->expectException(\InvalidArgumentException::class);
        MinecraftConfigurationPolicy::assertEditable($instance, 'server.properties', $old, "server-port=1\nmotd=New\n");
    }

    public function testCustomerProxySettingsShowInstalledBuildAndJava(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->getTemplate()->setInstallResolver(['type' => 'minecraft_bungeecord']);
        $instance->setInstalledVersion('BungeeCord');
        $instance->setInstalledBuildId('2104');
        $instance->setInstalledJavaVersion('17');
        $id = $instance->getId();
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        $client->request('GET', '/instances/'.$id.'/settings');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('BungeeCord Proxy', $html);
        self::assertStringContainsString('2104', $html);
        self::assertStringContainsString('name="locked_build_id"', $html);
    }

    public function testCreationReservesOnlyRequestedPortsAndPinsTheSelectedVersion(): void
    {
        $this->seedSite();
        $seed = $this->seedInstance();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('DELETE FROM port_allocations');
        self::getContainer()->get(GameTemplateSeeder::class)->seedTemplatesOnly();
        $template = $em->getRepository(\App\Module\Core\Domain\Entity\Template::class)->findOneBy(['gameKey' => 'minecraft_vanilla_all']);
        $pool = new \App\Module\Ports\Domain\Entity\PortPool($seed->getNode(), 'Minecraft validation', 'gameserver', 30000, 30100);
        $em->persist($pool);
        $entry = new MinecraftVersionCatalog('vanilla', '1.20.6', null, 'https://piston-data.mojang.com/1.20.6.jar');
        $entry->setJavaVersion('21');
        $em->persist($entry);
        $em->flush();
        $input = ['customer_id' => $seed->getCustomer()->getId(), 'template_id' => $template->getId(), 'node_id' => $seed->getNode()->getId(), 'cpu_limit' => 50, 'ram_limit' => 2048, 'disk_limit' => 10000, 'minecraft_version' => '1.20.6', 'instance_base_dir' => '/home'];
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginAsRole($client, 'admin');
        $this->useFreeNodePorts($client);
        foreach ([false, true] as $extras) {
            $client->jsonRequest('POST', '/api/admin/instances', $input + ['minecraft_rcon' => $extras, 'minecraft_query' => $extras]);
            self::assertResponseStatusCodeSame(201);
            $id = json_decode((string) $client->getResponse()->getContent(), true)['id'];
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $instance = $em->find(\App\Module\Core\Domain\Entity\Instance::class, $id);
            self::assertSame('1.20.6', $instance->getLockedVersion());
            $reserved = $em->getRepository(\App\Module\Ports\Domain\Entity\PortAllocation::class)->findBy(['instance' => $instance]);
            self::assertCount($extras ? 3 : 1, $reserved);
            if ($extras) {
                self::assertSame(['tcp', 'tcp', 'udp'], array_map(static fn ($port): string => $port->getProto(), $reserved));
            }
        }
    }

    public function testManualSpigotJarRemainsADirectDownload(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->getTemplate()->setInstallResolver(['type' => 'minecraft_spigot']);
        $instance->getNode()->recordHeartbeat(['os' => 'linux'], 'test', '127.0.0.1', [], ['minecraft_managed_install' => true]);
        $entry = new MinecraftVersionCatalog('spigot', 'custom-1', null, 'https://example.com/custom-spigot.jar');
        $entry->setSource('manual');
        $entry->setJavaVersion('21');
        $instance->setLockedVersion('custom-1');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($entry);
        $em->flush();
        $payload = self::getContainer()->get(InstanceJobPayloadBuilder::class)->buildSniperInstallPayload($instance);
        self::assertSame('download', $payload['minecraft_install_strategy']);
        self::assertSame('21', $payload['minecraft_java_version']);
    }

    public function testMissingAgentCapabilityBlocksNewInstallAndKeepsLegacyRuntime(): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $instance->getTemplate()->setInstallResolver(['type' => 'minecraft_vanilla']);
        $builder = self::getContainer()->get(InstanceJobPayloadBuilder::class);
        self::assertArrayNotHasKey('minecraft_managed', $builder->buildRuntimePayload($instance));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MINECRAFT_AGENT_UPDATE_REQUIRED');
        $builder->buildSniperInstallPayload($instance);
    }

    public function testPreparingTemplatesPreservesExistingCustomizations(): void
    {
        $this->seedSite();
        $this->seedInstance();
        $seeder = self::getContainer()->get(GameTemplateSeeder::class);
        $keys = ['minecraft_vanilla_all', 'minecraft_spigot_all', 'minecraft_bungeecord_all'];
        $seeder->seedTemplatesOnly(null, $keys, true);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $template = $em->getRepository(\App\Module\Core\Domain\Entity\Template::class)->findOneBy(['gameKey' => 'minecraft_spigot_all']);
        $template->setStartParams('custom-start-command');
        $em->flush();
        $result = $seeder->seedTemplatesOnly(null, $keys, true);
        self::assertSame(0, $result['created']);
        self::assertSame(0, $result['updated']);
        $em->refresh($template);
        self::assertSame('custom-start-command', $template->getStartParams());
    }

    public function testBedrockReservesTcpSignalingAlongsideUdpPorts(): void
    {
        $this->seedSite();
        $seed = $this->seedInstance();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('DELETE FROM port_allocations');
        self::getContainer()->get(GameTemplateSeeder::class)->seedTemplatesOnly(null, ['minecraft_bedrock'], true);
        $template = $em->getRepository(\App\Module\Core\Domain\Entity\Template::class)->findOneBy(['gameKey' => 'minecraft_bedrock']);
        $pool = new \App\Module\Ports\Domain\Entity\PortPool($seed->getNode(), 'Bedrock validation', 'gameserver', 31000, 31100);
        $entry = new MinecraftVersionCatalog('bedrock', '1.26.52.3', null, 'https://www.minecraft.net/bedrock.zip');
        $em->persist($pool);
        $em->persist($entry);
        $em->flush();
        $input = ['customer_id' => $seed->getCustomer()->getId(), 'template_id' => $template->getId(), 'node_id' => $seed->getNode()->getId(), 'cpu_limit' => 50, 'ram_limit' => 2048, 'disk_limit' => 10000, 'minecraft_version' => '1.26.52.3', 'instance_base_dir' => '/home'];
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginAsRole($client, 'admin');
        $this->useFreeNodePorts($client);
        $client->jsonRequest('POST', '/api/admin/instances', $input);
        self::assertResponseStatusCodeSame(201);
        $id = json_decode((string) $client->getResponse()->getContent(), true)['id'];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $em->find(\App\Module\Core\Domain\Entity\Instance::class, $id);
        $reserved = $em->getRepository(\App\Module\Ports\Domain\Entity\PortAllocation::class)->findBy(['instance' => $instance]);
        self::assertCount(3, $reserved);
        self::assertSame(['udp', 'udp', 'tcp'], array_map(static fn ($port): string => $port->getProto(), $reserved));
        self::assertSame($reserved[0]->getPort(), $reserved[2]->getPort());
    }
    private function useFreeNodePorts(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $client->disableReboot();
        $agent = $this->createStub(\App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient::class);
        $agent->method('checkFreePortsForNode')->willReturnCallback(static fn ($node,array $checks): array => array_map(static fn (array $check): array => $check+['free'=>true],$checks));
        self::getContainer()->set(\App\Module\Ports\Application\NodePortAvailability::class,new \App\Module\Ports\Application\NodePortAvailability($agent));
    }

}
