<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\Exception\RemoteFileException;
use App\Module\Core\Application\FileServiceClient;
use App\Module\Core\Attribute\RequiresModule;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Application\Configuration\EnshroudedBanList;
use App\Module\Gameserver\Application\Configuration\EnshroudedConfiguration;
use App\Module\Gameserver\Application\Configuration\EnshroudedRoleConfiguration;
use App\Module\Gameserver\Application\Configuration\EnshroudedServerTags;
use App\Module\Gameserver\Application\Configuration\GameConfigDocument;
use App\Module\Gameserver\Application\Configuration\GameConfigurationAdditions;
use App\Module\Gameserver\Application\Configuration\GameConfigurationFields;
use App\Module\Gameserver\Application\Configuration\GameSetupProfiles;
use App\Module\Gameserver\Application\Configuration\SourceServerSetup;
use App\Module\Gameserver\Application\Configuration\StructuredGameConfiguration;
use App\Module\Gameserver\Application\Configuration\VRisingGameConfiguration;
use App\Module\Gameserver\Application\Configuration\VRisingHostConfiguration;
use App\Module\Gameserver\Application\Configuration\VRisingProgressionConfiguration;
use App\Module\Gameserver\Application\MinecraftConfigurationPolicy;
use App\Repository\InstanceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[RequiresModule('game')]
final readonly class CustomerGameConfigurationController
{
    public function __construct(private InstanceRepositoryInterface $instances, private StructuredGameConfiguration $configuration, private FileServiceClient $files, private CsrfTokenManagerInterface $csrf, private EntityManagerInterface $em)
    {
    }

    #[Route('/api/v1/customer/instances/{id<\d+>}/configuration', name: 'customer_game_configuration', methods: ['GET', 'PUT', 'POST'])]
    public function configuration(Request $request, int $id): JsonResponse
    {
        $customer = RequestActor::user($request);
        if (null === $customer || !$customer->isCustomer()) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $instance = $this->instances->find($id);
        if (!$instance instanceof Instance || $instance->getCustomer()->getId() !== $customer->getId()) {
            return new JsonResponse(['message' => 'Not found.'], 404);
        }
        if (!$request->isMethod('GET') && !$this->csrf->isTokenValid(new CsrfToken('game-config-'.$id, $request->headers->get('X-CSRF-TOKEN', '')))) {
            return new JsonResponse(['message' => 'Ungültiger Formular-Token. Bitte die Seite neu laden.'], 403);
        }
        $path = '';
        $definitions = [];
        try {
            $definitions = $this->configuration->files($instance);
            $input = !$request->isMethod('GET') ? JsonMap::fromValue($request->toArray()) ?? [] : [];
            $text = JsonMap::strings($input);
            $path = !$request->isMethod('GET') ? ($text['file'] ?? '') : $request->query->getString('file');
            if ('' === $path && !$request->isMethod('GET')) {
                return new JsonResponse(['message' => 'Bitte eine Konfigurationsdatei wählen.'], 422);
            }
            if ('' === $path) {
                return new JsonResponse(['profile' => GameSetupProfiles::summary($instance->getTemplate()->getGameKey()), 'files' => array_map(static fn (string $key, array $file): array => ['path' => $key, 'label' => $file['label']], array_keys($definitions), array_values($definitions)), 'booked_slots' => $instance->getMaxSlots()]);
            }
            $definition = $definitions[$path] ?? null;
            if (null === $definition) {
                return new JsonResponse(['message' => 'Diese Konfigurationsdatei gehört nicht zum gewählten Spiel.'], 404);
            }
            if ($request->isMethod('POST')) {
                if (VRisingHostConfiguration::canInitialize($instance, $path) || VRisingGameConfiguration::canInitialize($instance, $path)) {
                    if (true !== ($input['initialize_confirmed'] ?? false)) {
                        return new JsonResponse(['message' => 'Bitte das Anlegen der V-Rising-Konfiguration im aktuellen Setup ausdrücklich bestätigen.'], 422);
                    }
                    $initial = VRisingHostConfiguration::canInitialize($instance, $path) ? VRisingHostConfiguration::initialConfig($instance, $path) : VRisingGameConfiguration::initialConfig($instance, $path);
                    $directory = dirname($path);
                    if ('.' !== $directory) {
                        $parent = dirname($directory);
                        $this->files->makeDirectory($instance, '.' === $parent ? '' : $parent, basename($directory));
                    }
                } elseif (SourceServerSetup::supports($instance->getTemplate()) && 'server.cfg' === strtolower(basename($path))) {
                    $initial = SourceServerSetup::initialConfig($instance);
                } else {
                    return new JsonResponse(['message' => 'Für diese Datei ist keine Standardkonfiguration vorgesehen.'], 422);
                }
                $created = $this->files->createFileContent($instance, $path, $initial);
                if (!$created['saved'] || '' === $created['new_etag']) {
                    return new JsonResponse(['message' => 'Der Agent hat das Anlegen nicht bestätigt. Bitte den Agenten aktualisieren und erneut versuchen.'], 502);
                }

                if (VRisingHostConfiguration::canInitialize($instance, $path)) {
                    $initialValues = GameConfigDocument::values($initial, 'json');
                    $instance->setCurrentSlots((int) $initialValues['MaxConnectedUsers']);
                }
                if (VRisingHostConfiguration::canInitialize($instance, $path) || VRisingGameConfiguration::canInitialize($instance, $path)) {
                    $overrides = $instance->getConfigOverrides();
                    unset($overrides[$path]);
                    $instance->setConfigOverrides($overrides);
                    $this->em->flush();
                }

                return new JsonResponse(['saved' => true, 'etag' => $created['new_etag'], 'message' => 'Standardkonfiguration angelegt.'], 201);
            }
            $current = $this->files->readFileContent($instance, $path);
            if ($current['is_binary'] || strlen($current['content']) > 1_048_576 || !mb_check_encoding($current['content'], 'UTF-8')) {
                return new JsonResponse(['message' => 'Diese Datei ist zu groß oder enthält kein unterstütztes Textformat.'], 422);
            }
            $fields = $this->configuration->fields($instance, $definition, $current['content']);
            $available = $this->configuration->availableFields($instance, $definition, $current['content']);
            if ($request->isMethod('GET')) {
                return new JsonResponse([
                    'file' => $path,
                    'description' => JsonMap::strings(GameSetupProfiles::file($instance->getTemplate()->getGameKey(), $path))['description'] ?? GameSetupProfiles::summary($instance->getTemplate()->getGameKey())['description'],
                    'etag' => $current['etag'],
                    'fields' => $fields,
                    'available_fields' => $available,
                    'collections' => VRisingProgressionConfiguration::describe($instance, $path, $current['content']),
                    'role_manager' => EnshroudedRoleConfiguration::describe($instance, $path, $current['content']),
                    'server_tags' => EnshroudedServerTags::describe($instance, $definition, $current['content']),
                    'ban_lists' => EnshroudedBanList::describe($instance, $path, $current['content']),
                    'analysed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'booked_slots' => $instance->getMaxSlots(),
                ]);
            }
            $etag = $text['etag'] ?? '';
            if ('' === $etag || '' === $current['etag'] || !hash_equals($current['etag'], $etag)) {
                return new JsonResponse(['message' => 'Die Datei wurde zwischenzeitlich geändert. Bitte neu analysieren; deine Eingaben bleiben erhalten.'], 409);
            }
            $values = JsonMap::fromValue($input['values'] ?? null);
            if (null === $values) {
                return new JsonResponse(['message' => 'Ungültige Konfigurationswerte.'], 422);
            }
            $changes = $this->configuration->validate(array_merge($fields, $available), $values);
            $prepared = GameConfigurationAdditions::prepare($current['content'], $definition['format'], $changes, $available);
            $content = GameConfigDocument::patch($prepared, $definition['format'], $changes);
            $content = VRisingProgressionConfiguration::patch($instance, $path, $content, array_key_exists('list_changes', $input) ? $input['list_changes'] : []);
            $content = EnshroudedRoleConfiguration::patch($instance, $path, $current['content'], $content, array_key_exists('role_changes', $input) ? $input['role_changes'] : []);
            $content = EnshroudedServerTags::patch($instance, $definition, $content, array_key_exists('tag_changes', $input) ? $input['tag_changes'] : []);
            MinecraftConfigurationPolicy::assertEditable($instance, $path, $current['content'], $content, true);
            EnshroudedConfiguration::assertSetupChange($instance, $path, $current['content'], $content);
            // Conditional agent write prevents overwriting a newer edit between read and save.
            $saved = $this->files->writeFileContent($instance, $path, $content, $etag);
            if (!$saved['saved']) {
                return new JsonResponse(['message' => 'Der Agent hat das Speichern nicht bestätigt.'], 502);
            }
            $setupVars = $instance->getSetupVars();
            foreach ($changes as $key => $value) {
                if (GameConfigurationFields::slots($key)) {
                    $instance->setCurrentSlots((int) $value);
                    foreach (['MAX_PLAYERS', 'MAXPLAYERS', 'SLOTS', 'SV_MAXPLAYERS'] as $variable) {
                        if (array_key_exists($variable, $setupVars)) {
                            $setupVars[$variable] = $value;
                        }
                    }
                }
            }
            foreach ($changes as $key => $value) {
                $metadata = JsonMap::strings(GameSetupProfiles::field($instance->getTemplate()->getGameKey(), $path, $key));
                $variable = $metadata['startup_var'] ?? '';
                if (in_array($definition['format'], ['json', 'jsonc'], true) && str_contains($key, '.') && isset($metadata['write_key']) && 0 !== strcasecmp($metadata['write_key'], $key)) {
                    continue;
                }
                $allowed = array_merge(array_column($instance->getTemplate()->getEnvVars(), 'key'), array_keys(SourceServerSetup::fields($instance->getTemplate())));
                if ('' !== $variable && in_array($variable, $allowed, true) && !GameSetupProfiles::isAdminOnly($instance->getTemplate(), $variable)) {
                    $setupVars[$variable] = $value;
                    if ('SERVER_NAME' === $variable) {
                        $instance->setServerName($value);
                    }
                }
            }
            $instance->setSetupVars($setupVars);
            // The remote file is authoritative. An old panel snapshot must not
            // overwrite a later file-manager edit on the next start.
            $overrides = $instance->getConfigOverrides();
            unset($overrides[$path]);
            $instance->setConfigOverrides($overrides);
            $this->em->flush();

            $refresh = EnshroudedConfiguration::supports($instance, $path) ? ['fields' => $this->configuration->fields($instance, $definition, $content)] : [];

            return new JsonResponse([
                'saved' => $saved['saved'],
                'etag' => $saved['new_etag'],
                'restart_required' => true,
                'collections' => VRisingProgressionConfiguration::describe($instance, $path, $content),
                ...$refresh,
                'role_manager' => EnshroudedRoleConfiguration::describe($instance, $path, $content),
                'server_tags' => EnshroudedServerTags::describe($instance, $definition, $content),
                'ban_lists' => EnshroudedBanList::describe($instance, $path, $content),
                'message' => 'Gespeichert. Die Änderungen werden beim nächsten Serverneustart übernommen.',
            ]);
        } catch (RemoteFileException $exception) {
            $host = VRisingHostConfiguration::canInitialize($instance, $path);
            $gameRules = VRisingGameConfiguration::canInitialize($instance, $path);
            $canInitialize = $request->isMethod('GET') && 404 === $exception->getStatusCode() && isset($path, $definitions[$path]) && ($host || $gameRules || (SourceServerSetup::supports($instance->getTemplate()) && 'server.cfg' === strtolower(basename($path))));
            $message = $request->isMethod('POST') && in_array($exception->getStatusCode(), [400, 404, 405], true) ? 'Die Datei konnte nicht angelegt werden. Bitte den Agenten aktualisieren und den Konfigurationspfad prüfen.' : ($canInitialize ? 'Diese Konfigurationsdatei ist noch nicht vorhanden. Du kannst eine Standardkonfiguration anlegen.' : (409 === $exception->getStatusCode() ? 'Die Datei wurde inzwischen geändert oder ist bereits vorhanden. Bitte neu analysieren.' : 'Die Konfigurationsdatei ist am Agenten gerade nicht erreichbar. Bitte erneut versuchen.'));

            if ($canInitialize && $host) {
                $message = 'Diese Host-Datei fehlt. Das Anlegen übernimmt das Panel-Spielerlimit (höchstens 128) ohne zusätzliche Adminreserve; andere Werte bleiben Spielvorgaben. Erst der nächste Serverstart übernimmt die Datei. Vorhandene Dateien werden nicht überschrieben.';
            }

            if ($canInitialize && $gameRules) {
                $message = 'Diese Spielregel-Datei fehlt. Auf Bestätigung wird eine leere Override-Datei angelegt. Spielvorgaben und aktive Vorlagen bleiben erhalten, bis du einzelne Werte speicherst. Bestehende Dateien werden nicht überschrieben; eine vorhandene Welt wird nicht zurückgesetzt.';
            }

            return new JsonResponse(['message' => $message, 'can_initialize' => $canInitialize, 'initialize_automatically' => !$host && !$gameRules, 'error_code' => $exception->getErrorCode()], $canInitialize ? 200 : min(599, max(400, $exception->getStatusCode())));
        } catch (\InvalidArgumentException|\JsonException|\Symfony\Component\HttpFoundation\Exception\JsonException|\Symfony\Component\Yaml\Exception\ParseException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 422);
        }
    }
}
