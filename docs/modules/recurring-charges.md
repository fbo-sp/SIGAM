# Cobranças recorrentes

## Responsabilidade

O módulo transforma vínculos financeiros vigentes em registros imutáveis de obrigação. `charges` é a tabela central do fluxo `origem → cobrança → pagamento → baixa`; a origem implementada é `monthly_fee`. Pagamentos e estornos foram adicionados sem alterar os snapshots já gerados.

## Geração

`RecurringChargeService::generate()` recebe uma competência normalizada no primeiro dia do mês, associação opcional e usuário opcional. Ele seleciona vínculos e planos ativos, valida a frequência e processa cada vínculo em transação com `lockForUpdate`.

Periodicidades:

- mensal: todo mês;
- semestral: a cada seis meses desde o mês inicial do vínculo;
- anual: a cada doze meses desde o mês inicial;
- personalizada: não possui geração automática até receber regra explícita.

A chave única `(source_type, source_id, competence)` reforça a idempotência. Para mensalidades, `source_id` é `student_plans.id` e `charges.dojo_id` vem exclusivamente da origem congelada nesse vínculo. Repetir o comando ou formulário retorna zero para registros existentes. Vínculo legado sem dojo é ignorado até revisão; nunca se usa o dojo principal atual para preencher uma obrigação.

## Snapshot e integridade

Cada cobrança preserva:

- associação, dojo, aluno e pessoa;
- plano e vínculo de origem;
- competência, emissão e vencimento;
- valor-base, desconto, valor final, pago e saldo;
- IDs de desconto ou gratuidade;
- `calculation_snapshot` com versão do calculador, composição e explicação;
- `due_policy_snapshot` com dia preferencial, política de fim de semana e feriados usados.

Alterar plano, desconto, gratuidade ou configurações depois da geração não modifica a cobrança. Gratuidade vigente gera `waived`, valor e saldo zero; as demais começam como `open`. Valores são `DECIMAL(12,2)` e regras usam BCMath.

## Estados atuais

- `open`: aguardando pagamento;
- `overdue`: aberta com vencimento anterior à referência;
- `waived`: isenta por gratuidade aprovada;
- `partially_paid` e `paid`: reconciliados a partir dos pagamentos válidos;
- `cancelled`: reservado para cancelamento formal futuro, sem exclusão física.

`OverdueChargeService` altera apenas `open`. Registros pagos, parciais, cancelados ou isentos nunca são afetados pelo job de vencimento.

O status de uma cobrança parcial continua `partially_paid`, porque descreve o pagamento. O atraso é derivado do vencimento: os escopos `Charge::pastDue()` (saldo positivo, status `open`/`overdue`/`partially_paid` e vencimento anterior à referência) e `Charge::notYetDue()` (saldo positivo, `open`/`partially_paid`, ainda no prazo) alimentam o resumo da central de cobranças, a inadimplência dos dashboards e a notificação `charge.overdue`. Assim, uma cobrança parcialmente paga e vencida aparece como vencida, com o saldo restante.

## Operação

- `php artisan sigam:charges:generate --competence=AAAA-MM [--association=ID]`;
- `php artisan sigam:charges:mark-overdue [--date=AAAA-MM-DD] [--association=ID]`.

O scheduler executa geração diariamente às 01:10 e vencimento às 01:30, ambos com `withoutOverlapping`. O cron de produção continua sendo uma única chamada a `schedule:run`. Cada comando registra início, término, status, quantidade e erro em `scheduled_job_runs`.

O formulário manual usa o mesmo serviço e informa o usuário em `created_by`. A execução de cron usa `created_by = null`, identificando origem sistêmica. Ambas produzem auditoria por cobrança.

## Interface e permissões

`charge.view` dá acesso à central, filtros, detalhe e extrato por aluno. `charge.generate` permite reprocessar uma competência. No contexto operacional, a listagem é própria e exige concessão vigente; mostra somente cobranças com `dojo_id` igual ao dojo da rota. Baixa, estorno e relatórios usam permissões próprias descritas em `payments-reports.md`.

Rotas aninhadas e consultas confirmam `association_id`; conhecer um ID de aluno ou cobrança de outra associação não concede acesso.

## Mapa de código

- migrations: `database/migrations/2026_08_30_000011_create_recurring_charges_table.php` e origem local consolidada em `2026_10_11_000023_consolidate_dojo_operation.php`;
- model: `app/Models/Charge.php`;
- cálculo: `app/Services/Finance/FinancialCalculator.php`;
- geração/vencimento: `RecurringChargeService.php`, `OverdueChargeService.php`;
- execução: `app/Console/Commands/GenerateRecurringCharges.php`, `MarkOverdueCharges.php`, `JobRunRecorder.php`;
- HTTP: `ChargeController`, `app/Http/Controllers/Access/DojoFinanceController.php`, `resources/views/finance/charges/` e `resources/views/operation/finance/`;
- testes: `tests/Feature/RecurringChargesTest.php` e cenários de origem/escopo em `tests/Feature/Sprint21Test.php`.

## Manutenção futura

Novas origens devem usar `charges`, escolher `source_type` documentado e uma chave de origem estável. Nunca recalcular registros existentes a partir do cadastro atual. O fluxo de pagamento obtém lock da cobrança, soma pagamentos válidos e reconcilia `paid_amount`, `balance_amount` e status.
