# ADR-016 — Exames de graduação

**Estado:** aceito (etapa 1 implementada em 30/10/2026).

## Contexto

Fabio pediu exames de faixa por associação, dojo, turma ou aluno, com conteúdo vindo do currículo, alerta de item não abordado em aula, banca, notas por grupo, quiz, resultado no histórico esportivo, painel e certificado. O sistema já tem o currículo cumulativo (ADR-014), as técnicas marcadas nas aulas e o quiz.

## Decisão

1. **Conteúdo copiado para o exame:** ao inscrever a primeira pessoa de uma faixa, o currículo efetivo vira linhas de `rank_exam_items` em grupos de `rank_exam_groups`. Mudar o currículo depois não altera um exame já montado; o exame é o registro do que foi cobrado.
2. **Cobertura calculada na hora:** "N/M aulas" não é gravado; sai das chamadas finalizadas e das técnicas marcadas, porque aulas continuam acontecendo até o exame. A justificativa do item sem aula é gravada.
3. **Alcance único por `ExamScope`:** associação, dojo ou banca, com as regras de `User::hasPermission` e `OperationalScope`. `exam.manage` organiza; `exam.grade` participa da banca.
4. **Nada é apagado:** participante retirado vira `removed`; exame cancelado guarda o motivo.

## Consequências

- A tela de conteúdo faz uma leitura de chamadas por faixa; em associações grandes o custo cresce com o número de aulas dos inscritos, ainda dentro do limite de uma requisição.
- A etapa 2 grava as notas sobre os mesmos itens e grupos e lança a nova faixa em `student_rank_history` com origem do exame ao encerrar.
