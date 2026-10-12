# Guia de manutenção para desenvolvedores e agentes de IA

## 1. Como interpretar o repositório

A ordem de autoridade para descrever o comportamento atual é:

1. migrations aplicadas, rotas, models e serviços de domínio;
2. testes automatizados que comprovam invariantes e casos negativos;
3. ADRs aceitos e documentos de módulo;
4. `IMPLEMENTATION_STATUS.md`, este guia e runbooks operacionais;
5. `IMPROVEMENT_BACKLOG.md`, que registra propostas sem provar implementação;
6. planos de sprint e especificações originais, que preservam intenção e histórico.

Se um plano antigo disser “futuro”, mas código, teste e documentação do módulo mostrarem a entrega, trate o plano como histórico. Não mude comportamento crítico apenas para alinhar uma frase antiga: confirme ADR, schema, serviço e testes.

## 2. Restrições arquiteturais

- preservar o monólito modular Laravel e a compatibilidade com hospedagem compartilhada;
- não tornar Docker, Redis, worker permanente, microserviço ou Node.js requisitos de produção;
- manter Blade/server-side e JavaScript progressivo, com interface em português simples e responsiva;
- validar permissão, associação, dojo e relação de domínio no backend;
- manter regras críticas em serviços transacionais, não em controllers ou views;
- dinheiro usa `DECIMAL` e BCMath, nunca `float`;
- financeiro, auditoria, presenças concluídas, filiações e resultados não são apagados fisicamente;
- arquivos continuam privados e downloads revalidam o relacionamento atual;
- migrations são incrementais; nunca recriar tabela para alterar uma instalação existente;
- comandos e endpoints críticos devem tolerar replay sem duplicar efeitos.

## 3. Estrutura do código

| Caminho | Responsabilidade |
|---|---|
| `routes/web.php` | rotas Blade, middleware e permissões associativas/operacionais |
| `routes/console.php` | agenda recorrente sem worker residente |
| `app/Http/Controllers` | coordenação HTTP; não concentra regra crítica |
| `app/Http/Requests` | forma, tipos e normalização da entrada |
| `app/Services` | casos de uso, autorização relacional, transações e auditoria |
| `app/Models` | relações, casts e escopos Eloquent; não substituem validação de domínio |
| `app/Support` | normalização, dinheiro, CSV, catálogos e leitura de planilhas |
| `database/migrations` | história incremental e fonte do schema |
| `database/seeders` | permissões, papéis técnicos, templates e bootstrap conservador |
| `resources/views` | UI Blade em português; dados já autorizados |
| `public/js`, `public/css` | melhoria progressiva e layout responsivo |
| `storage/app/private` | documentos e planilhas; nunca expor por link público |
| `tests/Feature` | contratos de domínio, escopo, segurança e regressão transversal |

## 4. Mapa dos módulos

| Módulo | Entradas HTTP principais | Serviços/fonte de regra | Testes principais |
|---|---|---|---|
| Identidade | `Auth/*Controller` | middleware ativo/associação, `TeacherInvitationService` | `AuthenticationTest`, `TeacherInvitationTest` |
| Organização/Pessoas | `Organization/*`, `People/*` | `AssociationService`, `DojoService`, `PersonService` | `OrganizationManagementTest`, `PersonManagementTest` |
| Acesso local | `Access/OperationalController`, `DojoAccessController` | `OperationalScope`, `AccessAdministration` | `OperationalContextAuthorizationTest` |
| Cadastro esportivo | `Sports/*Controller` | `CatalogService`, `StudentService`, `TeacherService` | `SportsRegistryTest` |
| Cadastro direto | `DirectRegistrationController` | `DirectRegistrationService`, `DocumentService` | `DirectRegistrationTest` |
| Turmas/aulas | `ClassGroupController`, `AttendanceController` | `ClassGroupService`, `FutureSessionMaintainer`, `AttendanceService`, `SessionVisitorService` | `ClassManagementTest`, `AttendanceTest`, `Sprint18Test`, `Sprint20Test` |
| Importação | `AssistedImportController` | `AssistedImportService` e serviços canônicos dos módulos | `AssistedImportTest` |
| Documentos | `DocumentController` | `DocumentService` | `PrivateDocumentsTest` |
| Financeiro | `FinanceController`, `ChargeController`, `PaymentController`, `DojoFinanceController` | `FinancialBenefitService`, `FinancialCalculator`, `RecurringChargeService`, `PaymentService` | `FinancialPlansAndBenefitsTest`, `RecurringChargesTest`, `PaymentsAndReportsTest`, `Sprint21Test` |
| Federações | `FederationController` | `FederationMembershipService`, `DocumentService` | `FederationMembershipsTest` |
| Campeonatos | `ChampionshipController`, `DojoChampionshipController`, portal | `ChampionshipService`, `ChampionshipRegistrationService`, `ChampionshipResultService` | `ChampionshipFlowTest`, `PortalAccessTest`, `Sprint21Test` |
| Portal | `PortalController` | `PortalAccessService` e serviços dos módulos | `PortalAccessTest` |
| Notificações/dashboard | `NotificationController`, `DashboardController` | `NotificationService`, `EmailDeliveryService`, `DashboardService` | `NotificationsAndDashboardTest` |
| Hardening/operação | middleware, comandos e scripts | `ProductionReadiness`, `JobRunRecorder`, `AuditService` | `ProductionHardeningTest`, `AuditLogTest` |

