<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Command;

use App\Module\Gameserver\Domain\Entity\GameProfile;
use App\Infrastructure\Config\JsonMap;
use App\Module\Gameserver\Domain\Enum\EnforceMode;
use App\Module\Gameserver\Infrastructure\Repository\GameProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'app:gameserver:seed-profiles', description: 'Seed gameserver profiles from YAML config.')]
final class GameProfileSeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GameProfileRepository $gameProfileRepository,
        private readonly string $configPath = __DIR__ . '/../../../../config/gameserver/game_profiles.yaml',
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!is_file($this->configPath)) {
            $output->writeln('<error>Config file not found.</error>');
            return Command::FAILURE;
        }

        $raw = JsonMap::fromValue(Yaml::parseFile($this->configPath));
        $profiles = JsonMap::fromValue($raw['profiles'] ?? []);
        if ($profiles === null) {
            $output->writeln('<error>Profiles section missing.</error>');
            return Command::FAILURE;
        }

        $resolved = $this->resolveProfiles($profiles);
        foreach ($resolved as $gameKey => $definition) {
            $enforcePorts = EnforceMode::from((string) ($definition['enforce_mode_ports'] ?? EnforceMode::EnforceByConfig->value));
            $enforceSlots = EnforceMode::from((string) ($definition['enforce_mode_slots'] ?? EnforceMode::EnforceByConfig->value));
            $portRoles = self::portRoles($definition['port_roles'] ?? []);
            $slotRule = JsonMap::fromValue($definition['slot_rule'] ?? [])
                ?? throw new \RuntimeException(sprintf('Profile %s slot_rule must be an object.', $gameKey));

            $profile = $this->gameProfileRepository->findOneByGameKey($gameKey);
            if ($profile === null) {
                $profile = new GameProfile($gameKey, $enforcePorts, $enforceSlots, $portRoles, $slotRule);
            } else {
                $profile->setEnforceModePorts($enforcePorts);
                $profile->setEnforceModeSlots($enforceSlots);
                $profile->setPortRoles($portRoles);
                $profile->setSlotRules($slotRule);
            }

            $this->entityManager->persist($profile);
        }

        $this->entityManager->flush();
        $output->writeln(sprintf('<info>Seeded %d game profiles.</info>', count($resolved)));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $profiles
     * @return array<string, array<string, mixed>>
     */
    private function resolveProfiles(array $profiles): array
    {
        $resolved = [];
        foreach (array_keys($profiles) as $gameKey) {
            $resolved[$gameKey] = $this->resolveProfile($gameKey, $profiles, []);
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $profiles
     * @param array<string, bool> $stack
     * @return array<string, mixed>
     */
    private function resolveProfile(string $gameKey, array $profiles, array $stack): array
    {
        if (isset($stack[$gameKey])) {
            throw new \RuntimeException(sprintf('Circular inherit detected for %s', $gameKey));
        }
        $definition = JsonMap::fromValue($profiles[$gameKey] ?? null);
        if ($definition === null) {
            throw new \RuntimeException(sprintf('Profile %s not defined', $gameKey));
        }

        $inherit = self::optionalText($definition['inherit'] ?? null);
        if (is_string($inherit) && isset($profiles[$inherit])) {
            $stack[$gameKey] = true;
            $base = $this->resolveProfile($inherit, $profiles, $stack);
            unset($definition['inherit']);

            return JsonMap::fromValue(array_replace_recursive($base, $definition)) ?? [];
        }

        unset($definition['inherit']);

        return $definition;
    }
    private static function optionalText(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** @return array<int, array<string, mixed>> */
    private static function portRoles(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException('Profile port_roles must be an array.');
        }

        $roles = [];
        foreach (array_keys($value) as $key) {
            if (!is_int($key)) {
                throw new \RuntimeException('Profile port_roles must use integer keys.');
            }
            $roles[$key] = JsonMap::fromValue($value[$key])
                ?? throw new \RuntimeException('Profile port_roles entries must be objects.');
        }

        return $roles;
    }

}
