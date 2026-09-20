<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum Permission: string
{
    case BillingRead = 'billing.read';
    case BillingManage = 'billing.manage';
    case NodesRead = 'nodes.read';
    case NodesManage = 'nodes.manage';
    case DockerManage = 'docker.manage';
    case KvmManage = 'kvm.manage';
    case ShopRead = 'shop.read';
    case ShopManage = 'shop.manage';
    case UsersRead = 'users.read';
    case UsersManage = 'users.manage';
    case AuditRead = 'audit.read';
    case SecurityManage = 'security.manage';
    case WebhooksManage = 'webhooks.manage';
}
