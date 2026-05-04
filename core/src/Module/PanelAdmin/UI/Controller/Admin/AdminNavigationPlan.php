<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

/**
 * TASK-019 planning helper for the admin IA refactor.
 *
 * This class intentionally contains only constants so TASK-020 can import a
 * stable mapping without changing route names or controller contracts.
 */
final class AdminNavigationPlan
{
    /**
     * Planned top-level IA buckets for the admin experience.
     *
     * @var list<string>
     */
    public const IA_BUCKETS = [
        'nodes',
        'instances',
        'webspace',
        'mail',
        'voice',
        'security',
        'billing',
        'ops',
    ];

    /**
     * Current nav keys grouped by future IA bucket.
     *
     * @var array<string, list<string>>
     */
    public const NAV_KEY_MAP = [
        'nodes' => ['nodes', 'bootstrap-tokens', 'port-pools', 'unifi'],
        'instances' => ['game-instances', 'templates', 'plugins', 'databases', 'webspaces'],
        'webspace' => ['dns-records', 'public-servers', 'cms-pages', 'cms-media'],
        'mail' => ['mail-system', 'mailboxes', 'mail-aliases', 'mail-health', 'mail-matrix'],
        'voice' => ['ts-nodes', 'ts3-servers', 'ts6-servers', 'sinusbot'],
        'security' => ['security', 'audit-logs', 'gdpr-overview', 'gdpr-retention'],
        'billing' => ['billing', 'shop-categories', 'shop-products'],
        'ops' => [
            'dashboard',
            'activity',
            'tickets',
            'jobs',
            'metrics',
            'status-components',
            'status-maintenance',
            'status-incidents',
            'updates',
            'settings',
            'modules',
        ],
    ];

    private function __construct()
    {
    }
}
