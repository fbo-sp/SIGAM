# Changelog

## 2026-10-22 — Aulas passadas na turma e na importação

- a aba Aulas da turma no dojo ganha "Gerar aulas passadas" (`POST /operacao/dojos/{dojo}/turmas/{classGroup}/aulas/passadas`, `class.manage`): cria as aulas de um período até ontem pelos horários vigentes em cada data, sem duplicar (`SessionGenerator::generateForGroup`, chave única da aula). Se a data inicial é anterior ao início da turma, o início da turma e dos horários iniciais recua para essa data, auditado como `class_group.start_moved`; a geração audita `class_sessions.past_generated`;
- a importação de presenças cria a aula passada que ainda não existe quando a turma tem horário naquele dia da semana e início, dentro do período da turma; a linha fica em revisão com o aviso "A aula será criada pelo horário da turma". Data futura, data fora do período da turma ou sem horário correspondente ficam inválidas com a explicação;
- novos testes em `DojoClassHubTest` e `AssistedImportTest`.

## 2026-10-22 — Chamada só no dia da aula e reabertura pelo dojo

- a chamada de uma aula só pode ser registrada ou finalizada a partir do dia da aula (`ClassSession::isBeforeAttendanceDay`). A regra fica em `AttendanceService::save` e vale para professor, gestor do dojo, associação e importação; a tela da aula futura mostra quando a chamada abre e esconde o formulário;
- `attendance.reopen` entra em `OperationalScope::DOJO_PERMISSIONS` e `TEACHER_PERMISSIONS`. O `db:seed` acrescenta a permissão aos papéis `dojo_operator` e `dojo_teacher`: o gestor reabre as chamadas do dojo e o professor reabre as das aulas dele, sempre com motivo (10 caracteres ou mais) e auditoria `attendance.reopened`;
- nova rota `POST /operacao/dojos/{dojo}/aulas/{session}/reabrir` (`operation.attendance.reopen`);
- novo teste em `DojoClassHubTest`; `Sprint18Test` passa a registrar a chamada no dia da aula.

## 2026-10-22 — Vínculo com o dojo libera o acesso do professor

- vincular o professor ao dojo (`/operacao/dojos/{dojo}/professores`) passa a criar também a concessão "Professor do dojo" (`dojo_teacher`) no mesmo período, quando o professor tem conta ativa ou pendente na associação; antes eram dois cadastros separados e, sem a concessão, o professor não via as aulas para fazer a chamada;
- encerrar o vínculo revoga as concessões de professor ainda ativas do usuário naquele dojo; concessões manuais existentes não são duplicadas;
- professor sem conta recebe o acesso ao aceitar o convite (`TeacherInvitationService::accept`);
- novo serviço `App\Services\Access\TeacherDojoAccess`, auditado como `dojo_access.granted|revoked` com origem `dojo_teacher_link`/`dojo_teacher_unlinked`;
- novo comando `sigam:access:sync-teacher-dojos` (simulação por padrão; `--apply` cria as concessões que faltam para vínculos vigentes ou futuros, sem duplicar). O `deploy/instalar.sh` roda o comando com `--apply` depois do `db:seed`;
- novo `TeacherDojoAccessTest`.

## 2026-10-22 — Turma: ícone de edição à esquerda e promoção de auxiliar

- na página da turma no dojo e na lista de alunos do dojo, o ícone de edição fica à esquerda do nome, com a dica "Editar dados do aluno" ou "Editar dados do professor", para não ser confundido com ações sobre a matrícula ou a designação;
- escolher como principal um professor que é auxiliar da turma encerra a designação de auxiliar (término no dia anterior, ou no próprio início se ele ainda não começou), auditada como `class_group_teacher.ended` com motivo `promoted_to_main`. As aulas anteriores continuam cobertas pela designação encerrada, então a chamada pendente segue acessível, e as próximas aulas passam a ser dele como principal;
- o professor principal continua sem poder ser designado auxiliar da mesma turma;
- novo teste em `DojoClassHubTest`.

## 2026-10-22 — Painel do professor abre a chamada no dojo

