# Sprint 20 — Evidências de validação

> Evidência histórica daquele incremento. O estado atual está em `IMPLEMENTATION_STATUS.md` e a validação consolidada em `SPRINT21_VALIDATION.md`.

Data: 2026-10-11.

## Entrega

- meta de 8 próximas aulas por turma, configurável para 4;
- geração inicial ao concluir a turma e reposição somente do déficit;
- contagem cronológica entre todos os dias/horários e respeito a timezone e vigências;
- reposição após troca de agenda, fechamento, cancelamento e reagendamento;
- preservação de excesso, histórico, cancelamento e ocorrência reagendada;
- botão manual com autorização associativa e comando horário idempotente;
- execução em lotes de 100 turmas, lock por turma, chave única e limite de busca de 366 dias;
- registro de auditoria quando há criação e `scheduled_job_runs` para a rotina.

## Automação

`Sprint20Test` cobre criação com vários dias, meta 4/8, reexecução, redução sem exclusão, cancelamento sem ressurreição, aula atrasada, término da turma, autorização/escopo e comando operacional. `Sprint20ConcurrencyTest` dispara dois processos CLI simultâneos contra a mesma turma no MySQL. A regressão inclui `ClassManagementTest`, `AttendanceTest` e `Sprint18Test`.

- SQLite: 130 testes, 686 asserções e 1 teste ignorado por ser exclusivo de concorrência MySQL;
- MySQL 8.4: 130 testes e 691 asserções, incluindo os dois processos concorrentes;
- Pint: suíte completa anterior e o novo teste concorrente aprovados sem desvio;
- Docker local: migration 22 aplicada, aplicação saudável, login HTTP 200 e rotina inicial executada sem duplicação.

## Homologação manual recomendada

Criar uma turma de segunda e quarta com meta 8 e conferir quatro semanas ordenadas. Cancelar a primeira aula e verificar que ela continua cancelada enquanto outra é adicionada no fim. Alterar para meta 4 e confirmar que nenhuma aula desaparece. Depois que a agenda consumir ocorrências, executar **Atualizar próximas aulas** e conferir a reposição e a mensagem quando a turma terminar antes de completar a meta.
