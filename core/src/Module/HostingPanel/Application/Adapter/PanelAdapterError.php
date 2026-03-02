<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Application\Adapter;

enum PanelAdapterErrorCode: string
{
    case ADAPTER_UNAVAILABLE = 'ADAPTER_UNAVAILABLE';
    case ACTION_UNSUPPORTED = 'ACTION_UNSUPPORTED';
    case AUTHENTICATION_FAILED = 'AUTHENTICATION_FAILED';
    case AUTHORIZATION_FAILED = 'AUTHORIZATION_FAILED';
    case RATE_LIMITED = 'RATE_LIMITED';
    case TEMPORARY_FAILURE = 'TEMPORARY_FAILURE';
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';
}

final readonly class PanelAdapterError
{
    public function __construct(
        public PanelAdapterErrorCode $code,
        public string $message,
        public bool $retryable = false,
        public array $details = [],
    ) {
    }
}
