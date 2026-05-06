<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Gameserver\Application\GameserverInstanceScheduleRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:gameserver:run-instance-schedules',
    description: 'Queues due gameserver instance schedules as agent jobs.',
)]
final class RunGameserverInstanceSchedulesCommand extends Command
{
    public function __construct(private readonly GameserverInstanceScheduleRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lockHandle = $this->acquireProcessLock();
        if ($lockHandle === null) {
            $output->writeln('Gameserver instance scheduler lock is already held. Skipping run.');
            return Command::SUCCESS;
        }

        try {
            $queued = $this->runner->runDue(new \DateTimeImmutable());
            $output->writeln(sprintf('Queued %d gameserver instance schedule job(s).', $queued));

            return Command::SUCCESS;
        } finally {
            $this->releaseProcessLock($lockHandle);
        }
    }

    /**
     * @return resource|null
     */
    private function acquireProcessLock()
    {
        $path = sprintf('%s/easywi-run-schedules.lock', sys_get_temp_dir());
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return null;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        @ftruncate($handle, 0);
        @fwrite($handle, (string) getmypid());

        return $handle;
    }

    /**
     * @param resource|null $lockHandle
     */
    private function releaseProcessLock($lockHandle): void
    {
        if ($lockHandle === null) {
            return;
        }

        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
}
