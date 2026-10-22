#!/usr/bin/env bash
# Instala ou atualiza o JUDOMA em hospedagem compartilhada (cPanel/SSH).
# Uso, dentro da pasta da aplicação:  bash deploy/instalar.sh
# Pode ser repetido: a chave só é gerada se ainda não existir e as etapas
# seguintes são idempotentes. Nunca imprime valores do .env.
set -euo pipefail

cd "$(dirname "$0")/.."
APP_DIR="$(pwd)"

PHP="${PHP:-}"
if [ -z "$PHP" ]; then
  if [ -x /opt/cpanel/ea-php84/root/usr/bin/php ]; then
    PHP=/opt/cpanel/ea-php84/root/usr/bin/php
  else
    PHP="$(command -v php || true)"
  fi
fi

passo() { printf '\n==> %s\n' "$1"; }
falha() { printf '\nERRO: %s\n' "$1" >&2; exit 1; }

passo "Conferindo PHP ($PHP)"
[ -n "$PHP" ] && [ -x "$PHP" ] || falha "PHP não encontrado. Rode com PHP=/caminho/do/php bash deploy/instalar.sh"
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' \
  || falha "É preciso PHP 8.4.1 ou superior; este é $("$PHP" -r 'echo PHP_VERSION;')."
for ext in bcmath pdo_mysql mbstring fileinfo openssl; do
  "$PHP" -r "exit(extension_loaded('$ext') ? 0 : 1);" || falha "Extensão do PHP ausente: $ext"
done
echo "PHP $("$PHP" -r 'echo PHP_VERSION;') ok"

passo "Conferindo arquivos"
[ -f vendor/autoload.php ] || falha "Pasta vendor ausente. Use o pacote judoma-vX.Y.Z.zip da página de Releases."
[ -f .env ] || falha "Arquivo .env não encontrado em $APP_DIR. Copie .env.example para .env e preencha."
grep -Eq '^APP_ENV="?production"?\s*$' .env || falha "No .env, APP_ENV precisa ser production."
grep -Eq '^APP_DEBUG="?false"?\s*$' .env || falha "No .env, APP_DEBUG precisa ser false."
chmod 600 .env
[ -f VERSAO.txt ] && echo "Versão: $(head -n1 VERSAO.txt)"

passo "Preparando pastas graváveis"
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
  storage/framework/views storage/logs bootstrap/cache
chmod 755 storage storage/app storage/app/private storage/framework storage/framework/* storage/logs bootstrap/cache

INSTALADO=0
if "$PHP" artisan migrate:status >/dev/null 2>&1; then INSTALADO=1; fi

if [ "$INSTALADO" = 1 ]; then
  passo "Atualização: entrando em manutenção"
  "$PHP" artisan down --retry=60 || true
fi

# Sem optimize:clear: ele limpa o cache em banco, que não existe antes das migrations.
"$PHP" artisan config:clear >/dev/null
"$PHP" artisan route:clear >/dev/null
"$PHP" artisan view:clear >/dev/null

if grep -Eq '^APP_KEY=base64:.+' .env; then
  passo "Chave da aplicação já existe (mantida)"
else
  [ "$INSTALADO" = 0 ] || falha "Banco já instalado mas APP_KEY vazia. Restaure a chave original do backup; não gere outra."
  passo "Primeira instalação: gerando a chave da aplicação"
  "$PHP" artisan key:generate --force
fi

passo "Aplicando migrations"
"$PHP" artisan migrate --force

passo "Carregando dados básicos (permissões, perfis, modelos)"
"$PHP" artisan db:seed --force

passo "Liberando o acesso dos professores aos dojos em que estão vinculados"
"$PHP" artisan sigam:access:sync-teacher-dojos --apply

passo "Gerando caches"
"$PHP" artisan config:cache
"$PHP" artisan view:cache

passo "Verificação de produção"
if "$PHP" artisan sigam:production:check; then
  "$PHP" artisan up >/dev/null 2>&1 || true
  printf '\nPronto. Se o .env ainda tiver SIGAM_ADMIN_*, apague essas linhas e rode:\n  %s artisan config:cache\n' "$PHP"
else
  falha "Há itens PENDENTE acima. Corrija o .env e rode o script de novo. O site segue em manutenção se for uma atualização."
fi
