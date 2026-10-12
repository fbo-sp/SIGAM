param(
    [switch] $Volumes
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$compose = Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker-compose.exe'
$arguments = @('--project-directory', $projectRoot, '-f', (Join-Path $projectRoot 'compose.yaml'), 'down')

if ($Volumes) {
    $arguments += '--volumes'
}

& $compose @arguments
exit $LASTEXITCODE

