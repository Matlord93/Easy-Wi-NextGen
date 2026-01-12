param(
    [string]$ServiceName = 'easywi-agent',
    [string]$NssmPath = ''
)

$ErrorActionPreference = 'Stop'

function Resolve-NssmPath {
    if (-not [string]::IsNullOrWhiteSpace($NssmPath)) {
        return $NssmPath
    }
    $command = Get-Command nssm.exe -ErrorAction SilentlyContinue
    if ($null -ne $command) {
        return $command.Path
    }
    return $null
}

$nssm = Resolve-NssmPath
$service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($null -ne $service) {
    Stop-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
    if ($null -ne $nssm) {
        & $nssm remove $ServiceName confirm | Out-Null
    } else {
        sc.exe delete $ServiceName | Out-Null
    }
    Write-Host "[easywi-agent] Service removed: $ServiceName"
} else {
    Write-Host "[easywi-agent] Service not found: $ServiceName"
}
