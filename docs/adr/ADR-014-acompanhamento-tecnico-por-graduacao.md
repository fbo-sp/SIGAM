# ADR-014 — Acompanhamento técnico por graduação

**Estado:** aceito (fase 1 implementada em 22/10/2026).

## Contexto

A associação quer acompanhar a preparação do aluno para o próximo exame de graduação: o que cada faixa cobra, como o aluno está em cada item e o que as aulas trabalharam. Federações e associações adotam programas diferentes, e o judô separa a avaliação em conhecimento teórico (história, princípios, terminologia, regras) e prático (técnicas e katas). O requisito aprovado está em `/mnt/project-files/sigam/requisito-acompanhamento-graduacao.md` (decisões D1 a D14).

## Decisão

1. **Novo módulo `Progression`** (`app/Services/Progression`, `app/Support/Progression`, `app/Http/Controllers/Progression`), separado de `SportsRegistry`, que só lê modalidades, graduações e o histórico de graduação do aluno.
2. **Catálogo em árvore por associação e modalidade** (`technique_items`): grupos e itens com profundidade livre. Todo nó tem `knowledge_type` teórico ou prático; o tipo é escolhido no grupo principal e herdado por tudo que está dentro dele (D9). Itens não são apagados: inativar um grupo inativa o que está dentro, e o histórico continua apontando para o item.
3. **Currículo cumulativo calculado** (`rank_curriculum_entries`): cada faixa guarda só os próprios ajustes (incluir item, mudar o nível exigido ou deixar de cobrar com `required_level = NULL`). O currículo efetivo de uma faixa é calculado em `CurriculumService::effective` somando as faixas anteriores da modalidade pela `sort_order` (D14). Assim, mudar uma faixa baixa reflete nas seguintes sem cópia.
4. **Escala única de 4 níveis** (`AssessmentLevel`): não iniciado, em desenvolvimento, adequado e dominado (D2). O currículo só exige de 2 a 4.
5. **Modelo pronto opcional** em `resources/data/technique-templates.json`, no mesmo padrão do ADR-010: validado por `TechniqueTemplateCatalog`, copiado para a associação em transação, auditado (`technique_template.applied`) e idempotente pelo caminho do item. Reaplicar só inclui o que falta e nunca sobrescreve ajustes do currículo. As faixas são encontradas pelo `name` da graduação (o modelo do judô usa os nomes do template `judo_cbj_2023`); faixas ausentes são informadas, não criadas.
6. **Registros de avaliação só de inclusão** (fases seguintes): o nível atual do aluno num item é o registro mais recente; correção gera novo registro.
7. **Permissões**: `curriculum.manage` (associação) nesta fase; a leitura usa `martial_art.view`. As fases seguintes acrescentam `assessment.view`, `assessment.manage`, `quiz.manage` e `progression.report`.

## Consequências

- o catálogo é da associação, mesmo quando a modalidade é global; duas associações nunca compartilham itens;
- excluir uma graduação ou um item com exigências fica bloqueado pelas chaves estrangeiras (`restrictOnDelete`);
- o cálculo do currículo efetivo lê todas as faixas anteriores; o volume é pequeno (dezenas de faixas, centenas de itens) e não exige cache;
- o conteúdo do modelo do judô é uma sugestão: precisa de revisão da associação antes do uso real.