- "Próximas aulas" no painel do professor levava à tela de aulas da associação, que exige `session.view` associativo; o professor, que só tem acesso pelo dojo (ADR-006), recebia 403;
- a lista passa a vir de `OperationalScope::sessions` nos dojos em que o professor tem concessão vigente, inclui aulas como auxiliar ou substituto e abre `/operacao/dojos/{dojo}/aulas/{session}`;
- sem concessão de dojo, a lista explica que é preciso pedir acesso ao gestor;
- novo teste em `NotificationsAndDashboardTest`.

## 2026-10-22 — Atalho para editar dados da pessoa nas listas

- listas de Pessoas, Alunos e Professores da associação, alunos e professores do dojo e abas da turma ganham um ícone de lápis que abre a edição dos dados da pessoa; a ficha do aluno ganha "Editar dados pessoais" e o ícone ao lado de cada responsável;
- o ícone só aparece para quem pode editar: na associação, `person.manage`; no dojo, o gestor com `student.manage` edita os alunos com vínculo vigente na unidade;
- nova tela `/operacao/dojos/{dojo}/alunos/{student}/dados`: o gestor do dojo corrige nome, nome social, nascimento, contatos, endereço e contato de emergência. CPF, documento, situação e observações continuam com a associação, e aluno que também é professor é editado só pela associação. A gravação passa por `PersonService::update` e audita `person.updated`;
- a edição da associação aceita `voltar` (só caminho interno) para retornar à lista de origem depois de salvar ou cancelar;
- novo `PersonEditShortcutTest`.

## 2026-10-21 — Turma dentro do dojo

- nova página da turma em `/operacao/dojos/{dojo}/turmas/{turma}`, com abas Alunos, Professores, Horários e Aulas; a lista de turmas do dojo ganha link, situação e o botão "Nova turma";
- o gestor do dojo (`dojo_operator`) passa a criar e editar turmas e a matricular e encerrar matrículas no próprio dojo (`class.manage` e `enrollment.manage` entram em `OperationalScope::DOJO_PERMISSIONS`; o `db:seed` acrescenta as permissões ao papel existente);
- editar turma: nome, descrição, faixa etária, capacidade (não fica abaixo das matrículas ativas), meta de aulas, término e situação. Trocar o professor principal atualiza os horários dele e as aulas futuras sem chamada; definir término ou inativar cancela as aulas futuras sem chamada e é recusado se já houver chamada depois da data;
- encerrar matrícula grava `ended`, data e motivo, sem apagar nada: o aluno continua nas chamadas até a data de término e sai das seguintes. Não aceita data anterior a uma presença registrada;
- professores auxiliares: o gestor designa professores vinculados ao dojo e habilitados na modalidade (`class_group_teachers`, papel `assistant`); o auxiliar vê as aulas da turma e faz a chamada, sem matricular nem editar;
- cadastro direto de aluno pode matricular em turmas do dojo escolhido (opcional, só ao ativar, exige `enrollment.manage`); sem turma, a mensagem final avisa que o aluno não aparece na chamada;
- a ficha do aluno mostra as turmas, com matricular e encerrar; a lista de alunos do dojo mostra a turma ou "Sem turma";
- a chamada sem alunos matriculados explica o motivo e leva à matrícula;
- matrícula em turma sem faixa etária não exige mais a data de nascimento;
- novo `DojoClassHubTest`.

## 2026-10-21 — Mensagens de validação em português

- o sistema não tinha arquivos de tradução: regras sem mensagem própria apareciam como chave, por exemplo `validation.required_unless` ao cadastrar aluno com responsável sem informar a relação;
- novo `lang/pt_BR/validation.php` com todas as mensagens padrão, nomes dos campos e mensagens próprias para as regras condicionais do cadastro direto; `lang/pt_BR/pagination.php` e `lang/pt_BR.json` traduzem a paginação;
- o formulário de cadastro direto passa a mostrar o erro ao lado dos campos do responsável e de e-mail e celular do aluno;
- nos cadastros diretos de aluno e professor, cada opção de "Nova pessoa", "Pessoa existente" e "Como informar" o responsável mostra só os campos que usa; os campos ocultos vão desabilitados e não são enviados (sem JavaScript, todos aparecem como antes);
- novo teste garante a mensagem traduzida e exibida na tela.

## 2026-10-20 — Convite de professor pelo administrador do sistema

