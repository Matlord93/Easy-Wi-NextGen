<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

interface UpdateJobServiceInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function getJob(string $id): ?array;

    public function getJobsDir(): string;
}
