<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\TokenGenerator;
use App\Module\Core\Domain\Entity\AgentBootstrapToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:agent:bootstrap-token:create',
    description: 'Create an agent bootstrap token for unattended installer flows.',
)]
final class AgentBootstrapTokenCreateCommand extends Command
{
    public function __construct(
        private readonly TokenGenerator $tokenGenerator,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Existing plaintext token to store. A random token is generated if omitted.')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name for the bootstrap token.', 'Installer bootstrap token')
            ->addOption('expires-in', null, InputOption::VALUE_REQUIRED, 'Expiry in minutes. Use 0 for no expiry.', '30')
            ->addOption('max-attempts', null, InputOption::VALUE_REQUIRED, 'Maximum bootstrap attempts. Use 0 for unlimited attempts.', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $token = trim((string) $input->getOption('token'));
        $tokenData = $token !== '' ? $this->tokenGenerator->fromToken($token) : $this->tokenGenerator->generate();

        $name = trim((string) $input->getOption('name'));
        if ($name === '') {
            $name = 'Installer bootstrap token';
        }

        $expiresIn = (int) $input->getOption('expires-in');
        if ($expiresIn < 0) {
            $io->error('expires-in must be greater than or equal to 0.');

            return Command::FAILURE;
        }

        $maxAttempts = (int) $input->getOption('max-attempts');
        if ($maxAttempts < 0) {
            $io->error('max-attempts must be greater than or equal to 0.');

            return Command::FAILURE;
        }

        $bootstrapToken = new AgentBootstrapToken(
            $name,
            $tokenData['token_prefix'],
            $tokenData['token_hash'],
            $tokenData['encrypted_token'],
            $expiresIn > 0 ? new \DateTimeImmutable(sprintf('+%d minutes', $expiresIn)) : null,
            null,
            $maxAttempts,
        );

        $this->entityManager->persist($bootstrapToken);
        $this->entityManager->flush();

        if (!$output->isQuiet()) {
            $io->success(sprintf('Created bootstrap token %s.', $tokenData['token_prefix']));
            $io->writeln($tokenData['token']);
        }

        return Command::SUCCESS;
    }
}