- o cadastro direto de professor com envio de convite devolvia 403 para o administrador do sistema, porque `TeacherInvitationService` exigia `users.association_id` igual à associação da rota, e esse administrador não tem associação;
- a verificação passa a seguir o padrão de `AccessAdministration`: `association_id` só recusa quando aponta para outra associação, e a autorização vem do `hasPermission` (ADR-006);
- novos testes cobrem o administrador do sistema e o gestor de outra associação;
- o teste de troca de horário de turma (`Sprint18Test`) passa a fixar a data, porque falhava a partir de 20/10/2026.

## 2026-10-17 — Script de instalação para hospedagem compartilhada

- novo `deploy/instalar.sh`, incluído no pacote de publicação: confere PHP, extensões e `.env`; prepara `storage`; gera `APP_KEY` só na primeira instalação; roda migrations, seed, caches e `sigam:production:check`;
- em atualização, entra em manutenção e só sai quando a verificação de produção passa;
- não usa `optimize:clear`, que falha antes das migrations porque o cache fica no banco;
- `DEPLOYMENT.md` descreve o script.

## 2026-10-16 — Pacote de publicação

- novo workflow "Pacote de publicação" (`.github/workflows/release.yml`) gera um zip com código e `vendor` de produção para hospedagem compartilhada sem Composer, com hash SHA-256 e `VERSAO.txt`;
- tags `v*` publicam o pacote na página de Releases; a execução manual com o campo `versao` cria a tag e a Release, e sem ele gera só o artefato;
- o workflow confere o pacote antes de compactar (autoload, ausência de `.env`, testes e dependências de desenvolvimento, `artisan` inicializando);
- `.env`, testes, Docker, scripts e arquivos de desenvolvimento ficam fora do pacote;
- `DEPLOYMENT.md` descreve o uso;
- `symfony/yaml` atualizado de 8.1.5 para 8.1.9 (alerta CVE-2026-109413 apontado pelo `composer audit`).

## 2026-10-12 — Compatibilidade com MySQL 5.7 (HostGator)

- CI passa a rodar a suíte também em MySQL 5.7, a versão da hospedagem prevista;
- 13 datas obrigatórias de negócio (como `payments.posted_at`, `championships.registration_open_at/close_at`, `charges.issued_at` e `teacher_invitations.expires_at`) passam de `TIMESTAMP` para `DATETIME` nas migrations. No MySQL 5.7 a criação de `championships` falhava, e as demais colunas seriam reescritas com a hora atual a cada atualização da linha;
- alteração feita nas migrations originais porque ainda não há banco de produção; bancos de desenvolvimento existentes continuam compatíveis;
- README, deploy, banco e testes atualizados.

## 2026-10-12 — Remoção das rotas antigas de cadastro

- removidas as rotas `POST /associacoes/{association}/alunos` e `POST /associacoes/{association}/professores`, que criavam aluno e professor sem idempotência, sem as validações do cadastro direto e, no caso do professor, podiam sobrescrever o registro de um perfil compartilhado;
- removido o código sem uso que as atendia: `StudentController::create/store`, `TeacherController::create/store`, `StudentService::create`, `TeacherService`, `StudentRequest`, `TeacherRequest` e as views `sports/students/create` e `sports/teachers/create`;
- a criação de aluno e professor passa a existir somente pelo cadastro direto; os testes de `SportsRegistryTest` foram migrados para ele e um novo teste garante que as rotas antigas não aceitam mais envios.

## 2026-10-12 — Usuários e perfis da associação

- perfis fixos Gestor da Associação, Financeiro da Associação e Portal do aluno/responsável (`AssociationRoleCatalog`, `AssociationRolesSeeder`);
- tela **Usuários** em `/associacoes/{association}/usuarios` (permissão `user.manage`) com lista, busca, situação, ficha, troca de perfis, inativação, reativação e reenvio de convite;
- conta criada a partir de uma pessoa do cadastro central e ativada por convite de 72 horas em `/ativar-conta/{token}`, sem senha padrão;
- botão **Liberar portal** na ficha do aluno para o aluno e cada responsável;
- regras no serviço: sem escalonamento de perfil, sempre um gestor ativo, conta do administrador do sistema protegida, sem autoinativação, sessões encerradas ao inativar e auditoria `user_account.*`;
- documentação de RBAC, identidade, rotas, guia do usuário, estado e testes atualizada.

## 2026-10-12 — Correções financeiras

