# Portal do aluno e responsável

## Objetivo e experiência

O portal reúne, em uma tela responsiva, dados próprios e de dependentes autorizados: turmas, frequência, cobranças e pagamentos, documentos, graduações, filiações e campeonatos. A troca de dependente permanece visível no topo e as seções têm atalhos adequados para celular.

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
- HTTP: `app/Http/Controllers/Portal/PortalController.php` e `app/Http/Requests/Portal/`;
- interface: `resources/views/portal/` e `public/css/modules.css`;
- rotas: grupo `portal.*` em `routes/web.php`;
- testes: `tests/Feature/PortalAccessTest.php`.

## Manutenção futura

Novos dados devem partir do aluno resolvido pelo serviço, nunca apenas de um ID do request. Novos campos editáveis exigem avaliação explícita de titularidade, validação, auditoria minimizada e testes de responsável não legal. Documentos ligados apenas à pessoa não devem aparecer automaticamente: o vínculo ao aluno é a decisão explícita de exposição.
