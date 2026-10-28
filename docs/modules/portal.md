# Portal do aluno e responsável

## Objetivo e experiência

O portal funciona como um aplicativo: um início curto e um assunto por subtela.

- Entrada: quem só tem o acesso de aluno ou responsável (`portal.access` sem `dashboard.association|dojo|teacher`) vai do login e do "Início" direto ao portal; professores e gestores continuam no painel de trabalho e chegam pelo link "Meu portal". Em `portal/`, um aluno só vai direto ao início dele; o responsável com mais de um aluno escolhe quem consultar.
- Início (`portal/alunos/{student}`): nome, foto, modalidade, faixa e dojo; indicadores de próxima aula, frequência no mês (presença, reposição e experimental sobre as chamadas do mês), mensalidade (em dia, saldo em aberto ou vencidas) e quiz (prova oficial liberada); grade de atalhos.
- Subtelas (`portal/alunos/{student}/{secao}`, com `turmas`, `frequencia`, `financeiro`, `campeonatos`, `historico`, `documentos` e `dados`) mais o progresso técnico: título, botão "← Início" e só o conteúdo do assunto. "Meus dados" mostra o cadastro e o formulário de contatos.
- No celular, uma barra inferior fixa leva a Início, Frequência, Financeiro e Mais (janela com os demais atalhos). 
- Mensalidade que não se aplica (`PortalAccessService::financeVisibility`): o indicador de mensalidade só aparece quando o aluno tem plano (`student_plans`) ativo e vigente hoje e nenhuma gratuidade aprovada cobrindo hoje. Isso cobre associação, dojo ou turma sem mensalidade (não há plano para o aluno) e o bolsista. O atalho e a aba Financeiro aparecem quando há mensalidade, cobrança em aberto ou cobrança não cancelada com vencimento nos últimos 12 meses (por exemplo, inscrição em campeonato); sem nada disso, a aba da barra inferior vira Turmas. Só muda o que o portal mostra; as telas da gestão não mudam.
- A troca de aluno do responsável fica no topo de todas as telas e mantém a subtela aberta.

Cada modalidade tem o "Progresso técnico" (`portal/alunos/{student}/progresso`): começa pelo Perfil técnico (radares do nível atual contra o pedido pela próxima graduação; sem próxima graduação ou sem currículo, uma mensagem explica) e, abaixo, quatro atalhos com resumo que abrem subtelas com "← Progresso" (`progresso/quiz`, `falta`, `aulas` e `evolucao`): Quiz teórico, O que falta (requisitos do exame e itens a trabalhar agrupados por grupo de técnicas, recolhidos, com a contagem de cada grupo), Nas aulas (o que foi trabalhado nos últimos 90 dias) e Evolução (avaliações, sem as observações do professor). O indicador Quiz do início abre direto o Quiz teórico (ver `progression.md`). A troca de dependente permanece visível no topo e as seções têm atalhos adequados para celular.

"Estudar" (`portal/alunos/{student}/estudar`, aba da barra inferior e atalho do início e do progresso, só com modalidade ativa) mostra todo o catálogo ativo da modalidade, decisão de Fabio em 28/10/2026, com busca e filtros Para melhorar (itens da próxima faixa abaixo do pedido ou com dica do professor), Próxima faixa, Já aprendidas e Todo o catálogo. Abre em Para melhorar quando há algum item nele. A página da técnica (`estudar/{item}`) traz vídeo embutido do YouTube (`youtube-nocookie`) ou Vimeo, a faixa em que o item entra no currículo, tradução, dica do professor, o que o professor avalia, erro comum, passo a passo, a situação do aluno, a ficha e técnicas do mesmo grupo. "Estudei esta técnica" grava um estudo por dia em `technique_study_logs` (`TechniqueStudyService::markStudied`), que o professor vê na ficha de acompanhamento; não muda a avaliação.

Não existe cadastro público. Uma conta só acessa o portal quando possui `portal.access`, `association_id`, `person_id` e vínculo válido com o aluno.

## Autorização relacional

`PortalAccessService` é a fonte única de autorização. O aluno deve pertencer à associação da conta e ser a própria pessoa do usuário ou possuir vínculo vigente em `student_guardians`. Conhecer um ID não concede acesso; recursos fora desse conjunto retornam 404. Responsáveis podem consultar dependentes ativos, mas somente o próprio aluno ou responsável legal altera contatos.

## Escritas permitidas

O portal atualiza apenas e-mail, telefone, celular e contato de emergência. Nome, CPF, nascimento, matrícula, graduação e situação não são aceitos. A atualização usa transação, lock da pessoa e auditoria que registra apenas os nomes dos campos alterados.

Inscrições são sempre de competidor. Pessoa, aluno, dojo e tipo de participação são derivados no servidor; `ChampionshipRegistrationService` continua validando janela, modalidade, histórico, filiação e duplicidade.

## Privacidade

Um documento aparece somente quando `documents.student_id` corresponde ao aluno autorizado. O arquivo permanece no disco privado, passa por rota autenticada e cada download é auditado. A permissão administrativa `document.download` não é concedida ao portal. O financeiro é somente leitura e não expõe observações internas.

## Mapa de código

- domínio: `app/Services/Portal/PortalAccessService.php`;
- HTTP: `app/Http/Controllers/Portal/PortalController.php`, `PortalProgressionController.php`, `PortalStudyController.php` e `app/Http/Requests/Portal/`;
- interface: `resources/views/portal/` e `public/css/modules.css`;
- rotas: grupo `portal.*` em `routes/web.php`;
- testes: `tests/Feature/PortalAccessTest.php`, `QuizTest.php` e `StudyAreaTest.php`.

## Manutenção futura

Novos dados devem partir do aluno resolvido pelo serviço, nunca apenas de um ID do request. Novos campos editáveis exigem avaliação explícita de titularidade, validação, auditoria minimizada e testes de responsável não legal. Documentos ligados apenas à pessoa não devem aparecer automaticamente: o vínculo ao aluno é a decisão explícita de exposição.
