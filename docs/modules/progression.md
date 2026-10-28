# Acompanhamento técnico (Progression)

## Objetivo e limites

Acompanha a preparação do aluno para o próximo exame de graduação, separando Conhecimento Teórico e Conhecimento Prático. Decisão de arquitetura: ADR-014. Requisito e plano por fases: `/mnt/project-files/sigam/requisito-acompanhamento-graduacao.md` e `plano-acompanhamento-graduacao.md`.

Entregue: catálogo de técnicas e currículo por graduação com modelo do judô (fase 1), avaliação pelo professor e acompanhamento do aluno (fase 2), técnicas trabalhadas na aula (fase 3), quiz teórico (fase 4), progresso e quiz no portal (fase 5), painéis (fase 6) e lembrete de avaliação (fase 7). O exame de graduação em si está fora do requisito.

## Telas

Em **Modalidades**, cada modalidade tem os botões **Técnicas** e **Currículo por graduação**.

- `modalidades/{martialArt}/tecnicas`: árvore do catálogo em duas seções (Teórico e Prático). Cada grupo abre e fecha pela seta e mostra quantas técnicas tem abaixo, em todos os níveis; item inativo aparece riscado. No topo, filtro por nome (abre os ramos que combinam) e "Expandir tudo"/"Recolher tudo" (`public/js/technique-tree.js`; sem JavaScript a árvore funciona com `<details>`). O menu "⋯" de cada linha tem Editar, Inativar/Reativar e, nos grupos ativos, "Incluir aqui dentro" (`?dentro={grupo}`, abre o cadastro com o grupo escolhido). Com `curriculum.manage`: aplicar ou completar com o modelo, incluir grupo ou item, editar (mover de grupo, renomear, trocar tipo, descrição, link de vídeo, ordem), inativar e reativar.
- `modalidades/{martialArt}/curriculo?graduacao={rank}`: currículo efetivo da graduação escolhida. Cada linha mostra o nível exigido e a origem: "Novo nesta graduação", "Nível alterado nesta graduação" ou "Herdado de {faixa}". Com `curriculum.manage`: incluir ou alterar exigência, deixar de cobrar a partir da faixa e desfazer o ajuste da faixa.

### Acompanhamento do aluno (fase 2)

- `operacao/dojos/{dojo}/alunos/{student}/acompanhamento?modalidade=`: próxima graduação, o que falta (Teórico e Prático), idade e tempo mínimo, radar por grupo, currículo completo com o nível atual e a evolução (registros de avaliação). Entram por aqui o gestor do dojo (`assessment.view`, aluno regular do dojo) e o professor que avalia o aluno. Na turma, cada aluno ganha o botão "Avaliar" (professor da turma) ou "Acompanhamento" (gestor); na lista de alunos do dojo, "Acompanhamento".
- `operacao/dojos/{dojo}/alunos/{student}/acompanhamento/{martialArt}/avaliar`: formulário para celular com os itens da próxima graduação agrupados, botões de 1 a 4, data (não futura), observação interna e, por item, uma "Dica para o aluno" (até 300 caracteres) mostrada no portal. O nível atual aparece tracejado e nada vem marcado: cada item marcado vira um registro, inclusive quando o nível se mantém (Fabio, 2026-10-27), e reenviar o mesmo formulário não duplica (item, data, nível e dica iguais são ignorados). Filtros: Todos, Aguardando avaliação, Precisa melhorar e Ainda não treinado.
- `operacao/dojos/{dojo}/aulas/{session}/avaliar`: "Avaliar técnicas desta aula", na chamada encerrada com técnicas marcadas, para o professor principal ou auxiliar da turma (`SessionAssessmentService`). Mostra, por técnica marcada (o grupo marcado conta para os itens abaixo dele), os alunos presentes que o professor avalia e que têm o item no currículo da próxima faixa; a data é a da aula.
- Situação de cada item da próxima faixa (`StudentProgressService::snapshot`, só no detalhe): No nível; Precisa melhorar (última avaliação acima de Não iniciado e abaixo do pedido); Aguardando avaliação (treinado em aula com presença, sem avaliação depois); Ainda não treinado (sem treino desde a graduação atual, contada da última promoção registrada ou do início na modalidade). "O que falta" no portal agrupa por situação e grupo; o atalho mostra "N não treinado(s) · N a melhorar"; a ficha do professor mostra a situação em cada item.
- Linha do tempo (`StudentProgressService::timeline`, portal em Evolução e ficha do professor): gráfico geral com a porcentagem do currículo da próxima faixa no nível pedido desde a graduação atual e, por item, os degraus de cada avaliação, a linha tracejada do pedido e os dias de treino em aula (`x-timeline-chart`, SVG sem bibliotecas).
- `associacoes/{association}/alunos/{student}/acompanhamento`: a mesma consulta para a associação (`assessment.view`), com link na ficha do aluno. A associação não avalia.

