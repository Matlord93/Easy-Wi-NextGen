<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use App\Module\Core\Application\AppSettingsService;

#[AsCommand(
    name: 'app:mail:test',
    description: 'Send a test email using the configured SMTP transport.',
)]
final class MailTestCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly AppSettingsService $appSettingsService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('to', InputArgument::REQUIRED, 'Recipient email address')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Email subject', 'SMTP test')
            ->addOption('body', null, InputOption::VALUE_REQUIRED, 'Email body', 'SMTP test email sent via Symfony Mailer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = (string) $input->getArgument('to');
        $subject = (string) $input->getOption('subject');
        $body = (string) $input->getOption('body');

        $email = (new Email())
            ->from(new Address($this->appSettingsService->getMailFromAddress(), $this->appSettingsService->getMailFromName()))
            ->to($to)
            ->subject($subject)
            ->text($body);

        $this->mailer->send($email);

        $output->writeln(sprintf('Sent test email to %s.', $to));

        return Command::SUCCESS;
    }
}
