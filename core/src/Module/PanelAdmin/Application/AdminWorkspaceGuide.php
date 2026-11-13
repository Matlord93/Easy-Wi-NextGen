<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\Application;

use Symfony\Component\Routing\RouterInterface;

/** Navigation help only: never mutates configuration or claims service health. */
final readonly class AdminWorkspaceGuide
{
    private const GUIDES = [
        [
            'key' => 'general',
            'aliases' => ['settings', 'modules', 'profile', 'updates', 'notifications', 'changelog', 'getting-started'],
            'module' => null,
            'steps' => [
                ['route' => 'admin_settings'],
                ['route' => 'admin_modules'],
                ['route' => 'admin_updates'],
            ],
        ],
        [
            'key' => 'customers',
            'aliases' => ['users', 'roles'],
            'module' => null,
            'steps' => [
                ['route' => 'admin_users'],
                ['route' => 'admin_instances'],
                ['route' => 'admin_audit_logs'],
            ],
        ],
        [
            'key' => 'nodes',
            'aliases' => ['nodes', 'bootstrap-tokens', 'port-pools'],
            'module' => null,
            'steps' => [
                ['route' => 'admin_agent_bootstrap_tokens'],
                ['route' => 'admin_nodes'],
                ['route' => 'admin_port_pools'],
            ],
        ],
        [
            'key' => 'games',
            'aliases' => ['game-instances', 'templates', 'game-profiles'],
            'module' => 'game',
            'steps' => [
                ['route' => 'admin_nodes'],
                ['route' => 'admin_templates'],
                ['route' => 'admin_instances'],
            ],
        ],
        [
            'key' => 'minecraft',
            'aliases' => ['minecraft', 'minecraft-versions'],
            'module' => 'game',
            'steps' => [
                ['route' => 'admin_nodes'],
                ['route' => 'admin_minecraft'],
                ['route' => 'admin_instances'],
            ],
        ],
        [
            'key' => 'plugins',
            'aliases' => ['plugins'],
            'module' => 'game',
            'steps' => [
                ['route' => 'admin_plugins'],
                ['route' => 'admin_instances'],
                ['route' => 'admin_jobs'],
            ],
        ],
        [
            'key' => 'proxmox',
            'aliases' => ['proxmox'],
            'module' => null,
            'steps' => [
                ['route' => 'admin_proxmox'],
                ['route' => 'admin_proxmox'],
                ['route' => 'admin_proxmox'],
            ],
        ],
        [
            'key' => 'kvm',
            'aliases' => ['kvm'],
            'module' => null,
            'steps' => [
                ['route' => 'admin_nodes'],
                ['route' => 'admin_kvm'],
                ['route' => 'admin_kvm'],
            ],
        ],
        [
            'key' => 'voice',
            'aliases' => ['ts-nodes', 'ts3-servers', 'ts6-servers', 'voice', 'voice-profiles'],
            'module' => null,
            'steps' => [
                ['route' => 'admin_ts_nodes_index'],
                ['route' => 'admin_ts3_servers_index'],
                ['route' => 'admin_jobs'],
            ],
        ],
        [
            'key' => 'music',
            'aliases' => ['musicbots', 'sinusbot'],
            'module' => null,
            'steps' => [
                ['route' => 'admin_musicbot_index'],
                ['route' => 'admin_musicbot_teamspeak_backend'],
                ['route' => 'admin_jobs'],
            ],
        ],
        [
            'key' => 'web',
            'aliases' => ['webspaces'],
            'module' => 'web',
            'steps' => [
                ['route' => 'admin_nodes'],
                ['route' => 'admin_webspaces'],
                ['route' => 'admin_dns_records'],
            ],
        ],
        [
            'key' => 'databases',
            'aliases' => ['databases'],
            'module' => 'web',
            'steps' => [
                ['route' => 'admin_nodes'],
                ['route' => 'admin_databases'],
                ['route' => 'admin_jobs'],
            ],
        ],
        [
            'key' => 'mail',
            'aliases' => ['mail-system', 'mailboxes', 'mail-aliases', 'mail-health', 'mail-matrix'],
            'module' => 'mail',
            'steps' => [
                ['route' => 'admin_mail_system'],
                ['route' => 'admin_mailboxes'],
                ['route' => 'admin_mail_health'],
            ],
        ],
        [
            'key' => 'dns',
            'aliases' => ['dns-records', 'domains'],
            'module' => 'dns',
            'steps' => [
                ['route' => 'admin_dns_records'],
                ['route' => 'admin_dns_records'],
                ['route' => 'admin_jobs'],
            ],
        ],
        [
            'key' => 'billing',
            'aliases' => ['billing'],
            'module' => 'billing',
            'steps' => [
                ['route' => 'admin_billing_settings'],
                ['route' => 'admin_billing_invoice_new'],
                ['route' => 'admin_billing'],
            ],
        ],
        [
            'key' => 'shop',
            'aliases' => ['shop-products', 'shop-categories', 'shop-settings', 'shop-orders'],
            'module' => 'shop',
            'steps' => [
                ['route' => 'admin_shop_categories'],
                ['route' => 'admin_shop_products'],
                ['route' => 'admin_billing_settings'],
            ],
        ],
        [
            'key' => 'cms',
            'aliases' => ['cms-pages', 'cms-blog', 'cms-events', 'cms-team', 'cms-media', 'cms-settings', 'cms-forum', 'cms-contact', 'public-servers'],
            'module' => 'cms',
            'steps' => [
                ['route' => 'admin_cms_settings'],
                ['route' => 'admin_cms_pages'],
                ['route' => 'admin_cms_media_index'],
            ],
        ],
        [
            'key' => 'backups',
            'aliases' => ['backups', 'backup-targets', 'backup-settings'],
            'module' => null,
            'steps' => [
                ['route' => 'admin_backups_targets'],
                ['route' => 'admin_settings_backups'],
                ['route' => 'admin_backups_overview'],
            ],
        ],
        [
            'key' => 'security',
            'aliases' => ['security', 'firewall', 'ddos', 'gdpr-overview', 'gdpr-retention'],
            'module' => null,
            'steps' => [
                ['route' => 'admin_security'],
                ['route' => 'admin_users'],
                ['route' => 'admin_audit_logs'],
            ],
        ],
        [
            'key' => 'operations',
            'aliases' => ['jobs', 'schedules', 'metrics', 'activity', 'audit-logs', 'diagnostics'],
            'module' => null,
            'steps' => [
                ['route' => 'admin_jobs'],
                ['route' => 'admin_schedules'],
                ['route' => 'admin_audit_logs'],
            ],
        ],
        [
            'key' => 'support',
            'aliases' => ['tickets', 'docs', 'downloads'],
            'module' => null,
            'steps' => [
                ['route' => 'admin_tickets'],
                ['route' => 'admin_jobs'],
                ['route' => 'admin_docs'],
            ],
        ],
        [
            'key' => 'integrations',
            'aliases' => ['unifi', 'marketplace', 'extensions'],
            'module' => null,
            'steps' => [
                ['route' => 'admin_modules'],
                ['route' => 'admin_settings'],
                ['route' => 'admin_jobs'],
            ],
        ],
    ];

    public function __construct(private RouterInterface $router)
    {
    }

    public function context(string $activeNav): array
    {
        foreach (self::GUIDES as $guide) {
            if (in_array($activeNav, $guide['aliases'], true)) {
                if ($activeNav === 'ts6-servers') {
                    $guide['steps'][0]['route'] = 'admin_ts6_nodes_index';
                    $guide['steps'][1]['route'] = 'admin_ts6_servers_index';
                } elseif ($activeNav === 'sinusbot') {
                    $guide['steps'][0]['route'] = 'admin_sinusbot_nodes_index';
                    $guide['steps'][1]['route'] = 'admin_sinusbot_nodes_index';
                }

                return $this->present($guide);
            }
        }

        return $this->present(self::GUIDES[0]);
    }

    public function all(): array
    {
        return array_map($this->present(...), self::GUIDES);
    }

    private function present(array $guide): array
    {
        foreach ($guide['steps'] as $index => &$step) {
            $route = $this->router->getRouteCollection()->get($step['route']);
            $step['href'] = $route ? $this->router->generate($step['route']) : null;
            $step['key'] = 'workspace.guide.'.$guide['key'].'.step.'.($index + 1);
        }

        return $guide;
    }
}