### Técnicas da aula (fase 3)

Na tela da aula (`operacao/dojos/{dojo}/aulas/{session}`), o bloco "Técnicas trabalhadas" usa a mesma árvore do catálogo (só itens ativos), com uma caixa de marcação em cada técnica. Os grupos só ganham caixa quando a associação liga "Permitir marcar o grupo inteiro nas técnicas da aula" em Configurações do acompanhamento (`progression_settings.allow_group_pick`, desligado por padrão, decisão de Fabio em 28/10/2026); aí tocar no nome do grupo marca o grupo inteiro e a seta abre as técnicas dele. Desligada a opção, o servidor recusa grupo novo, mas o grupo já marcado numa aula continua valendo, aparece com a etiqueta "grupo inteiro" e pode ser desmarcado. Os grupos principais e os que têm algo marcado já abrem, e o grupo mostra quantas técnicas estão marcadas dentro. Grava em `POST aulas/{session}/tecnicas`. Na ficha de acompanhamento, "Trabalhado nas aulas" mostra, nos últimos 90 dias, em quantas aulas com presença do aluno cada grupo foi trabalhado.

### Quiz (fases 4 e 5)

- `modalidades/{martialArt}/quiz` (`quiz.manage`): perguntas por graduação, com 2 a 5 alternativas, a correta, o item teórico avaliado (opcional) e uma explicação mostrada depois da resposta. Inativar tira a pergunta dos próximos sorteios.
- `acompanhamento/configuracoes` (`curriculum.manage`): perguntas por quiz, nota mínima da prova oficial e semanas do lembrete.
- No acompanhamento do aluno no dojo, o professor que avalia libera ("Liberar prova oficial") ou cancela a liberação ainda não iniciada; a ficha mostra as provas oficiais e quantos treinos o aluno fez.
- No portal (`portal/alunos/{student}/progresso/quiz`, atalho "Quiz teórico" do progresso), o aluno ou responsável treina quando quiser e faz a prova oficial liberada. A tela da tentativa mostra as perguntas com as alternativas na ordem sorteada; o resultado mostra acertos, a correta e a explicação.

### Painéis (fase 6)

- `operacao/dojos/{dojo}/acompanhamento` (`progression.report`): filtros de modalidade, turma e período (padrão 90 dias, até um ano). Números do período, radar médio por tipo de conhecimento, "precisa x trabalhado" por grupo (alunos abaixo do exigido e aulas em que o grupo foi trabalhado, destacando grupo com alunos abaixo e nenhuma aula) e os alunos com mais itens a trabalhar, com link para a ficha. Gestor vê todas as turmas ativas; professor, só as dele.
- `operacao/dojos/{dojo}/turmas/{classGroup}/cobertura` (`progression.report`, mesma regra de turmas do painel; `?de=&ate=`, padrão 90 dias): cobertura do currículo da turma (`CurriculumCoverageService`). Um item conta como trabalhado se foi marcado em alguma aula da turma no período (não cancelada nem reagendada, até hoje). "Visão geral da turma" junta o currículo cumulativo de todas as próximas faixas dos alunos, com barra geral, três gráficos e o que ainda não foi trabalhado. Os gráficos são: cobertura por grupo (barras); distribuição por grupo (colunas empilhadas por mês com o número de aulas em que cada grupo prático foi trabalhado, o bloco teórico como um grupo só, até sete grupos na ordem do catálogo e o resto em "Outros"); e mapa aula a aula (itens marcados nas últimas oito aulas do período, até 15, os mais frequentes); "Por faixa" tem uma linha expansível para cada próxima faixa, com alunos, barras por grupo e o que falta. Cor da barra: verde a partir de 70%, amarelo de 40% a 69%, vermelho abaixo de 40%. Alunos na última faixa ficam fora. O painel do professor mostra o resumo geral por turma.
- `associacoes/{association}/acompanhamento` (`progression.report`): por dojo e por professor principal, turmas, alunos, prontos no currículo, média de itens que faltam por aluno (Teórico e Prático), aulas com técnicas registradas e, por professor, avaliações no período.

