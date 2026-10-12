#!/usr/bin/env sh
# Only synthetic sigam_test is read. No access to the application database.
set -eu
umask 077
export MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?Credencial de teste ausente}"
source_db=sigam_test
target_db="sigam_restore_drill_$(date +%s)_$$"
case "$target_db" in sigam_restore_drill_[0-9]*_[0-9]*) ;; *) exit 2;; esac
scratch=$(mktemp -d)
started=$(date +%s)
mysql -uroot "$source_db" -e "CREATE TABLE sprint14_restore_probe (id INT PRIMARY KEY, amount DECIMAL(12,2), label VARCHAR(120)); INSERT INTO sprint14_restore_probe VALUES (1,123.45,'synthetic restore proof');"
mysqldump -uroot --single-transaction --no-tablespaces --set-gtid-purged=OFF --skip-comments "$source_db" > "$scratch/source.sql"
mysql -uroot -e "CREATE DATABASE $target_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -uroot "$target_db" < "$scratch/source.sql"
mysqldump -uroot --single-transaction --no-tablespaces --set-gtid-purged=OFF --skip-comments "$target_db" > "$scratch/restored.sql"
# MySQL may spell an implied charset explicitly after import. Normalize only
# that redundant utf8mb4 column clause; matching collation still proves charset.
# INSERT/data lines and all constraints/indexes remain byte-for-byte compared.
sed '/^  `/s/ CHARACTER SET utf8mb4 COLLATE utf8mb4_/ COLLATE utf8mb4_/g' "$scratch/source.sql" > "$scratch/source.canonical.sql"
sed '/^  `/s/ CHARACTER SET utf8mb4 COLLATE utf8mb4_/ COLLATE utf8mb4_/g' "$scratch/restored.sql" > "$scratch/restored.canonical.sql"
[ "$(sha256sum "$scratch/source.canonical.sql" | cut -d ' ' -f 1)" = "$(sha256sum "$scratch/restored.canonical.sql" | cut -d ' ' -f 1)" ]
[ "$(mysql -uroot -NB "$target_db" -e 'SELECT amount FROM sprint14_restore_probe WHERE id=1')" = "123.45" ]
mkdir "$scratch/private" "$scratch/restored"
printf '%s\n' 'Synthetic private document for restore validation.' > "$scratch/private/proof.txt"
tar -czf "$scratch/private.tar.gz" -C "$scratch" private
tar -xzf "$scratch/private.tar.gz" -C "$scratch/restored"
[ "$(sha256sum "$scratch/private/proof.txt" | cut -d ' ' -f 1)" = "$(sha256sum "$scratch/restored/private/proof.txt" | cut -d ' ' -f 1)" ]
tables=$(mysql -uroot -NB -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$target_db'")
echo "RESTORE_OK tables=$tables elapsed_seconds=$(($(date +%s) - started))"
sha256sum "$scratch/private/proof.txt"
# Only the dedicated synthetic objects just created above are removed.
mysql -uroot -e "DROP DATABASE $target_db; DROP TABLE sigam_test.sprint14_restore_probe;"
rm "$scratch/source.sql" "$scratch/restored.sql" "$scratch/source.canonical.sql" "$scratch/restored.canonical.sql" "$scratch/private.tar.gz" "$scratch/private/proof.txt" "$scratch/restored/private/proof.txt"
rmdir "$scratch/private" "$scratch/restored/private" "$scratch/restored" "$scratch"
