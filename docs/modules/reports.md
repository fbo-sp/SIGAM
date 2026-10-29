# Relatórios exportáveis

## Objetivo

Exportar em Excel e PDF os dados que cada perfil já vê nas telas (pedido de Fabio em 28/10/2026). Proposta, respostas e modelos aprovados: `/mnt/project-files/sigam/proposta-relatorios.md` e `/mnt/project-files/sigam/relatorios-modelos/`. A entrega é por etapas:

1. base e lista de alunos (Excel), disponível;
2. frequência resumida e detalhada (Excel), com registro de quem deu a aula ao finalizar a chamada, disponível;
3. lista de presença (PDF): chamada fechada, em branco por aula e grade do mês, disponível;
4. técnicas ministradas (Excel com gráficos nativos), comparando com o currículo da próxima faixa.

## Alcance

A tela "Relatórios" existe em três níveis, com a permissão `report.export`:

- associação: `/associacoes/{association}/relatorios`, todos os dojos, com filtro por dojo (perfil Gestor da Associação);
- dojo: `/operacao/dojos/{dojo}/relatorios`, só o dojo (`dojo_operator`, via `OperationalScope::canDojo`);
- professor: `/professor/relatorios`, só as turmas em que é principal ou auxiliar vigente, nos dojos em que o papel `dojo_teacher` traz `report.export` (atalho no Mais e no início do dojo).

`ReportScope` monta um `ReportContext` com os dojos e turmas do alcance. Os filtros do pedido (`dojo_id`, `class_group_id`, `situacao`) são validados contra esse contexto e só restringem; um id fora do alcance volta como erro de validação. O filtro roda no SQL antes de contar ou carregar. "Ver total" mostra a contagem antes de baixar. Cada download registra `report.exported` na auditoria com o relatório, o nível, os filtros e o número de linhas, sem dados pessoais.

## Lista de alunos

`StudentListReport`: matrícula, nome (social quando houver), nascimento, idade, dojos com vínculo vigente, modalidades e faixa atual, turmas com matrícula ativa, situação, entrada, CPF, e-mail, celular do aluno e o responsável principal com telefone. Situação: "Ativos e pré-cadastro" (padrão), "Só ativos" ou "Todos". O professor vê só alunos ativos com matrícula ativa nas turmas dele, e a lista dele não tem CPF nem e-mail (decisão de Fabio). Limite de 5.000 linhas por arquivo.

## Frequência

O cartão "Frequência" tem período (padrão: o mês atual; até 366 dias), dojo e turma, "Ver total" (aulas finalizadas, abertas, alunos e linhas da detalhada) e dois downloads. `AttendanceReportData` monta a base: cada aula da turma no período, até hoje, para cada aluno com matrícula ativa ou encerrada que cobre a data da aula. Aulas canceladas ou reagendadas ficam fora; visitas também. Só chamada finalizada conta (decisão de Fabio); SIM, reposição e experimental contam presença, e NÃO ou aluno sem marcação contam falta. As turmas são as ativas do alcance.

- `AttendanceSummaryReport` (resumida): aba "Por turma" com matrícula, aluno, faixa, turma, aulas no período, presenças, faltas, frequência (verde 75% ou mais, amarelo 50% a 74%, vermelho abaixo de 50%) e chamadas abertas, que não contam; aba "Por aluno" soma as turmas; aba "Sobre" explica as regras.
- `AttendanceDetailReport` (detalhada): uma linha por aula de cada aluno com data, dia, início, professor da aula, auxiliares presentes, presença (SIM, NÃO, "—" na chamada aberta, "Sem marcação") e chamada (Finalizada ou Aberta). Sem registro de quem deu a aula, mostra o previsto com "(previsto)". Limite de 10.000 linhas, com aviso no subtítulo.

## Lista de presença (PDF)

`AttendanceListReport` gera três folhas A4 com o `PdfReport` (Dompdf):

- **da aula, finalizada:** dojo, turma, dia e horário, professor da aula (ou o previsto, com "(previsto)"), auxiliares, quando e por quem foi finalizada, técnicas da aula e visitantes; lista com faixa e SIM/NÃO (matriculados por nome, visitantes no fim) e totais de presentes, ausentes e da lista;
- **da aula, em branco:** os alunos da chamada daquela data com caixas SIM e NÃO e observação, três linhas para visitante, campos para professor, auxiliares e técnicas, e assinatura. Sai para aula aberta ou futura;
- **grade do mês (paisagem):** os alunos com matrícula em alguma aula do mês e uma coluna por aula (sem canceladas e reagendadas), para marcar P ou F, com linhas de visitante, rubrica do professor por aula e assinatura.

A lista da aula sai pelo botão na página da aula, no dojo (`operacao/dojos/{dojo}/aulas/{session}/lista-presenca`, gestor do dojo ou professor da aula com `report.export`, pela mesma regra de `OperationalScope::sessions`) e na associação (`associacoes/{association}/aulas/{session}/lista-presenca`). Aula cancelada ou reagendada não tem lista. A grade do mês fica no cartão "Lista de presença" da tela Relatórios (`relatorios/grade-presenca`, `grade_turma` e `mes`), nos três níveis; mês sem aulas é recusado com aviso.

## PDF

`App\Support\Export\PdfReport` renderiza uma view de `resources/views/reports/pdf` sem acesso remoto, PHP ou JavaScript embutidos, com a fonte DejaVu Sans (acentos) e o rodapé "Gerado em ... por ..." e "Página N de M" em todas as páginas. `sigam:production:check` informa se as extensões `dom` e `mbstring` estão disponíveis.

## Excel

`App\Support\Export\XlsxReport` (PhpSpreadsheet) padroniza título, linha de filtros, linha "Gerado em ... por ... (perfil)", cabeçalho azul fixo com filtro do Excel e linhas alternadas. Texto é gravado sempre como texto, então um nome que começa com `=` não vira fórmula; datas viram data do Excel. A frequência usa formatação condicional e a folha sai em paisagem, ajustada à largura. O arquivo é gerado na hora (sem fila nem worker) e não fica guardado no servidor. `sigam:production:check` informa se as extensões `zip` e `xmlwriter` estão disponíveis.

## Mapa do código

- `app/Services/Reporting/ReportScope.php`, `ReportContext.php`, `StudentListReport.php`, `AttendanceReportData.php`, `AttendanceSummaryReport.php`, `AttendanceDetailReport.php`, `AttendanceListReport.php`;
- `app/Http/Controllers/Reporting/ReportController.php`;
- `app/Support/Export/XlsxReport.php` e `PdfReport.php`;
- views `resources/views/reports/pdf/*` (folhas PDF) e `attendance/partials/list-pdf` (botão na aula);
- view `resources/views/reports/index.blade.php`;
- testes `tests/Feature/ReportsTest.php` e `AttendanceReportsTest.php` e `AttendanceListPdfTest.php`.
