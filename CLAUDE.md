# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Visão geral

JUDOMA é o nome mostrado aos usuários; o identificador técnico `SIGAM` permanece em comandos (`sigam:*`), variáveis (`SIGAM_*`), bancos e infraestrutura. Não renomeie esses identificadores.

Sistema de gestão de associações de artes marciais: monólito modular Laravel 12 / PHP 8.2+, Blade renderizado no servidor, CSS/JS simples em `public/css` e `public/js` (Vite/Tailwind existem mas o layout não os usa). Produção alvo: hospedagem Linux comum com MySQL 8.4/MariaDB, cron e SMTP. Redis, worker permanente, Docker e Node.js **não** podem virar requisito de produção (ADR-001, ADR-005). Produção está em NO-GO até `docs/RELEASE_CHECKLIST.md` ser aprovado.

Interface, mensagens, documentação e commits estão em português brasileiro; telas precisam funcionar em celular.

## Comandos (Windows, PHP portátil em `.tools/php`)

```powershell
.\scripts\composer.ps1 install
.\scripts\php.ps1 artisan migrate --seed
.\scripts\php.ps1 artisan serve
.\scripts\php.ps1 artisan test                                  # suíte completa (SQLite em memória)
.\scripts\php.ps1 artisan test --filter=PaymentsAndReportsTest  # um arquivo/classe
.\scripts\php.ps1 artisan test --filter=nome_do_metodo          # um teste
.\scripts\php.ps1 vendor\bin\pint --test                        # lint (use sem --test para corrigir)
```

`composer check` roda Pint + testes. `key:generate` só em instalação nova, nunca em atualização.

Docker (dev e suíte MySQL, opcional):

```powershell
.\scripts\docker-up.ps1     # app :8080, Mailpit :8025, MySQL 127.0.0.1:3307
.\scripts\docker-test.ps1   # mesma suíte contra MySQL, banco isolado sigam_test (phpunit.mysql.xml)
```

A suíte MySQL é obrigatória para mudanças que envolvam locks, constraints ou tipos. Todos os testes ficam em `tests/Feature` (`tests/Unit` está vazio). Não use `migrate:fresh`/`refresh`/rollback fora de banco de teste descartável.

## Regras do projeto (de `AGENTS.md` e `docs/MAINTENANCE_GUIDE.md`)

- Antes de editar, leia `docs/INDEX.md`, `docs/IMPLEMENTATION_STATUS.md`, `docs/MAINTENANCE_GUIDE.md`, o documento do módulo em `docs/modules/` e os ADRs em `docs/adr/`. `docs/IMPROVEMENT_BACKLOG.md` lista propostas, não funcionalidade entregue.
- Ordem de autoridade sobre o comportamento atual: migrations/rotas/models/serviços → testes → ADRs e docs de módulo → status/guias → backlog → planos de sprint e `Especificação/` (histórico).
- Nunca leia, imprima ou versione o `.env`.
- Dinheiro: `DECIMAL` + BCMath (`app/Support/Money`), nunca `float`.
- Sem exclusão física de financeiro, auditoria, presenças concluídas, filiações e resultados.
- Documentos e planilhas ficam em `storage/app/private`; downloads revalidam o vínculo atual.
- Migrations incrementais; leia todas as migrations da tabela afetada antes de alterar.
- Jobs, comandos e escritas críticas devem ser idempotentes (replay não duplica efeito).
- Toda mudança atualiza na mesma alteração a documentação correspondente e `docs/CHANGELOG.md` (tabela em `MAINTENANCE_GUIDE.md` §10: módulo, `RBAC.md`, `ROUTES_AND_COMMANDS.md`, `DATABASE.md`, `JOBS_AND_CRON.md`, ADR quando estrutural).
- Rode testes e Pint antes de concluir.

## Arquitetura

Fluxo obrigatório de uma escrita crítica:

`rota → middleware (auth/permissão/contexto) → Form Request → controller fino → serviço de domínio → transação/locks → models/constraints → auditoria`

- `app/Services/<Domínio>` concentra casos de uso, autorização relacional, transações e auditoria (`AuditService`). Controllers (`app/Http/Controllers/<Domínio>`) só coordenam HTTP. Form Requests validam forma, não autorizam. Jobs/comandos chamam os mesmos serviços do fluxo HTTP.
- `app/Support` tem utilitários transversais: Money, Export (CSV), Import (planilhas), Normalization, Tenancy.
- Domínios: IdentityAccess, Organization, People (pessoa central sem duplicação por papel, ADR-002), SportsRegistry, Classes, Attendance, Documents, Finance (motor financeiro único, ADR-003), Federation, Championship, Portal, Notifications, Reporting, Import, Operations/Audit. O mapa módulo → controller → serviço → teste está em `docs/MAINTENANCE_GUIDE.md` §4, e a tabela de serviços obrigatórios por escrita crítica em §6.

### Dois contextos de autorização (ADR-006)

- **Associação** — rotas `/associacoes/{association}`: middleware `RequirePermission` + `User::hasPermission`; só papéis `system`/`association` em `user_roles` valem. O serviço ainda prova que o recurso pertence à associação.
- **Dojo** — rotas `/operacao/dojos/{dojo}`: `Access\OperationalScope` + concessões vigentes em `dojo_role_assignments`. Papéis locais não liberam rotas associativas. Professor precisa ainda de perfil ativo, vínculo institucional, vínculo local e designação na aula.

Armadilhas: `association_id`/`dojo_id` vindos do request nunca provam acesso; `users.dojo_id` e `students.primary_dojo_id` são ponteiros de conveniência, não autorização nem origem financeira (aluno local vem de `student_dojo_history` vigente; origem financeira em `student_plans.dojo_id`); visitante só acessa a aula. Em listas, filtre no SQL antes de contar/paginar/carregar relações.

### Scheduler sem worker

`routes/console.php` agenda os comandos `sigam:*` (`app/Console/Commands`) via cron com `withoutOverlapping()`: geração de cobranças e marcação de vencidas (diário), aulas futuras e notificações (horário), envio de e-mails/convites em lotes de 50 (a cada 5 min), limpeza de arquivos de importação. `sigam:production:check`-style preflight e auditoria de origens (`sigam:operation:audit-origins`, `--apply` só após backup) estão em `docs/ROUTES_AND_COMMANDS.md`.
