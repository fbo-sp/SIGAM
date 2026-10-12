$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$compose = Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker-compose.exe'

if (-not (Test-Path -LiteralPath $compose)) {
    throw 'Docker Compose não encontrado. Inicie ou reinstale o Docker Desktop.'
}

& $compose --project-directory $projectRoot -f (Join-Path $projectRoot 'compose.yaml') up --detach --build
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& $compose --project-directory $projectRoot -f (Join-Path $projectRoot 'compose.yaml') ps
exit $LASTEXITCODE

