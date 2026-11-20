<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class CustomWebsiteDirectory
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDir)
    {
    }

    public function path(): string
    {
        return $this->projectDir.'/website/public';
    }

    public function prepare(): void
    {
        (new Filesystem())->mkdir($this->path(), 0755);
    }
}