- cobrança parcialmente paga com vencimento passado passa a contar como vencida no resumo da central de cobranças, na inadimplência dos dashboards e na notificação `charge.overdue`; o resumo "em aberto" passa a incluir parciais ainda no prazo;
- escopos `Charge::pastDue()` e `Charge::notYetDue()` centralizam a regra; o status `partially_paid` não muda;
- o portal conta cobranças parciais entre as cobranças em aberto;
- quem solicitou uma gratuidade não pode mais aprová-la na etapa do dojo nem na da associação;
- testes de regressão em `PaymentsAndReportsTest` e `FinancialPlansAndBenefitsTest`.

## 2026-10-12 — Controle de versão, CI e dependências

- projeto versionado no GitHub (`fbo-sp/SIGAM`) com workflow de CI: Pint, testes em SQLite, testes em MySQL 8.4 e `composer audit`;
- CI executa em PHP 8.4, versão mínima exigida pelo `composer.lock` atual (pacotes Symfony 8.1 requerem PHP >= 8.4.1);
- `laravel/framework` 12.68.0 → 12.69.3 e `league/commonmark` 2.10.0 → 2.10.3, com dependências, para corrigir alertas de segurança apontados pelo `composer audit`.

## 2026-10-11 — Backlog de experiência móvel

- backlog canônico pós-roadmap criado em `IMPROVEMENT_BACKLOG.md`;
- melhorias móveis priorizadas para professor, aluno e responsável, com IDs UXM-01 a UXM-12;
- navegação por perfil, chamada, portal, feedback, linguagem, notificações, formulários, acessibilidade e desempenho detalhados com critérios de aceite;
- PWA separada de chamada offline, preservando privacidade e exigindo discovery de sincronização antes de armazenar dados pessoais no dispositivo;
- índice, estado implementado, guia de manutenção, README e instruções de agentes atualizados para distinguir proposta de entrega.

## 2026-10-11 — Consolidação documental pós-Sprint 21

- estado implementado centralizado em `IMPLEMENTATION_STATUS.md`, separando conclusão técnica de liberação para produção;
- guia de manutenção e mapa de rotas/comandos adicionados para desenvolvedores e agentes de IA;
- arquitetura, banco, RBAC, segurança, testes, deploy, jobs, runbook e módulos reconciliados com o código atual;
- planos e evidências antigas marcados como históricos para não serem confundidos com requisitos vigentes;
- pendências reais de produto/operação preservadas no checklist de go/no-go e no registro de decisões.

## 2026-10-11 — Sprint 21

- operação final do dojo com financeiro local e campeonatos compartilhados da associação;
- origem explícita em `student_plans.dojo_id`, sem inferência pelo dojo principal;
- cobranças recorrentes e gratuidades vinculadas à mesma origem local, preservando histórico;
- planos simultâneos permitidos em dojos distintos com vínculo regular comprovado;
- inscrições locais derivando aluno, pessoa, dojo e graduação no backend e ocultando outras unidades;
- participação dojo–campeonato idempotente, sem copiar evento, regras ou taxa;
- diagnóstico/backfill conservador `sigam:operation:audit-origins`, seco por padrão e sem dados pessoais;
- migration incremental, ADR, guia, documentação técnica e testes transversais SQLite/MySQL.

## 2026-10-11 — Sprint 20

- meta de oito próximas aulas por turma, configurável para quatro;
- geração inicial transacional e reposição somente do déficit entre todos os dias semanais;
- respeito a timezone, vigências, término, associação/dojo/turma ativos e limite de busca;
- reposição em criação/troca de agenda, fechamento, cancelamento e reagendamento;
- cancelamentos, reagendamentos, histórico e excesso preservados sem ressurreição de ocorrência;
- botão manual e comando horário compartilhando o mesmo serviço idempotente;
- lock por turma, constraint de ocorrência, auditoria e resultado operacional;
- migration incremental, ADR, documentação funcional/técnica e testes SQLite/MySQL.

## 2026-10-08 — Sprint 19

