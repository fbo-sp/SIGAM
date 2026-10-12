# Publicação em hospedagem PHP/MySQL

Docker é apenas homologação. A aplicação não requer Redis, worker permanente nem Node em produção; a interface usa Blade e arquivos estáticos em `public`.

## Pré-requisitos

- PHP compatível com `composer.lock` (mínimo declarado 8.2); verificar extensões com `composer check-platform-reqs --no-dev`, incluindo PDO MySQL, BCMath, mbstring, fileinfo e OpenSSL.
- MySQL 5.7 ou superior (8.4 e 5.7 testados no CI), conta exclusiva e não root, TLS no site, acesso de administração protegido, cron por minuto e saída SMTP autenticada.
- Raiz do domínio apontando **somente para `public/`**. Se a hospedagem não permitir essa separação, não publicar sem revisão específica.
- `storage` e `bootstrap/cache` graváveis apenas pelo usuário necessário; sem permissões universais. Documentos, logs, backups, código e configuração fora da raiz pública. Não criar link público para documentos.
- Responsável, janela, backup recuperável, release anterior e checklist aprovados.

## Configuração

O operador configura segredos diretamente no painel/cofre, sem colar em chat ou terminal compartilhado. Usar `APP_ENV=production`, `APP_DEBUG=false`, URL HTTPS canônica, sessão Secure/HttpOnly/SameSite, MySQL e SMTP. Uma instalação nova recebe APP_KEY uma única vez; atualizações e restaurações preservam a mesma chave. Nunca executar `key:generate` em uma instalação existente. Configurar proxy confiável somente se houver proxy real e conhecido; verificar HTTPS/cookies no caminho público final.

Não copiar valores ou credenciais locais do Docker. Não executar `composer setup` em produção: é um atalho de desenvolvimento e pode gerar chave/migrar automaticamente.

## Sequência de release

1. Preparar diretório versionado fora da raiz pública com código revisado e `composer.lock`. Registrar identificador e hashes do artefato, data, aprovador e versão de PHP/MySQL. Preservar configuração e armazenamento compartilhados.
2. Instalar com `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`. Executar `composer check-platform-reqs --no-dev` e auditoria de dependências. Nunca usar `composer update` no servidor.
3. Pausar cron e outros escritores, aguardar comandos em curso e entrar em manutenção (`php artisan down --retry=60`). Fazer backup consistente conforme `BACKUP_RESTORE.md`.
4. Revisar o histórico e as migrations pendentes e executar `php artisan migrate --force`. Não usar `migrate:fresh`, `refresh` ou reset de dados. A migration mais recente no estado documentado é `2026_10_11_000023_consolidate_dojo_operation.php`; releases futuras devem confirmar isso no código, não copiar o número deste documento como verdade permanente.
5. Executar `php artisan db:seed --force` quando o conteúdo estático da release exigir. O seeder não redefine nem promove contas existentes. Provisionamento inicial de administrador é excepcional, supervisionado e com segredo de cofre; retirar a variável de bootstrap após criação.
6. Executar `php artisan optimize:clear`, `php artisan config:cache` e `php artisan view:cache`. Conferir `php artisan route:list` e `php artisan sigam:production:check`. Código diferente de zero impede liberação técnica. Não tratar preflight aprovado como aceite final.
7. Trocar o diretório ativo de forma atômica se o provedor suportar; verificar login, negação de acesso cruzado, consulta financeira e download autorizado em conta de homologação. Reiniciar/recarregar PHP pelo painel se necessário para invalidar OPcache.
8. Registrar go/no-go. Somente após aprovação executar `php artisan up`, reativar cron, conferir `/up`, erros e jobs. SMTP real exige destinatário de teste autorizado, nunca disparo em massa como smoke test.

## Rollback

Manter manutenção e cron pausado. Em falha apenas de código, apontar para o artefato anterior **somente após conferir compatibilidade com o schema atual**, limpar/regerar caches e repetir smoke tests. Não executar `migrate:rollback` cegamente: pode remover histórico.

Se for preciso restaurar dados, preservar primeiro o estado do incidente, escolher backup consistente e restaurar em destino novo e isolado. Comparar integridade e reconciliar operações posteriores antes de trocar a aplicação. Obter aprovação explícita para qualquer perda de dados. Banco, documentos privados e APP_KEY devem pertencer ao mesmo conjunto. O ensaio local não comprova rollback do provedor definitivo.
