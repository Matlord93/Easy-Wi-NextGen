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
        if (isset($this->overrides[$version])) { return $this->overrides[$version]; }
        foreach (MinecraftJavaVersionResolver::JAVA_BIN_BY_VERSION as $knownVersion => $binary) {
            if ((string) $knownVersion === $version) { return $binary; }
        }
        return 'java'.$version;
    }

}
