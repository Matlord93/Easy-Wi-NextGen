<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\Configuration\ValheimServerSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValheimServerSetupTest extends TestCase
{
    private function instance(string $game = 'valheim', string $parameters = 'valheim_server.x86_64 -port {{PORT_GAME}} -public 1'): Instance
    {
        $template = new Template($game, 'Valheim', null, null, null, [], $parameters, [], [], [], [], '', '', [], []);

        return new Instance(new User('valheim@example.test', UserType::Customer), $template, new Agent('valheim-node', []), 1, 2048, 10, null, InstanceStatus::Stopped, InstanceUpdatePolicy::Manual);
    }

    public function testUneditedNativeCommandsAndCustomWrappersStayUnchanged(): void
    {
        foreach (['valheim' => '"/srv/my server/valheim_server.x86_64"', 'valheim_windows' => '"C:/Game Servers/valheim_server.exe"'] as $game => $binary) {
            $command = $binary.' -public 1 -modifier raids less -preset hard -crossplay -savedir "custom saves" -logFile "server.log"';
            $instance = $this->instance($game, $command);
            self::assertTrue(ValheimServerSetup::supports($instance->getTemplate()));
            self::assertSame($command, ValheimServerSetup::parameters($instance, $command));
            self::assertSame('hard', ValheimServerSetup::defaults($instance)['VALHEIM_PRESET']);
            self::assertSame('less', ValheimServerSetup::defaults($instance)['VALHEIM_RAIDS']);
            self::assertSame('1', ValheimServerSetup::defaults($instance)['VALHEIM_CROSSPLAY']);
            $instance->getTemplate()->setStartParams('custom-wrapper --keep');
            $instance->setSetupVars(['VALHEIM_PUBLIC' => '0']);
            self::assertFalse(ValheimServerSetup::supports($instance->getTemplate()));
            self::assertSame('custom-wrapper --keep', ValheimServerSetup::parameters($instance, 'custom-wrapper --keep'));
        }
        self::assertFalse(ValheimServerSetup::supports($this->instance('rust')->getTemplate()));
    }

    public function testOperatingSettingsPreserveReservedPortsPathsAndUnrelatedArguments(): void
    {
        $instance = $this->instance(parameters: 'valheim_server.x86_64 -public 1 -port {{PORT_GAME}} -savedir "custom saves" -crossplay -saveinterval 1800 -backups 4 -logFile "server.log"');
        $instance->setSetupVars(['VALHEIM_PUBLIC' => '0', 'VALHEIM_CROSSPLAY' => '0', 'VALHEIM_SAVE_INTERVAL' => '600', 'VALHEIM_BACKUP_COUNT' => '0']);
        $parameters = ValheimServerSetup::parameters($instance, $instance->getTemplate()->getStartParams());
        self::assertSame(1, substr_count($parameters, '-public '));
        self::assertStringContainsString('-public {{VALHEIM_PUBLIC}}', $parameters);
        self::assertStringContainsString('-port {{PORT_GAME}}', $parameters);
        self::assertStringContainsString('-savedir "custom saves"', $parameters);
        self::assertStringContainsString('-logFile "server.log"', $parameters);
        self::assertStringNotContainsString('-crossplay', $parameters);
        self::assertSame(1, substr_count($parameters, '-saveinterval '));
        self::assertStringContainsString('-backups {{VALHEIM_BACKUP_COUNT}}', $parameters);
    }

    public function testEditedWorldRulesFollowTheirPresetAndRetainOtherNativeOverrides(): void
    {
        $instance = $this->instance(parameters: 'valheim_server.x86_64 -modifier raids less -preset hard -modifier portals veryhard -setkey nomap -public 1');
        $instance->setSetupVars(['VALHEIM_PRESET' => 'normal', 'VALHEIM_COMBAT' => 'easy']);
        $parameters = ValheimServerSetup::parameters($instance, $instance->getTemplate()->getStartParams());
        self::assertLessThan(strpos($parameters, '-modifier combat'), strpos($parameters, '-preset '));
        self::assertLessThan(strpos($parameters, '-modifier raids'), strpos($parameters, '-preset '));
        self::assertLessThan(strpos($parameters, '-modifier portals'), strpos($parameters, '-preset '));
        self::assertStringContainsString('-setkey nomap', $parameters);
        self::assertLessThan(strpos($parameters, '-setkey nomap'), strpos($parameters, '-preset '));
        self::assertStringContainsString('-public 1', $parameters);
        self::assertSame('less', ValheimServerSetup::defaults($instance)['VALHEIM_RAIDS']);
        self::assertSame('veryhard', ValheimServerSetup::defaults($instance)['VALHEIM_PORTALS']);
    }

    public function testFlagLikeTextInQuotedNamesAndPathsIsNeverEdited(): void
    {
        $name = '-name "Our -public 1 -crossplay clan"';
        $path = "-savedir '/srv/-preset hard'";
        $instance = $this->instance(parameters: 'valheim_server.x86_64 '.$name.' '.$path." -public 1 -public 0 -modifier raids 'none'");
        $defaults = ValheimServerSetup::defaults($instance);
        self::assertSame('0', $defaults['VALHEIM_PUBLIC']);
        self::assertSame('0', $defaults['VALHEIM_CROSSPLAY']);
        self::assertSame('', $defaults['VALHEIM_PRESET']);
        self::assertSame('none', $defaults['VALHEIM_RAIDS']);
        $instance->setSetupVars(['VALHEIM_PUBLIC' => '1', 'VALHEIM_CROSSPLAY' => '1', 'VALHEIM_PRESET' => 'normal']);
        $parameters = ValheimServerSetup::parameters($instance, $instance->getTemplate()->getStartParams());
        self::assertStringContainsString($name, $parameters);
        self::assertStringContainsString($path, $parameters);
        self::assertStringContainsString('-public {{VALHEIM_PUBLIC}}', $parameters);
        self::assertStringContainsString('-modifier raids {{VALHEIM_RAIDS}}', $parameters);
    }

    public function testRemovingAStartOverrideDoesNotInventAWorldReset(): void
    {
        $instance = $this->instance(parameters: 'valheim_server.x86_64 -preset hard -modifier raids none -public 1');
        $instance->setSetupVars(['VALHEIM_PRESET' => '', 'VALHEIM_RAIDS' => '', 'VALHEIM_CROSSPLAY' => '1']);
        $parameters = ValheimServerSetup::parameters($instance, $instance->getTemplate()->getStartParams());
        self::assertStringNotContainsString('-preset', $parameters);
        self::assertStringNotContainsString('-modifier', $parameters);
        self::assertStringContainsString('-crossplay', $parameters);
        self::assertStringContainsString('-public 1', $parameters);
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidPersistedValuesCannotBecomeStartupArguments(string $key, string $value): void
    {
        $instance = $this->instance();
        $instance->setSetupVars([$key => $value]);
        $this->expectException(\InvalidArgumentException::class);
        ValheimServerSetup::parameters($instance, $instance->getTemplate()->getStartParams());
    }

    public static function invalidSettings(): iterable
    {
        yield 'shell input' => ['VALHEIM_PRESET', 'hard; quit'];
        yield 'unknown rule' => ['VALHEIM_RAIDS', 'often'];
        yield 'negative backups' => ['VALHEIM_BACKUP_COUNT', '-1'];
        yield 'zero interval' => ['VALHEIM_SAVE_INTERVAL', '0'];
        yield 'overflow' => ['VALHEIM_SAVE_INTERVAL', '2147483648'];
        yield 'fraction' => ['VALHEIM_BACKUP_SHORT', '1.5'];
        yield 'invalid toggle' => ['VALHEIM_CROSSPLAY', 'yes'];
    }
}
