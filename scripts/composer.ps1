$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$phpExecutable = Join-Path $projectRoot '.tools\php\php.exe'
$phpConfig = Join-Path $projectRoot '.tools\php\php.ini'
$composer = Join-Path $projectRoot '.tools\composer.phar'

if (-not (Test-Path -LiteralPath $phpExecutable)) {
    throw 'PHP portátil não encontrado em .tools/php. Consulte o README.'
}

if (-not (Test-Path -LiteralPath $composer)) {
    throw 'Composer portátil não encontrado em .tools/composer.phar. Consulte o README.'
}

& $phpExecutable -c $phpConfig $composer @args
exit $LASTEXITCODE

