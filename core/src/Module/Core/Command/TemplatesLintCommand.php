<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Repository\TemplateRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'templates:lint',
    description: 'Validate template metadata like OS support and port profiles.',
)]
final class TemplatesLintCommand extends Command
{
    public function __construct(
        private readonly TemplateRepository $templateRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $templates = $this->templateRepository->findAll();
        $errors = [];

        foreach ($templates as $template) {
            $gameKey = $template->getGameKey();

            $supportedOs = $template->getSupportedOs();
            if (!is_array($supportedOs) || $supportedOs === []) {
                $errors[] = sprintf('%s: supported_os is missing.', $gameKey);
            } else {
                $invalidOs = array_diff($supportedOs, ['linux', 'windows']);
                if ($invalidOs !== []) {
                    $errors[] = sprintf('%s: supported_os has invalid values (%s).', $gameKey, implode(', ', $invalidOs));
                }
            }

            $portProfile = $template->getPortProfile();
            if (!is_array($portProfile) || $portProfile === []) {
                $errors[] = sprintf('%s: port_profile is missing.', $gameKey);
            } else {
                foreach ($portProfile as $index => $entry) {
                    if (!is_array($entry)) {
                        $errors[] = sprintf('%s: port_profile entry %d must be an object.', $gameKey, $index + 1);
                        continue;
                    }
                    $role = (string) ($entry['role'] ?? '');
                    $protocol = (string) ($entry['protocol'] ?? '');
                    $count = $entry['count'] ?? null;
                    if ($role === '' || $protocol === '') {
                        $errors[] = sprintf('%s: port_profile entry %d missing role/protocol.', $gameKey, $index + 1);
                    }
                    if (!is_int($count) || $count < 1) {
                        $errors[] = sprintf('%s: port_profile entry %d count must be >= 1.', $gameKey, $index + 1);
                    }
                    if (!array_key_exists('required', $entry) || !is_bool($entry['required'])) {
                        $errors[] = sprintf('%s: port_profile entry %d required flag invalid.', $gameKey, $index + 1);
                    }
                    if (!array_key_exists('contiguous', $entry) || !is_bool($entry['contiguous'])) {
                        $errors[] = sprintf('%s: port_profile entry %d contiguous flag invalid.', $gameKey, $index + 1);
                    }
                }
            }

            $requirements = $template->getRequirements();
            if (!is_array($requirements) || $requirements === []) {
                $errors[] = sprintf('%s: requirements are missing.', $gameKey);
            } else {
                $requiredKeys = [
                    'required_vars',
                    'required_secrets',
                    'steam_install_mode',
                    'customer_allowed_vars',
                    'customer_allowed_secrets',
                ];
                foreach ($requiredKeys as $key) {
                    if (!array_key_exists($key, $requirements)) {
                        $errors[] = sprintf('%s: requirements missing %s.', $gameKey, $key);
                        continue;
                    }
                }

                $steamInstallMode = (string) ($requirements['steam_install_mode'] ?? '');
                if ($steamInstallMode === '' || !in_array($steamInstallMode, ['anonymous', 'none'], true)) {
                    $errors[] = sprintf('%s: requirements steam_install_mode invalid.', $gameKey);
                }

                foreach (['required_vars', 'required_secrets', 'customer_allowed_vars', 'customer_allowed_secrets'] as $listKey) {
                    $list = $requirements[$listKey] ?? null;
                    if (!is_array($list)) {
                        $errors[] = sprintf('%s: requirements %s must be an array.', $gameKey, $listKey);
                        continue;
                    }
                    foreach ($list as $value) {
                        if (!is_string($value) || $value === '') {
                            $errors[] = sprintf('%s: requirements %s entries must be non-empty strings.', $gameKey, $listKey);
                            break;
                        }
                    }
                }
            }
        }

        if ($errors !== []) {
            $output->writeln('<error>Template linting failed.</error>');
            foreach ($errors as $error) {
                $output->writeln(sprintf('- %s', $error));
            }

            return Command::FAILURE;
        }

        $output->writeln('<info>Templates OK.</info>');

        return Command::SUCCESS;
    }
}
