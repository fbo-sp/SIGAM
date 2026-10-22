# ADR-014 — Acompanhamento técnico por graduação

**Estado:** aceito (fases 1 e 2 implementadas em 22/10/2026).

## Contexto

A associação quer acompanhar a preparação do aluno para o próximo exame de graduação: o que cada faixa cobra, como o aluno está em cada item e o que as aulas trabalharam. Federações e associações adotam programas diferentes, e o judô separa a avaliação em conhecimento teórico (história, princípios, terminologia, regras) e prático (técnicas e katas). O requisito aprovado está em `/mnt/project-files/sigam/requisito-acompanhamento-graduacao.md` (decisões D1 a D14).

## Decisão

1. **Novo módulo `Progression`** (`app/Services/Progression`, `app/Support/Progression`, `app/Http/Controllers/Progression`), separado de `SportsRegistry`, que só lê modalidades, graduações e o histórico de graduação do aluno.
2. **Catálogo em árvore por associação e modalidade** (`technique_items`): grupos e itens com profundidade livre. Todo nó tem `knowledge_type` teórico ou prático; o tipo é escolhido no grupo principal e herdado por tudo que está dentro dele (D9). Itens não são apagados: inativar um grupo inativa o que está dentro, e o histórico continua apontando para o item.
3. **Currículo cumulativo calculado** (`rank_curriculum_entries`): cada faixa guarda só os próprios ajustes (incluir item, mudar o nível exigido ou deixar de cobrar com `required_level = NULL`). O currículo efetivo de uma faixa é calculado em `CurriculumService::effective` somando as faixas anteriores da modalidade pela `sort_order` (D14). Assim, mudar uma faixa baixa reflete nas seguintes sem cópia.
4. **Escala única de 4 níveis** (`AssessmentLevel`): não iniciado, em desenvolvimento, adequado e dominado (D2). O currículo só exige de 2 a 4.
5. **Modelo pronto opcional** em `resources/data/technique-templates.json`, no mesmo padrão do ADR-010: validado por `TechniqueTemplateCatalog`, copiado para a associação em transação, auditado (`technique_template.applied`) e idempotente pelo caminho do item. Reaplicar só inclui o que falta e nunca sobrescreve ajustes do currículo. As faixas são encontradas pelo `name` da graduação (o modelo do judô usa os nomes do template `judo_cbj_2023`); faixas ausentes são informadas, não criadas.
6. **Registros de avaliação só de inclusão** (`technique_assessments`): o nível atual do aluno num item é o último registro gravado; correção gera novo registro. Só os itens cujo nível mudou são gravados, então reenviar o formulário não duplica.
7. **Quem avalia** (D11): o professor principal ou auxiliar vigente de uma turma ativa da modalidade, no dojo, em que o aluno está matriculado. A regra fica em `AssessmentService::assessorTeacherId`, sobre `OperationalScope::canTeacher`. Gestor do dojo e associação só consultam.
8. **Prontidão sem percentual** (D12): `StudentProgressService` compara o nível atual com o currículo efetivo da próxima graduação e lista o que falta, mais idade e tempo mínimo na faixa atual. O radar é SVG gerado no servidor (componente `x-radar`), sem biblioteca externa.
9. **Permissões**: `curriculum.manage` (associação); `assessment.view` (associação, gestor do dojo e professor) e `assessment.manage` (só professor do dojo). As fases seguintes acrescentam `quiz.manage` e `progression.report`.

## Consequências

- o catálogo é da associação, mesmo quando a modalidade é global; duas associações nunca compartilham itens;
- excluir uma graduação ou um item com exigências fica bloqueado pelas chaves estrangeiras (`restrictOnDelete`);
- o cálculo do currículo efetivo lê todas as faixas anteriores; o volume é pequeno (dezenas de faixas, centenas de itens) e não exige cache;
- o conteúdo do modelo do judô é uma sugestão: precisa de revisão da associação antes do uso real.