## 5. Dois contextos de autorização

### Associação

Rotas `/associacoes/{association}` usam `RequirePermission` e `User::hasPermission`. Somente papéis `system` e `association` em `user_roles` satisfazem essas rotas. O controller/serviço ainda precisa provar que cada recurso pertence à associação.

### Dojo

Rotas `/operacao/dojos/{dojo}` usam `OperationalScope` e concessões vigentes em `dojo_role_assignments`. Papéis locais não desbloqueiam rotas associativas. Professor exige, além da concessão, perfil ativo, vínculo institucional, vínculo local e designação na aula.

Regras importantes:

- `users.dojo_id` serve ao dashboard e não autoriza recurso;
- `students.primary_dojo_id` é ponteiro de conveniência, não prova vínculo ou origem financeira;
- aluno local vem de `student_dojo_history` vigente;
- visitante concede acesso somente à aula e nunca ao diretório/documentos/financeiro;
- em listas, filtrar no SQL antes de contar, paginar ou carregar relações.

## 6. Escritas críticas

| Operação | Serviço obrigatório | Proteção contra replay/concorrência |
|---|---|---|
| cadastro direto | `DirectRegistrationService` | UUID + `direct_registration_requests`, transação e locks |
| convite | `TeacherInvitationService` | token hash único, locks e estados separados de transporte |
| agenda/matrícula | `ClassGroupService` | versões de agenda, lock da turma e validação de sobreposição |
| próximas aulas | `FutureSessionMaintainer` | lock da turma + chave única da ocorrência |
| chamada | `AttendanceService` | lock da aula, lista revalidada e unicidade aula/aluno |
| visitante | `SessionVisitorService` | unicidade aula/aluno, vínculo restrito e revogação lógica |
| plano/benefício | `FinancialBenefitService` | lock, vigência e sobreposição por aluno/dojo |
| cobrança | `RecurringChargeService` | lock + unicidade origem/competência |
| pagamento | `PaymentService` | UUID, hash da requisição, lock e reconciliação por soma |
| inscrição | `ChampionshipRegistrationService` | lock + unicidade campeonato/pessoa/papel |
| importação | `AssistedImportService` | hash de arquivo/linha, estados duráveis e lote retomável |
| notificação | `NotificationService`/`EmailDeliveryService` | chaves idempotentes e reserva condicional |

## 7. Banco e migrations

Leia todas as migrations da tabela afetada, não apenas a mais recente. A migration `000023` adiciona origem de dojo a `student_plans` e participação dojo–campeonato; ela não substitui as migrations financeiras/esportivas anteriores. FKs históricas usam `RESTRICT` por intenção.

Antes de uma migration:

1. inventarie dados nulos, duplicados e órfãos;
2. defina comportamento temporário compatível com código anterior;
3. use coluna inicialmente nula quando um backfill seguro não puder ser garantido;
4. crie comando dry-run/idempotente para inferências não triviais;
5. não use `migrate:fresh`, `refresh` ou rollback destrutivo fora de banco de teste descartável.

Para origens legadas, execute primeiro `sigam:operation:audit-origins`; `--apply` só pode ser usado após backup e revisão das contagens.

## 8. Processo seguro de alteração

1. leia `INDEX.md`, `IMPLEMENTATION_STATUS.md`, este guia, módulo e ADR relacionado; se a tarefa for melhoria planejada, leia também `IMPROVEMENT_BACKLOG.md`;
2. inspecione migrations, models, rotas, request, serviço, views e testes do fluxo;
3. escreva os casos negativos de outra associação/dojo e ID adulterado;
4. implemente a regra no serviço e mantenha controller fino;
5. audite somente IDs/estados necessários, nunca senha, token, CPF ou arquivo;
6. atualize módulo, mapa de rotas/comandos, status/changelog e ADR quando estrutural;
7. execute SQLite, MySQL e Pint;
8. para produção, cumpra `RELEASE_CHECKLIST.md`; teste automatizado não assina aceite humano.

## 9. Comandos de desenvolvimento

```powershell
scripts/composer.ps1 install
scripts/php.ps1 artisan migrate --seed
scripts/php.ps1 artisan test
scripts/php.ps1 vendor/bin/pint --test
scripts/docker-up.ps1
scripts/docker-test.ps1
```

Nunca leia, imprima ou versione segredos do `.env`. Para testar e-mail local use Mailpit; para produção use SMTP real e autorizado.

## 10. Atualização obrigatória da documentação

| Tipo de mudança | Documentos mínimos |
|---|---|
| regra funcional | módulo, `USER_GUIDE.md`, testes e `CHANGELOG.md` |
| rota/permissão | módulo, `RBAC.md`, `ROUTES_AND_COMMANDS.md` e testes negativos |
| schema | `DATABASE.md`, módulo, migration, status e ADR se estrutural |
| job/comando | `JOBS_AND_CRON.md`, `ROUTES_AND_COMMANDS.md`, runbook e teste |
| deploy/configuração | `README.md`, `DEPLOYMENT.md`, `.env.example` sem segredo e checklist |
| decisão de produto | `DECISIONS.md` e ADR quando arquitetural |
| item de melhoria | `IMPROVEMENT_BACKLOG.md`, módulo afetado, critérios/testes e changelog |

Planos e documentos `SPRINT*_VALIDATION.md` preservam evidência histórica. Corrija neles somente erros factuais; registre o estado corrente nos documentos canônicos.
