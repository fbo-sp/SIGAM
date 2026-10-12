#!/usr/bin/env sh
# Shared-host backup; does not read .env or place secrets on the command line.
set -eu
umask 077
if [ "$#" -ne 5 ] || [ "$5" != "--writers-stopped" ]; then
    echo "Uso: sh backup.sh /projeto /backup-novo /mysql-client.cnf banco --writers-stopped" >&2
    exit 2
fi
project=$(cd "$1" && pwd -P)
destination=$2
defaults=$3
database=$4
case "$database" in ''|*[!a-zA-Z0-9_]*) echo "Nome de banco inválido." >&2; exit 2;; esac
case "$destination" in /*) ;; *) echo "Destino deve ser absoluto." >&2; exit 2;; esac
[ ! -e "$destination" ] || { echo "Destino já existe; nada será sobrescrito." >&2; exit 2; }
parent=$(cd "$(dirname "$destination")" && pwd -P)
case "$parent/" in "$project/"*) echo "Backup deve ficar fora do projeto e da raiz pública." >&2; exit 2;; esac
[ -r "$defaults" ] && [ -f "$project/artisan" ] && [ -d "$project/storage/app/private" ]
for tool in php mysqldump gzip tar sha256sum; do command -v "$tool" >/dev/null; done
mkdir "$destination"
entered_maintenance=false
finish() {
    if [ "$entered_maintenance" = true ]; then
        (cd "$project" && php artisan up)
    fi
}
trap finish EXIT
if [ ! -f "$project/storage/framework/down" ]; then
    (cd "$project" && php artisan down --retry=60)
    entered_maintenance=true
fi
# Caller must stop cron and every other writer before invoking this script.
# Avoid a pipe: a failed mysqldump must not be hidden by successful gzip.
mysqldump --defaults-extra-file="$defaults" --single-transaction --quick --no-tablespaces --hex-blob "$database" > "$destination/database.sql"
gzip "$destination/database.sql"
tar -czf "$destination/private-files.tar.gz" -C "$project/storage/app" private
(cd "$destination" && sha256sum database.sql.gz private-files.tar.gz > SHA256SUMS && sha256sum -c SHA256SUMS)
echo "Backup criado. Copie para destino externo criptografado; APP_KEY fica em cofre separado."
