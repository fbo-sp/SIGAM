# Acompanhamento técnico (Progression)

## Objetivo e limites

Acompanha a preparação do aluno para o próximo exame de graduação, separando Conhecimento Teórico e Conhecimento Prático. Decisão de arquitetura: ADR-014. Requisito e plano por fases: `/mnt/project-files/sigam/requisito-acompanhamento-graduacao.md` e `plano-acompanhamento-graduacao.md`.

Entregue até agora: fase 1 (catálogo de técnicas e currículo por graduação, com modelo do judô) e fase 2 (avaliação pelo professor e acompanhamento do aluno). Técnicas da aula, quiz, portal, painéis e lembrete são as fases 3 a 7 e ainda não existem. O exame de graduação em si está fora do requisito.

## Telas

Em **Modalidades**, cada modalidade tem os botões **Técnicas** e **Currículo por graduação**.

- `modalidades/{martialArt}/tecnicas`: árvore do catálogo em duas seções (Teórico e Prático). Com `curriculum.manage`: aplicar ou completar com o modelo, incluir grupo ou item, editar (mover de grupo, renomear, trocar tipo, descrição, link de vídeo, ordem), inativar e reativar.
- `modalidades/{martialArt}/curriculo?graduacao={rank}`: currículo efetivo da graduação escolhida. Cada linha mostra o nível exigido e a origem: "Novo nesta graduação", "Nível alterado nesta graduação" ou "Herdado de {faixa}". Com `curriculum.manage`: incluir ou alterar exigência, deixar de cobrar a partir da faixa e desfazer o ajuste da faixa.

### Acompanhamento do aluno (fase 2)

- `operacao/dojos/{dojo}/alunos/{student}/acompanhamento?modalidade=`: próxima graduação, o que falta (Teórico e Prático), idade e tempo mínimo, radar por grupo, currículo completo com o nível atual e a evolução (registros de avaliação). Entram por aqui o gestor do dojo (`assessment.view`, aluno regular do dojo) e o professor que avalia o aluno. Na turma, cada aluno ganha o botão "Avaliar" (professor da turma) ou "Acompanhamento" (gestor); na lista de alunos do dojo, "Acompanhamento".
- `operacao/dojos/{dojo}/alunos/{student}/acompanhamento/{martialArt}/avaliar`: formulário para celular com os itens da próxima graduação agrupados, botões de 1 a 4, data (não futura) e observação. Só o que mudou é gravado.
- `associacoes/{association}/alunos/{student}/acompanhamento`: a mesma consulta para a associação (`assessment.view`), com link na ficha do aluno. A associação não avalia.

## Regras

- O tipo de conhecimento é escolhido no grupo principal; itens internos herdam e, quando o grupo muda de tipo, os itens dentro mudam junto.
- Só grupos recebem filhos; um grupo com filhos não vira item; um grupo não entra dentro de si mesmo; nomes são únicos (sem diferenciar maiúsculas) dentro do mesmo grupo.
- Inativar um grupo inativa tudo dentro dele. Reativar um item exige o grupo ativo. Nada é apagado; item inativo some do currículo efetivo, mas a exigência gravada continua.
- O currículo de uma faixa é o que é cobrado no exame para chegar a ela. Ele herda as faixas anteriores da mesma modalidade (ordem `sort_order`). `required_level` 2 a 4 inclui ou altera; `NULL` deixa de cobrar a partir daquela faixa. "Desfazer ajuste" remove a linha da própria faixa e o item volta a seguir as anteriores.
- O modelo é idempotente pelo caminho do item ("Nage-waza > Te-waza > ...") e pela chave única de exigência; não sobrescreve ajustes. Faixas do modelo que não existem na modalidade são informadas na mensagem.
- Links de vídeo aceitam só `http` e `https`.
- Avalia quem é professor principal ou auxiliar vigente (`class_group_teachers.role_type = assistant`) de turma ativa da modalidade no dojo, com matrícula ativa do aluno, e tem `assessment.manage` na concessão de professor do dojo. Substituto eventual não avalia.
- O nível atual é o último registro gravado por item; sem registro, conta como "Não iniciado". O item falta quando o nível atual é menor que o exigido.
- Próxima graduação: a primeira graduação ativa com `sort_order` maior que a atual; sem graduação atual, a primeira da modalidade.
- Tempo mínimo conta a partir do último registro da faixa atual em `student_rank_history` (ou do início na modalidade).
- Radar: eixos são os grupos principais de cada tipo; com um só grupo principal (o bloco teórico do modelo), os grupos dentro dele. Valor é a média do nível atual e do exigido. Com menos de três eixos, mostra só a tabela.

## Serviços

- `TechniqueCatalogService`: `items`, `paths`, `flatten`, `inactiveIds`, `save`, `setStatus`, `applyTemplate`.
- `CurriculumService`: `effective`, `set`, `restore`, `ownEntries`.
- `AssessmentService`: `assessorTeacherId`, `teachesClass`, `canViewInDojo`, `current`, `history`, `record`.
- `StudentProgressService`: `martialArts`, `snapshot` (próxima graduação, linhas do currículo com nível atual, o que falta, requisitos e radar).
- `TechniqueTemplateCatalog`: lê e valida `resources/data/technique-templates.json` (chaves obrigatórias, tipo no grupo principal, nomes únicos entre irmãos, caminhos e níveis do currículo).

## Auditoria

`technique_item.saved`, `technique_item.status_changed`, `technique_template.applied`, `rank_curriculum.saved`, `rank_curriculum.restored`, `technique_assessment.recorded`.

## Modelo do judô

Chave `judo_kodokan_2026`: fundamentos (Kihon), Nage-waza (Tachi-waza e Sutemi-waza pela classificação Kodokan), Ouyou-waza (renraku, renzoku, kaeshi), Katame-waza, Ate-waza, Nage-no-kata e Katame-no-kata, mais o bloco teórico. O programa por faixa vai de 11º Kyū a 1º Dan e é uma sugestão que a associação revisa.

## Testes

`TechniqueCatalogTest`: validade do modelo frente ao template de graduações, aplicação idempotente que preserva ajustes, faixas ausentes, herança (novo, alterado, herdado, retirado, restaurado), item inativo, regras da árvore, isolamento entre associações e permissões.

`TechniqueAssessmentTest`: gravação só do que mudou e histórico preservado, o que falta e radar, idade e tempo mínimo, quem avalia (principal, auxiliar, outro professor, auxiliar encerrado, outra modalidade), data futura e item inativo, gestor do dojo e associação só consultam, isolamento entre associações e aluno com duas modalidades.
