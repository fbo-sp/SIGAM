# Índice da documentação

## Ordem de leitura

1. `IMPLEMENTATION_STATUS.md` — fotografia canônica do que está e não está implementado.
2. `IMPROVEMENT_BACKLOG.md` — melhorias propostas, prioridades, critérios e estado; não descreve funcionalidade já entregue.
3. `MAINTENANCE_GUIDE.md` — roteiro para manutenção humana ou por agente de IA.
4. `ARCHITECTURE.md` — limites dos módulos e dependências.
5. `DATABASE.md` — convenções, modelo atual e invariantes.
6. `RBAC.md` — autorização e escopo.
7. `ROUTES_AND_COMMANDS.md` — mapa de entradas HTTP, CLI e scheduler.
8. `SECURITY.md` — controles e limites conhecidos.
9. `TESTING.md` — estratégia e comandos.
10. `DECISIONS.md` e `adr/` — decisões de produto e arquitetura.
11. `modules/` — contrato técnico/funcional de cada módulo.
12. `DOCKER.md` — ambiente local e suíte MySQL em contêineres.
13. `JOBS_AND_CRON.md` — rotinas idempotentes e operação por cron.
14. `DEPLOYMENT.md`, `BACKUP_RESTORE.md` e `OPERATIONS_RUNBOOK.md` — publicação e recuperação.
15. `RELEASE_CHECKLIST.md` e `RELEASE_VALIDATION.md` — go/no-go e evidências.
16. `USER_GUIDE.md` — ajuda e roteiro de treinamento por tarefa.
17. `../PLANO_DE_DESENVOLVIMENTO_SIGAM.md` e `PLANO_MELHORIAS_CADASTRO_E_OPERACAO.md` — planos históricos concluídos tecnicamente.
18. `SPRINT15_VALIDATION.md` — evidências de escopo operacional, regressão e limites do aceite técnico.
19. `SPRINT16_VALIDATION.md` — evidências do cadastro composto, documentos contextuais e regressão.
20. `SPRINT17_VALIDATION.md` — evidências dos convites, ativação, recuperação de senha, SMTP e regressão.
21. `SPRINT18_VALIDATION.md` — evidências da agenda semanal, reconciliação, visitantes e regressão.
22. `SPRINT19_VALIDATION.md` — evidências dos modelos, prévia, lotes retomáveis, isolamento e regressão da importação.
23. `SPRINT20_VALIDATION.md` — evidências da meta 4/8, reposição automática, histórico, escopo e operação horária.
24. `SPRINT21_VALIDATION.md` — evidências da origem financeira local, campeonatos compartilhados, migração conservadora e regressão transversal.

As especificações originais permanecem em `../Especificação/` como baseline histórico de produto. Para comportamento já implementado, migrations, serviços, testes, ADRs e documentos de módulo formam a fonte atual. Divergências devem ser analisadas e registradas; não se deve reverter silenciosamente segurança, integridade ou decisões posteriores apenas para copiar texto antigo.

## Status por módulo

| Módulo | Status | Documento |
|---|---|---|
| Fundação/Identidade | Convite e ativação de professor disponíveis | `modules/identity-access.md`, `modules/operational-access.md`, `modules/teacher-invitations.md` |
| Organização e Pessoas | Implementado | `modules/organization-people.md` |
| Cadastro esportivo | Cadastro direto disponível; template revisável de graduações | `modules/sports-registry.md`, `modules/direct-registration.md`, `templates/template_carga_graduacoes.xlsx` |
| Acompanhamento técnico | Catálogo, currículo por graduação, avaliação, técnicas da aula, quiz, portal, painéis e lembrete | `modules/progression.md`, `adr/ADR-014-acompanhamento-tecnico-por-graduacao.md` |
| Turmas e matrículas | Agenda semanal e meta 4/8 disponíveis | `modules/classes.md` |
| Aulas e presença | Visitantes e reposição automática disponíveis | `modules/attendance.md` |
| Importação assistida | Alunos e presenças disponíveis por CSV/XLSX | `modules/assisted-imports.md` |
| Documentos | Implementado | `modules/documents.md` |
| Planos e benefícios | Consolidado por origem de dojo | `modules/finance-plans-benefits.md` |
| Cobranças recorrentes | Consolidado por origem de dojo | `modules/recurring-charges.md` |
| Pagamentos e relatórios | Implementado | `modules/payments-reports.md` |
| Relatórios exportáveis | Etapa 1: lista de alunos em Excel nos três níveis | `modules/reports.md`, `adr/ADR-015-relatorios-exportaveis.md` |
| Federações e filiações | Implementado | `modules/federations-memberships.md` |
| Campeonatos | Compartilhado entre associação e operação local | `modules/championships.md` |
| Portal do aluno e responsável | Implementado | `modules/portal.md` |
| Notificações e dashboards | Implementado | `modules/notifications-dashboards.md` |
| Hardening e produção | Incremento técnico; produção bloqueada por gates | `modules/production-hardening.md` |
| Experiência móvel pós-roadmap | Backlog proposto; não implementado | `IMPROVEMENT_BACKLOG.md` |
