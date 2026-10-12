$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$phpExecutable = Join-Path $projectRoot '.tools\php\php.exe'
$phpConfig = Join-Path $projectRoot '.tools\php\php.ini'

if (-not (Test-Path -LiteralPath $phpExecutable)) {
    throw 'PHP portátil não encontrado em .tools/php. Consulte o README.'
}

& $phpExecutable -c $phpConfig @args
exit $LASTEXITCODE

