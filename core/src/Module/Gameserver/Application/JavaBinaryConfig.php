<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

final class JavaBinaryConfig
{
    /** @param array<array-key, string> $overrides Keys are Java versions ('8','17','21'), values are binary names or paths */
    public function __construct(
        private readonly array $overrides = [],
        public readonly bool $autoInstallJava = false,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function getBinForVersion(string $version): string
    {
        return $this->overrides[$version]
            ?? MinecraftJavaVersionResolver::JAVA_BIN_BY_VERSION[$version]
            ?? 'java'.$version;
    }

    /** @return array<array-key, string> map of version → binary */
    public function allBinaries(): array
    {
        $result = MinecraftJavaVersionResolver::JAVA_BIN_BY_VERSION;
        foreach ($this->overrides as $version => $bin) {
            if (isset($result[$version])) {
                $result[$version] = $bin;
            }
        }
        return $result;
    }
}
