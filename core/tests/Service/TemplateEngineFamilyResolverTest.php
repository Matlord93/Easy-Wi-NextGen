<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Domain\Entity\Template;
use App\Module\Gameserver\Application\Query\TemplateEngineFamilyResolver;
use PHPUnit\Framework\TestCase;

final class TemplateEngineFamilyResolverTest extends TestCase
{
    public function testResolvesSource2FromCs2(): void
    {
        $resolver = new TemplateEngineFamilyResolver();
        self::assertSame('source2', $resolver->resolve($this->template('cs2', ['query' => ['type' => 'steam_a2s']])));
    }

    public function testResolvesMinecraftJava(): void
    {
        $resolver = new TemplateEngineFamilyResolver();
        self::assertSame('minecraft_java', $resolver->resolve($this->template('minecraft_vanilla_all', ['query' => ['type' => 'minecraft_java']])));
    }

    public function testResolvesBedrock(): void
    {
        $resolver = new TemplateEngineFamilyResolver();
        self::assertSame('bedrock', $resolver->resolve($this->template('minecraft_bedrock', ['query' => ['type' => 'minecraft_bedrock']])));
    }

    public function testDefaultsToSource1(): void
    {
        $resolver = new TemplateEngineFamilyResolver();
        self::assertSame('source1', $resolver->resolve($this->template('l4d2', ['query' => ['type' => 'steam_a2s']])));
    }

    private function template(string $gameKey, array $requirements): Template
    {
        $template = $this->createMock(Template::class);
        $template->method('getGameKey')->willReturn($gameKey);
        $template->method('getRequirements')->willReturn($requirements);

        return $template;
    }
}