- importação assistida e separada de alunos e presenças por CSV/XLSX;
- modelos oficiais com instruções simples, exemplos fictícios e listas de valores;
- arquivo privado, prévia pronta/revisão/inválida/já processada, confirmação e relatório neutralizado contra fórmulas;
- lotes de 100 linhas retomáveis, hash contra replay e reutilização dos serviços transacionais existentes;
- CPF existente sob confirmação, matrícula/aula exatas e bloqueio de chamada fechada ou recurso fora do escopo;
- permissão `import.manage`, suporte a gestor associativo/local, auditoria sem dados pessoais e retenção do arquivo por 30 dias;
- migration incremental, ADR, documentação técnica/usuário e testes MySQL.

## 2026-10-08 — Planejamento da Sprint 19

- importação assistida adicionada ao roadmap em fluxos separados para alunos e presenças;
- sequência revisada: importação passa à Sprint 19, aulas automáticas à Sprint 20 e consolidação à Sprint 21;
- modelos CSV/XLSX, prévia, revisão de duplicidades/ambiguidades, confirmação e relatório de erros previstos;
- isolamento por associação/dojo, arquivos privados, auditoria, idempotência e preservação de chamadas fechadas definidos como critérios de aceite;
- processamento planejado em lotes compatíveis com hospedagem compartilhada, sem worker permanente.

## 2026-10-08 — Marca visual JUDOMA

- nome apresentado na interface, títulos do navegador, login, e-mails, notificações e documentos operacionais alterado de SIGAM para JUDOMA;
- configuração padrão e ambiente Docker ajustados para a nova marca;
- identificadores técnicos legados `sigam:*`, `SIGAM_*`, bancos, arquivos e infraestrutura preservados para evitar quebra de compatibilidade.

## 2026-10-08 — Template de graduações

- planilha revisável para carga de graduações de Judô, Jiu-Jitsu Brasileiro e Karatê;
- campos alinhados ao catálogo atual, com validação de obrigatórios, nomes e ordens duplicadas;
- referências oficiais da CBJ, CBJJ/IBJJF e CBKD registradas na própria planilha;
- trilha infantil do Jiu-Jitsu separada por incompatibilidade com a sequência linear atual;
- avisos explícitos para regras variáveis, graduações superiores e confirmação pela associação.
- seleção opcional de template diretamente no cadastro de modalidade;
- catálogo operacional em JSON, separado das regras de domínio e com referências de origem;
- preenchimento progressivo de nome/descrição e resumo simples antes de salvar;
- criação transacional e auditada de modalidade e graduações, com rejeição de chave inválida e ausência de permissão.

## 2026-10-07 — Sprint 18

- criação de turma com vários dias da semana, horário comum ou horários diferentes;
- conjunto semanal versionado, professor/local por dia e bloqueio de conflito do professor;
- prévia das próximas aulas e reconciliação transacional de ocorrências futuras;
- preservação de chamadas, presenças, cancelamentos e reagendamentos históricos;
- visitante gratuito por aula, localizado por matrícula exata e restrito à mesma associação;
- permissão `visitor.manage` para gestor e professor local designado;
- validações de modalidade, idade, responsável legal, capacidade e matrícula regular;
- chamada e portal distinguem matriculado de visitante, sem criar vínculo ou cobrança;
- migration incremental, ADR, documentação e testes SQLite/MySQL.

## 2026-10-07 — Sprint 17

- convite associativo de professor integrado ao cadastro direto, sem senha padrão ou acesso antecipado;
- conta pendente, token de 72 horas com hash e cópia cifrada somente até a entrega;
- mensagem transacional própria, lote por cron, retentativa limitada, falha visível e recuperação de execução interrompida;
- GET sem mutação e POST transacional de aceite, verificação de e-mail e uso único;
- conta nova define senha; conta existente comprova e conserva senha, associação e papéis;
- reenvio invalida token anterior; recusa/revogação preservam perfil, vínculo e conta;
- papel mínimo `teacher_account` somente com dashboard e revalidação da autoridade do emissor;
- recuperação de senha genérica, limitada e com token descartável;
- páginas responsivas, headers restritivos, ADR, documentação técnica/operacional e testes SQLite/MySQL.

## 2026-10-07 — Sprint 16