## Regras

- O tipo de conhecimento é escolhido no grupo principal; itens internos herdam e, quando o grupo muda de tipo, os itens dentro mudam junto.
- Só grupos recebem filhos; um grupo com filhos não vira item; um grupo não entra dentro de si mesmo; nomes são únicos (sem diferenciar maiúsculas) dentro do mesmo grupo.
- Inativar um grupo inativa tudo dentro dele. Reativar um item exige o grupo ativo. Nada é apagado; item inativo some do currículo efetivo, mas a exigência gravada continua.
- O currículo de uma faixa é o que é cobrado no exame para chegar a ela. Ele herda as faixas anteriores da mesma modalidade (ordem `sort_order`). `required_level` 2 a 4 inclui ou altera; `NULL` deixa de cobrar a partir daquela faixa. "Desfazer ajuste" remove a linha da própria faixa e o item volta a seguir as anteriores.
- O modelo é idempotente pelo caminho do item ("Nage-waza > Te-waza > ...") e pela chave única de exigência; não sobrescreve ajustes. Faixas do modelo que não existem na modalidade são informadas na mensagem.
- Quando o catálogo já tem itens, a tela oferece "Atualizar também o currículo que veio do modelo" (`update_curriculum`). Com ela, as exigências nunca gravadas pela tela de currículo (sem `rank_curriculum.saved` na auditoria) passam a seguir a versão atual do modelo: mudam de nível (`rank_curriculum.template_updated`) ou saem (`rank_curriculum.template_removed`), com o valor anterior na auditoria. Ajustes da associação ficam como estão. Sem a opção, reaplicar só inclui o que falta.
- Links de vídeo aceitam só `http` e `https`.
- Avalia quem é professor principal ou auxiliar vigente (`class_group_teachers.role_type = assistant`) de turma ativa da modalidade no dojo, com matrícula ativa do aluno, e tem `assessment.manage` na concessão de professor do dojo. Substituto eventual não avalia.
- O nível atual é o último registro gravado por item; sem registro, conta como "Não iniciado". O item falta quando o nível atual é menor que o exigido.
- Próxima graduação: a primeira graduação ativa com `sort_order` maior que a atual; sem graduação atual, a primeira da modalidade.
- Tempo mínimo conta a partir do último registro da faixa atual em `student_rank_history` (ou do início na modalidade).
- Técnicas da aula: mesma janela e permissão da chamada (a partir do dia da aula, chamada aberta, aula não cancelada nem reagendada, `attendance.manage`). Só itens ativos do catálogo da modalidade da turma. Desmarcar preenche `removed_at`.
- Sorteio do quiz: perguntas ativas da graduação seguinte do aluno; se faltarem, completa com as das graduações anteriores. O treino retoma a tentativa em andamento. A prova oficial é da graduação da liberação, uma tentativa por liberação; abrir de novo retoma a mesma tentativa.
- Correção só no servidor; alternativa fora das sorteadas conta como sem resposta. Reenviar uma tentativa finalizada não muda o resultado. Nota = acertos / perguntas, arredondada; aprovado quando a nota é maior ou igual à mínima gravada na tentativa.
- Prova oficial aprovada: cada item teórico ativo ligado às perguntas sorteadas que está abaixo de "Adequado" recebe um registro "Adequado" em nome do professor que liberou, com a observação "Quiz oficial: nota N%". Nunca rebaixa. Reprovação não grava nada; o professor libera de novo.
- Pergunta já respondida mantém as alternativas; enunciado, explicação, graduação e item podem mudar.
- Lembrete: um aviso por turma ativa, professor (principal ou auxiliar vigente, com acesso de professor no dojo) e período de N semanas, quando há aluno ativo matriculado há mais de N semanas sem avaliação na modalidade nas últimas N semanas.
- Radar: eixos são os grupos principais de cada tipo; com um só grupo principal (o bloco teórico do modelo), os grupos dentro dele. Valor é a média do nível atual e do exigido. Com menos de três eixos, mostra só a tabela.

## Serviços

