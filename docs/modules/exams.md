# Exames de graduação

## Objetivo

Organizar os exames de faixa (pedido de Fabio em 30/10/2026). Proposta, respostas e telas aprovadas: `/mnt/project-files/sigam/exames/proposta-exames-graduacao.md` e as prévias na mesma pasta. A entrega é por etapas:

1. exame, banca, participantes e conteúdo por faixa com cobertura e justificativas, disponível;
2. lançamento de notas, quiz do exame, resultado e graduação no histórico esportivo;
3. painel com indicadores e gráficos;
4. certificado A4 com modelo editável e código de validação;
5. portal ("tudo": notas por item, médias e certificado) e ideias extras.

## Alcance

- associação: `/associacoes/{association}/exames`, com `exam.manage` (perfil Gestor da Associação); todos os exames da associação e todos os níveis;
- dojo: `/operacao/dojos/{dojo}/exames`, com `exam.manage` pela regra do dojo (`dojo_operator`); exames do dojo, das turmas do dojo e dos alunos do dojo, nunca o nível associação;
- banca: `/professor/exames`, para professor com perfil ativo e `exam.grade` em algum dojo (`dojo_teacher` ou `dojo_operator`). Vê rascunhos, abertos e encerrados em que está na banca: escolhido pelo nome ou, com "Professores das turmas dos alunos", principal ou auxiliar vigente de uma turma ativa em que algum inscrito tem matrícula ativa.

`ExamScope` monta o `ExamContext`; um exame fora do alcance responde 404. A banca só desmarca itens e escreve justificativas; dados, participantes, grupos, abrir e cancelar são dos gestores.

## Exame

`rank_exams`: nome, modalidade, nível (`association`, `dojo`, `class`, `student`), dojo/turma/aluno conforme o nível, justificativa (obrigatória no exame de um aluno), período, média mínima (padrão 7,0, de 0 a 10), "professores das turmas avaliam", quiz (ligado, perguntas por aluno, padrão da modalidade, e tempo limite opcional) e situação: `draft` (rascunho), `open` (aberto), `closed` (encerrado, etapa 2) ou `cancelled` (cancelado com motivo, continua guardado). A turma precisa ser da mesma modalidade. Modalidade e nível ficam travados depois que o exame tem participantes.

## Participantes

A tela lista os alunos ativos do nível com a modalidade ativa e uma próxima faixa ativa (`ExamCandidates`). Apto = idade mínima e tempo mínimo na faixa atual da próxima faixa cumpridos na data de início do exame; o tempo conta desde a última promoção registrada na faixa atual ou, sem ela, desde o início na modalidade. Dado que falta (sem nascimento ou sem data da faixa) aparece como "não verificado" e não impede. No primeiro acesso os aptos vêm marcados. Incluir quem não está apto pede motivo (decisão de Fabio). Desmarcar quem já estava inscrito muda a inscrição para `removed`, sem apagar. Um aluno só pode estar em um rascunho ou exame aberto por modalidade de cada vez. A inscrição guarda a faixa atual e a de destino do momento.

## Conteúdo por faixa

Ao salvar participantes, cada faixa de destino nova ganha o seu conteúdo a partir do currículo efetivo (`CurriculumService::effective`): itens práticos agrupados pelo grupo do catálogo e teóricos no grupo "Teoria". Vêm marcados os itens novos ou que subiram de nível na faixa; os herdados vêm desmarcados (decisão de Fabio). O gestor move itens entre grupos e cria grupos; a banca desmarca. Cada aluno será avaliado só no conteúdo da faixa dele; nota do item, média do grupo e média geral (média dos grupos) chegam na etapa 2.

Ao lado de cada item, "N/M aulas" (`ExamCoverageService`): M são as aulas com chamada finalizada, no nível do exame (associação e aluno: toda a associação; dojo: o dojo; turma: a turma), em que algum aluno daquela faixa de destino esteve presente desde a última graduação dele; N é em quantas delas o item foi marcado (marcar um grupo cobre os itens dele). Item marcado com 0 aulas exige justificativa para salvar e para abrir.

Com quiz ligado, cada faixa tem o item "Quiz do exame" (no grupo Teoria, movível) e a tela mostra o banco: perguntas ativas da própria faixa e das anteriores, como no sorteio do quiz oficial. Desligar o quiz desmarca o item.

## Abrir e cancelar

"Abrir exame" exige: pelo menos um aluno, pelo menos um item marcado em cada faixa, todas as justificativas de itens sem aula, banco do quiz com pergunta quando o quiz está ligado e o fim do período a partir de hoje. Depois de aberto, participantes e conteúdo ainda podem ser ajustados. Cancelar pede motivo.

## Auditoria

`rank_exam.created`, `rank_exam.updated`, `rank_exam.participants_saved`, `rank_exam.content_saved` (itens alterados e o nível de quem salvou), `rank_exam.group_created`, `rank_exam.opened` e `rank_exam.cancelled`.

## Testes

`RankExamsTest`: aptidão e motivo, montagem do conteúdo (novo marcado, herdado desmarcado, grupos, quiz em Teoria), cobertura só com chamada finalizada e desde a graduação, justificativa obrigatória, abrir, retirar participante sem apagar; alcance do gestor do dojo (sem nível associação, sem exames de fora), banca da turma (vê e desmarca, não abre), professor fora da banca (404), aluno em dois exames e cancelamento.