- cadastro direto de aluno no dojo e de professor na associação, sem passagem obrigatória por Pessoas;
- responsável criado ou reutilizado na mesma tarefa, com pré-cadastro e ativação segura de menor;
- matrículas automáticas transacionais e reenvio idempotente por UUID;
- reutilização confirmada de CPF dentro do escopo e resposta genérica para identidade externa;
- vários vínculos regulares de dojo no mesmo aluno, com troca explícita do principal sem encerrar os demais;
- documentos privados contextualizados por aluno, professor ou vínculo de responsável;
- permissões locais `student.manage` e `student.document.manage` sem ampliar rotas associativas;
- telas responsivas, ADR, documentação do módulo e testes em SQLite/MySQL.

## 2026-10-07 — Sprint 15

- contextos separados de administração da associação e operação por dojo;
- concessões locais com papel, unidade, vigência, revogação lógica, idempotência e auditoria;
- papéis locais impedidos de abrir rotas associativas antigas, inclusive por alteração de URL;
- vínculo institucional do professor separado da equipe local e da conta de acesso;
- professor restrito ao dojo e às aulas em que atua, com autorização revalidada na chamada;
- aluno local derivado de vínculo regular vigente, sem usar dojo principal ou visita como prova de acesso;
- telas responsivas de Meus dojos, alunos, turmas, aulas, chamada, professores e gestão de acessos;
- backfill conservador em modo simulação, sem criação automática de conta, convite ou permissão;
- testes negativos de isolamento, vigência, revogação, rotas antigas, recurso externo e replay.

## Planejamento — melhorias das Sprints 17–20 (histórico)

- decisões Q1–Q3 confirmadas: múltiplos dojos com principal, professores cadastrados/convidados somente pela associação e padrão de oito próximas aulas ajustável para quatro;
- participação visitante adicionada ao plano, separada de matrícula regular, com acesso mínimo por aula; Q4/Q5 confirmadas: somente na mesma associação e sempre gratuita, sem lançamento financeiro pela visita;

- proposta integrada de cadastro direto de aluno/professor/responsável e documentos na ficha;
- requisitos de convite, verificação de e-mail e aceite do professor, sem acesso antecipado;
- ampliação futura da operação por dojo para cadastros compostos, documentos, campeonatos e financeiro;
- agenda semanal com vários dias e reposição limitada das próximas aulas;
- sequência proposta de Sprints 15–20, migração preservando histórico e decisões abertas documentadas em `PLANO_MELHORIAS_CADASTRO_E_OPERACAO.md`.

## 2026-08-30 — Sprint 14 (homologação; produção ainda não liberada)

- preflight `sigam:production:check` sem exposição de segredos;
- reserva atômica de e-mails, revalidação de conta/preferências e conteúdo SMTP minimizado;
- caixa restrita à associação atual e revalidação de detalhes pessoais em avisos antigos;
- CSV protegido contra fórmulas, cache privado bloqueado e impressão compatível com CSP;
- seeder preserva senha, status, papéis e templates existentes;
- reforço de coerência no portal, documentos e vínculo de dojo;
- testes de regressão/hardening e ensaio de desempenho com dados sintéticos;
- procedimentos de deploy, rollback, backup, restauração, operação, treinamento e go/no-go explícito.

## 2026-08-30 — Sprint 13

- eventos, templates, preferências e entregas persistentes desacoplados de canal;
- caixa interna com leitura individual/em lote e contador no menu;
- e-mail em lotes por cron, com idempotência, até cinco tentativas e erro observável;
- nove detectores iniciais de cobranças, documentos, filiações, campeonatos, gratuidades, aulas e frequência;
- dashboards responsivos de associação, dojo e professor com escopo derivado no backend;
- permissões específicas de dashboard e vínculo opcional de dojo na conta;
- testes de isolamento, preferência, SMTP falho, idempotência e escopo de indicadores.

## 2026-08-30 — Sprint 12

- portal responsivo com alternância clara de dependentes;
- autorização por titularidade ou vínculo vigente, além de `portal.access`;
- consulta integrada de turmas, frequência, financeiro, documentos, histórico e campeonatos;
- edição transacional limitada a contatos pelo titular ou responsável legal;
- documentos privados por vínculo direto ao aluno e download auditado;
- inscrição com identidade e dojo derivados no backend;
- testes de enumeração, vínculo encerrado e adulteração de IDs.

## 2026-08-30 — Sprint 11

