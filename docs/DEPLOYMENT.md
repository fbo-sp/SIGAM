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

## Pacote pronto (hospedagem sem Composer)

O workflow `.github/workflows/release.yml` ("Pacote de publicação") gera `judoma-<versão>.zip` com o código e a pasta `vendor` já instalada (`composer install --no-dev --optimize-autoloader` em PHP 8.4), mais o arquivo `.sha256`. Ficam fora do pacote: `.env`, `.git`, `.github`, testes, Docker, scripts locais, `Especificação/`, arquivos de Node/Vite e conteúdo gerado em `storage`. O arquivo `VERSAO.txt` registra a versão e o commit.

- Ao enviar uma tag `v*` (ex.: `v0.1.0`), o pacote é publicado na página de Releases do GitHub.
- Pela aba Actions ("Run workflow"), informando a versão (ex.: `v0.1.0`), o workflow cria a tag e publica a Release no commit escolhido. Sem versão, o pacote fica só como artefato da execução, com nome `main-<commit>`.
- Antes de compactar, o workflow confere que há `vendor/autoload.php`, que não há `.env`, testes nem dependências de desenvolvimento, e que `php artisan --version` funciona.

Com o pacote, o passo 2 abaixo se reduz a extrair o zip no diretório versionado; `composer check-platform-reqs` já roda no workflow. Na atualização, preservar `.env` e `storage/` do servidor.

### Script de instalação (`deploy/instalar.sh`)

Com acesso SSH ou Terminal do cPanel, depois de extrair o pacote e criar o `.env`, rodar `bash deploy/instalar.sh` dentro da pasta da aplicação. O script usa o PHP 8.4 do cPanel (`/opt/cpanel/ea-php84/root/usr/bin/php`) ou o indicado em `PHP=...` e executa, nesta ordem:

1. confere a versão do PHP, as extensões, a pasta `vendor` e se o `.env` tem `APP_ENV=production` e `APP_DEBUG=false`; aplica `chmod 600` no `.env`;
2. cria as pastas graváveis de `storage` e `bootstrap/cache` com permissão 755;
3. se o banco já estiver instalado, entra em manutenção;
4. limpa os caches de configuração, rotas e views;
5. gera `APP_KEY` **somente** quando ela está vazia e o banco ainda não foi instalado; com banco instalado e chave vazia, para e pede a chave original;
6. roda `migrate --force` e `db:seed --force`, que são idempotentes;
7. roda `config:cache` e `view:cache`;
8. roda `sigam:production:check`. Só sai da manutenção quando todos os itens estão OK.

O script não imprime valores do `.env` e pode ser repetido. Ele não substitui backup, pausa do cron nem os passos de go/no-go abaixo.

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
