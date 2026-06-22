<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Enum;

enum MusicbotTeamspeakBackendStatus: string
{
    case NotConfigured = 'not_configured';
    case BinaryMissing = 'binary_missing';
    case BinaryNotExecutable = 'binary_not_executable';
    case LibraryMissing = 'library_missing';
    case OpusMissing = 'opus_missing';
    case IdentityMissing = 'identity_missing';
    case InvalidPermissions = 'invalid_permissions';
    case ClientBackendRequired = 'client_backend_required';
    case Ready = 'ready';
    case Connected = 'connected';
    case Failed = 'failed';
    case OfficialClientNotInstalled = 'official_client_not_installed';
    case OfficialClientDownloadFailed = 'official_client_download_failed';
    case OfficialClientChecksumFailed = 'official_client_checksum_failed';
    case OfficialClientInstalled = 'official_client_installed';
    case OfficialClientInstalledLibraryMissing = 'official_client_installed_library_missing';
    case OfficialClientInvalid = 'official_client_invalid';
    case OfficialClientReady = 'official_client_ready';
    case SdkClientNotInstalled = 'sdk_client_not_installed';
    case SdkClientDownloadFailed = 'sdk_client_download_failed';
    case SdkClientChecksumFailed = 'sdk_client_checksum_failed';
    case SdkClientInstalled = 'sdk_client_installed';
    case SdkClientInstalledLibraryMissing = 'sdk_client_installed_library_missing';
    case SdkClientInvalid = 'sdk_client_invalid';
}