- campeonatos com modalidade, tipo, datas, janela, regulamento, taxa e papéis aceitos;
- inscrições transacionais por competidor, árbitro ou auxiliar, sem duplicidade de pessoa/papel;
- idade calculada no servidor e graduação/filiação validadas contra históricos existentes;
- aprovação com cobrança idempotente no motor financeiro compartilhado;
- bloqueio de participação paga sem quitação e de resultado antes do evento;
- resultados, medalhas, pontos, WO e desclassificação com auditoria;
- histórico esportivo consolidado, filtros, indicadores por papel/dojo e CSV.

## 2026-08-30 — Sprint 10

- catálogo de federações por associação, contatos, abrangência e inativação sem apagar histórico;
- filiações anuais imutáveis com número, validade, modalidade, graduação registrada, pontos e situação;
- validação transacional da graduação contra o histórico esportivo consolidado;
- proteção contra duplicidade por aluno, federação, modalidade e ano;
- documentos federativos no armazenamento privado existente;
- painel de vencimentos, filtros, relatório CSV, RBAC, auditoria e isolamento por associação.

## 2026-08-30 — Sprint 9

- pagamentos totais e parciais transacionais, com chave idempotente e bloqueio de excedente;
- reconciliação do saldo pela soma dos lançamentos válidos;
- estorno auditável com ator, instante e motivo, sem exclusão física;
- detalhe da cobrança e extrato do aluno com histórico completo;
- relatórios de previsto, recebido, pendente, atrasos, descontos e gratuidades por dojo/modalidade;
- exportação CSV UTF-8, RBAC específico, isolamento por associação e testes SQLite/MySQL.

## 2026-08-30 — Sprint 8

- cobrança central com origem, competência, saldos e snapshots completos do cálculo/vencimento;
- geração recorrente mensal, semestral e anual, idempotente e protegida por lock/constraint;
- gratuidade materializada como isenção e desconto congelado no histórico;
- atualização segura de vencidas e registro operacional dos jobs;
- central responsiva de abertas/vencidas, filtros, extrato do aluno e comandos agendáveis.

## 2026-08-30 — Sprint 7

- planos e vínculos de aluno com snapshot monetário e prevenção transacional de sobreposição;
- regras configuráveis para dia inexistente, fim de semana e feriados;
- descontos com vigência e separação entre solicitação/aprovação;
- gratuidade com dupla aprovação sequencial e atores distintos;
- simulador explicável, BCMath, formatação sem `float`, RBAC, auditoria e testes.

## 2026-08-30 — Sprint 6

- tipos configuráveis, metadados, validade e central responsiva de documentos;
- upload privado com UUID, validação de assinatura/MIME/extensão/tamanho e coerência pessoa/aluno;
- download por streaming autorizado, exclusão lógica, auditoria e testes de isolamento.

## Não lançado

### Adicionado

- baseline Laravel 12 e toolchain PHP/Composer portátil para desenvolvimento;
- migrations de associação, pessoa central, usuários, RBAC, auditoria e jobs;
- autenticação sem cadastro público, associação atual e bloqueio de contas inativas;
- auditoria de login/logout e headers básicos de segurança;
- layout responsivo inicial em português brasileiro;
- documentação viva, guia de agentes e testes automatizados da fundação.
- ambiente Docker opcional com PHP/Apache, MySQL persistente, healthchecks e suíte MySQL isolada.
- gestão responsiva de associações, dojos e pessoas com busca, paginação e exportação CSV;
- validação e normalização de CPF, CNPJ, contatos e códigos de dojo;
- autorização negativa por permissão/associação, bloqueio de novos vínculos em associação inativa e auditoria minimizada;
- documentação técnica e funcional do módulo Organização e Pessoas.
- catálogo configurável de modalidades e graduações, sem faixas fixas no código;
- pré-cadastro guiado de aluno, múltiplos responsáveis e ativação segura de menores;
- históricos de dojo, modalidade e graduação e habilitações de professores;
- permissões, auditoria e documentação do Cadastro Esportivo.
- turmas com capacidade, faixa etária, professor habilitado e agenda versionada;
- matrículas transacionais com validação de dojo, modalidade, idade, vigência, duplicidade e vaga;
- interface e documentação do módulo de Turmas, Horários e Matrículas.
- geração idempotente de aulas por comando e agenda versionada;
- chamada mobile com registro em lote, fechamento e reabertura auditada;
- cancelamento e reagendamento de aula sem sobrescrever histórico.
