<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Repository\TemplateRepository;
use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\ScalarValue;
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

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $templates = $this->templateRepository->findAll();
        $errors = [];

        foreach ($templates as $template) {
            $gameKey = $template->getGameKey();

            $supportedOs = $template->getSupportedOs();
            if ($supportedOs === []) {
                $errors[] = sprintf('%s: supported_os is missing.', $gameKey);
            } else {
                $invalidOs = self::invalidOsValues($supportedOs);
                if ($invalidOs !== []) {
                    $errors[] = sprintf('%s: supported_os has invalid values (%s).', $gameKey, implode(', ', $invalidOs));
                }
            }

            $portProfile = $template->getPortProfile();
            if ($portProfile === []) {
                $errors[] = sprintf('%s: port_profile is missing.', $gameKey);
            } else {
                foreach (array_keys($portProfile) as $index) {
                    $entry = JsonArray::fromValue($portProfile[$index]);
                    if ($entry === null) {
                        $errors[] = sprintf('%s: port_profile entry %s must be an object.', $gameKey, self::entryPosition($index));
                        continue;
                    }
                    $role = ScalarValue::text($entry['role'] ?? '');
                    $protocol = ScalarValue::text($entry['protocol'] ?? '');
                    $validCount = self::validPortCount($entry['count'] ?? null);
                    if ($role === '' || $protocol === '') {
                        $errors[] = sprintf('%s: port_profile entry %s missing role/protocol.', $gameKey, self::entryPosition($index));
                    }
                    if (!$validCount) {
                        $errors[] = sprintf('%s: port_profile entry %s count must be >= 1.', $gameKey, self::entryPosition($index));
                    }
                    if (!array_key_exists('required', $entry) || !is_bool($entry['required'])) {
                        $errors[] = sprintf('%s: port_profile entry %s required flag invalid.', $gameKey, self::entryPosition($index));
                    }
                    if (!array_key_exists('contiguous', $entry) || !is_bool($entry['contiguous'])) {
                        $errors[] = sprintf('%s: port_profile entry %s contiguous flag invalid.', $gameKey, self::entryPosition($index));
                    }
                }
            }

            $requirements = $template->getRequirements();
            if ($requirements === []) {
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
                    $list = JsonArray::fromValue($requirements[$listKey] ?? null);
                    if ($list === null) {
                        $errors[] = sprintf('%s: requirements %s must be an array.', $gameKey, $listKey);
                        continue;
                    }
                    foreach (array_keys($list) as $key) {
                        if (!self::nonEmptyText($list[$key])) {
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
    private static function entryPosition(int|string $index): int|string
    {
        return is_int($index) ? $index + 1 : $index;
    }

    private static function validPortCount(mixed $value): bool
    {
        return is_int($value) && $value >= 1;
    }

    private static function nonEmptyText(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private static function invalidOsValues(array $values): array
    {
        $invalid = [];
        foreach (array_keys($values) as $key) {
            $os = ScalarValue::text($values[$key], get_debug_type($values[$key]));
            if (!in_array($os, ['linux', 'windows'], true)) {
                $invalid[] = $os;
            }
        }

        return $invalid;
    }

}
