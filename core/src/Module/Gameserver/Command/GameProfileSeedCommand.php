<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Command;

use App\Module\Gameserver\Domain\Entity\GameProfile;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!is_file($this->configPath)) {
            $output->writeln('<error>Config file not found.</error>');
            return Command::FAILURE;
        }

        $raw = Yaml::parseFile($this->configPath);
        $profiles = $raw['profiles'] ?? [];
        if (!is_array($profiles)) {
            $output->writeln('<error>Profiles section missing.</error>');
            return Command::FAILURE;
        }

        $resolved = $this->resolveProfiles($profiles);
        foreach ($resolved as $gameKey => $definition) {
            $enforcePorts = EnforceMode::from((string) ($definition['enforce_mode_ports'] ?? EnforceMode::EnforceByConfig->value));
            $enforceSlots = EnforceMode::from((string) ($definition['enforce_mode_slots'] ?? EnforceMode::EnforceByConfig->value));
            $portRoles = $definition['port_roles'] ?? [];
            $slotRule = $definition['slot_rule'] ?? [];

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
        foreach ($profiles as $gameKey => $definition) {
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
        $definition = $profiles[$gameKey] ?? null;
        if (!is_array($definition)) {
            throw new \RuntimeException(sprintf('Profile %s not defined', $gameKey));
        }

        $inherit = $definition['inherit'] ?? null;
        if (is_string($inherit) && isset($profiles[$inherit])) {
            $stack[$gameKey] = true;
            $base = $this->resolveProfile($inherit, $profiles, $stack);
            unset($definition['inherit']);

            return array_replace_recursive($base, $definition);
        }

        unset($definition['inherit']);

        return $definition;
    }
}
