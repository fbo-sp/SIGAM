# ADR-015 — Relatórios exportáveis em Excel e PDF

**Estado:** aceito (etapa 1 implementada em 29/10/2026).

## Contexto

Fabio pediu cinco relatórios (lista de alunos, lista de presença, frequência resumida e detalhada, técnicas ministradas) para gestor da associação, gestor do dojo e professor, cada um no próprio alcance. A hospedagem é compartilhada (ADR-001, ADR-005): sem worker, sem Node e sem programa externo para gerar arquivos.

## Decisão

1. **Bibliotecas só PHP, no pacote:** PhpSpreadsheet para Excel (inclusive gráficos nativos) e, na etapa do PDF, Dompdf. Entram em `vendor` no zip da versão e não pedem nada novo da hospedagem além de extensões que ela já tem (`zip`, `xmlwriter`, `gd`).
2. **Geração na hora, sem guardar arquivo:** o download é montado na requisição, com limite de linhas e throttle. Nenhum relatório fica no disco.
3. **Alcance único por `ReportScope`:** associação, dojo ou professor, com as mesmas regras das telas (`User::hasPermission`, `OperationalScope::canDojo`/`canTeacher` e as turmas do professor). A permissão `report.export` separa exportar de consultar.
4. **Planilha padronizada por `XlsxReport`:** cabeçalho, filtros, autor e texto sempre gravado como texto (sem injeção de fórmula).
5. **Auditoria de cada exportação** (`report.exported`) com relatório, nível, filtros e número de linhas, sem dados pessoais.

## Consequências

- O pacote cresce alguns megabytes por causa das bibliotecas.
- Relatórios muito grandes pedem filtro; se um dia passarem do limite de tempo da hospedagem, a saída será gerar em lotes pelo cron, sem worker.
