# Relatórios exportáveis

## Objetivo

Exportar em Excel e PDF os dados que cada perfil já vê nas telas (pedido de Fabio em 28/10/2026). Proposta, respostas e modelos aprovados: `/mnt/project-files/sigam/proposta-relatorios.md` e `/mnt/project-files/sigam/relatorios-modelos/`. A entrega é por etapas:

1. base e lista de alunos (Excel), disponível;
2. frequência resumida e detalhada (Excel), com registro de quem deu a aula ao finalizar a chamada;
3. lista de presença (PDF): chamada fechada, em branco por aula e grade do mês;
4. técnicas ministradas (Excel com gráficos nativos), comparando com o currículo da próxima faixa.

## Alcance

A tela "Relatórios" existe em três níveis, com a permissão `report.export`:

- associação: `/associacoes/{association}/relatorios`, todos os dojos, com filtro por dojo (perfil Gestor da Associação);
- dojo: `/operacao/dojos/{dojo}/relatorios`, só o dojo (`dojo_operator`, via `OperationalScope::canDojo`);
- professor: `/professor/relatorios`, só as turmas em que é principal ou auxiliar vigente, nos dojos em que o papel `dojo_teacher` traz `report.export` (atalho no Mais e no início do dojo).

`ReportScope` monta um `ReportContext` com os dojos e turmas do alcance. Os filtros do pedido (`dojo_id`, `class_group_id`, `situacao`) são validados contra esse contexto e só restringem; um id fora do alcance volta como erro de validação. O filtro roda no SQL antes de contar ou carregar. "Ver total" mostra a contagem antes de baixar. Cada download registra `report.exported` na auditoria com o relatório, o nível, os filtros e o número de linhas, sem dados pessoais.

## Lista de alunos

`StudentListReport`: matrícula, nome (social quando houver), nascimento, idade, dojos com vínculo vigente, modalidades e faixa atual, turmas com matrícula ativa, situação, entrada, CPF, e-mail, celular do aluno e o responsável principal com telefone. Situação: "Ativos e pré-cadastro" (padrão), "Só ativos" ou "Todos". O professor vê só alunos ativos com matrícula ativa nas turmas dele, e a lista dele não tem CPF nem e-mail (decisão de Fabio). Limite de 5.000 linhas por arquivo.

## Excel

`App\Support\Export\XlsxReport` (PhpSpreadsheet) padroniza título, linha de filtros, linha "Gerado em ... por ... (perfil)", cabeçalho azul fixo com filtro do Excel e linhas alternadas. Texto é gravado sempre como texto, então um nome que começa com `=` não vira fórmula; datas viram data do Excel. O arquivo é gerado na hora (sem fila nem worker) e não fica guardado no servidor. `sigam:production:check` informa se as extensões `zip` e `xmlwriter` estão disponíveis.

## Mapa do código

- `app/Services/Reporting/ReportScope.php`, `ReportContext.php`, `StudentListReport.php`;
- `app/Http/Controllers/Reporting/ReportController.php`;
- `app/Support/Export/XlsxReport.php`;
- view `resources/views/reports/index.blade.php`;
- teste `tests/Feature/ReportsTest.php`.
