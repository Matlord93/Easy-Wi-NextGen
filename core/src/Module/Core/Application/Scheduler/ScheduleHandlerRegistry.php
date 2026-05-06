<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

final class ScheduleHandlerRegistry
{
    /** @var array<string, ScheduleHandlerInterface> */
    private array $handlers = [];

    /** @param iterable<ScheduleHandlerInterface> $handlers */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->type()] = $handler;
        }
    }

    /** @return ScheduleHandlerInterface[] */
    public function all(): array
    {
        return array_values($this->handlers);
    }

    public function get(string $type): ?ScheduleHandlerInterface
    {
        return $this->handlers[$type] ?? null;
    }
}