- `TechniqueCatalogService`: `items`, `paths`, `tree`, `inactiveIds`, `save`, `setStatus`, `applyTemplate`.
- `CurriculumService`: `effective`, `set`, `restore`, `ownEntries`.
- `AssessmentService`: `assessorTeacherId`, `teachesClass`, `canViewInDojo`, `current`, `history`, `record`.
- `StudentProgressService`: `martialArts`, `snapshot` (próxima graduação, linhas do currículo com nível atual, o que falta, requisitos e radar), `axes`, `worked`.
- `SessionTechniqueService`: `canMark`, `marked`, `sync`, `workedByStudent`.
- `QuizService`: `saveQuestion`, `setQuestionStatus`, `saveSettings`, `release`, `cancelRelease`, `openReleases`, `start`, `submit`, `attempts`, `summary`.
- `ProgressionPanelService`: `dojoClasses`, `panel`, `comparison`.
- `NotificationGenerator::assessmentsDue`: lembrete `assessment.due`.
- `TechniqueTemplateCatalog`: lê e valida `resources/data/technique-templates.json` (chaves obrigatórias, tipo no grupo principal, nomes únicos entre irmãos, caminhos e níveis do currículo).

## Auditoria

`technique_item.saved`, `technique_item.status_changed`, `technique_template.applied`, `rank_curriculum.saved`, `rank_curriculum.restored`, `rank_curriculum.template_updated`, `rank_curriculum.template_removed`, `technique_assessment.recorded`, `class_session.techniques_saved`, `quiz_question.saved`, `quiz_question.status_changed`, `progression_settings.saved`, `quiz_release.created`, `quiz_release.cancelled`, `quiz_attempt.started` e `quiz_attempt.finished` (só a prova oficial; treino não é auditado).

## Modelo do judô

Chave `judo_kodokan_2026`: fundamentos (Kihon), Nage-waza (Tachi-waza e Sutemi-waza pela classificação Kodokan), Ouyou-waza (renraku, renzoku, kaeshi), Katame-waza, Ate-waza, Nage-no-kata e Katame-no-kata, mais o bloco teórico. O programa por faixa vai de 11º Kyū a 1º Dan e é uma sugestão que a associação revisa. O modelo traz também perguntas de quiz por faixa (lista `quiz`), ligadas aos itens teóricos; reaplicar não duplica perguntas com o mesmo enunciado na mesma faixa.

Versão 2 (2026-10-25), revisada pelo responsável técnico: 55 itens novos encaixados nos grupos existentes (amarrar a faixa, fases kuzushi/tsukuri/kake, fusegi em pé, três osaekomi kuzure/makura, 20 renraku-waza, 14 kaeshi-waza) e o grupo novo `Katame-waza > Ne-waza (viradas, escapadas e guarda)` com 15 itens; currículo refeito pelo checklist de troca de faixa da CBJ, com as faixas intermediárias propostas, e 376 perguntas novas de quiz. Em seguida, 108 perguntas escritas para as faixas iniciais levaram o banco a 10 na cinza, 20 na cinza/azul, 40 na azul/amarela e 50 na amarela/laranja, cada faixa cobrindo o currículo dela e o das anteriores (521 perguntas no total). Cada coluna do currículo é o que se exige para chegar àquela faixa. Associações que já aplicaram a versão 1 recebem a versão 2 reaplicando o modelo com a opção de atualizar o currículo.

## Testes

`TechniqueCatalogTest`: validade do modelo frente ao template de graduações, aplicação idempotente que preserva ajustes, atualização do currículo do modelo que mantém ajustes da associação, faixas ausentes, herança (novo, alterado, herdado, retirado, restaurado), item inativo, regras da árvore, isolamento entre associações e permissões.

`SessionTechniquesTest`, `QuizTest`, `ProgressionPanelTest` e `AssessmentReminderTest`: ver `docs/TESTING.md`.

`TechniqueAssessmentTest`: cada item marcado gravado sem duplicar, dica para o aluno, histórico preservado, situação de cada item (treinado, avaliado, não treinado), avaliação pela aula encerrada, linha do tempo, o que falta e radar, idade e tempo mínimo, quem avalia (principal, auxiliar, outro professor, auxiliar encerrado, outra modalidade), data futura e item inativo, gestor do dojo e associação só consultam, isolamento entre associações e aluno com duas modalidades.
