# Mapa de rotas, comandos e processos

Este documento é um índice de manutenção. `routes/web.php`, `routes/console.php` e as assinaturas das classes em `app/Console/Commands` são sempre a fonte canônica.

## Rotas públicas

| Prefixo/rota | Função | Proteções |
|---|---|---|
| `/entrar` | criação de sessão | CSRF, throttle, regeneração e mensagem genérica |
| `/recuperar-senha`, `/redefinir-senha` | recuperação não enumerável | throttle e token descartável |
| `/convites/professor/{token}` | visualizar, aceitar ou recusar convite | throttle, GET sem mutação e POST transacional |
| `/ativar-conta/{token}` | definir a senha de conta criada pela tela de usuários | somente visitante, throttle, token de 72 h de uso único e conta pendente |
| `/up` | saúde do processo | não substitui teste de banco/SMTP |

## Rotas autenticadas por contexto

| Grupo | Controllers | Escopo |
|---|---|---|
| `/dashboard` | `DashboardController` | visão derivada das permissões e vínculos da conta |
| `/notificacoes` | `NotificationController` | sempre `notifications.user_id` da conta |
| `/fotos/{person}/{thumb\|medium}` | `PersonPhotoController` | link assinado relativo gerado só por telas que já mostram a pessoa; pessoa da associação da conta; foto trocada invalida o link |
| `/sair` | `AuthenticatedSessionController` | sessão autenticada, CSRF e invalidação |
| `/modelos/importacao/{type}/{format}` | `AssistedImportController` | conta ativa; tipo/formato em lista fechada e conteúdo fictício |
| `/portal` | `PortalController` | titularidade ou responsabilidade vigente |
| `/portal/alunos/{student}/progresso` e `/portal/alunos/{student}/quiz/...` | `PortalProgressionController`: progresso técnico, início (`POST quiz/{martialArt}`, `mode=training|official`), tentativa e envio (`quiz/tentativas/{attempt}`) | titularidade ou responsabilidade vigente; início e envio com limite de 20 por minuto |
| `/meus-dojos` | `OperationalController` | dojos concedidos ou administração associativa |
| `/operacao/dojos/{dojo}` | `OperationalController`, `DirectRegistrationController`, `DojoFinanceController`, `DojoChampionshipController` | `OperationalScope`; recursos sempre filtrados pelo dojo |
| `/associacoes/{association}/usuarios` | `Access\UserController` | `user.manage` associativo; regras em `UserAccountService` |
| `/associacoes/{association}/acessos-dojos` | `DojoAccessController` | `role.manage` associativo |
| `/associacoes/{association}` | controllers administrativos | permissão associativa específica + validação do recurso |

## Áreas administrativas da associação

| Caminho relativo | Área | Permissões principais |
|---|---|---|
| `dojos` | unidades | `dojo.view`, `dojo.manage` |
| `pessoas` | identidade central; `pessoas/{person}/editar?voltar=/caminho` volta à lista de origem (só caminho interno) | `person.view`, `person.manage` |
| `modalidades` | modalidades com menu de seções (graduações, técnicas, currículo, quiz, configurações) e templates | `martial_art.view`, `martial_art.manage` |
| `modalidades/{martialArt}/graduacoes` | GET lista as graduações; POST inclui | leitura `martial_art.view`; escrita `martial_art.manage` |
| `modalidades/{martialArt}/tecnicas` | catálogo de técnicas: incluir, `{item}/editar`, `{item}/situacao`, `modelo` | leitura `martial_art.view`; escrita `curriculum.manage` |
| `alunos/{student}/acompanhamento` | acompanhamento técnico do aluno (`?modalidade=`), só consulta | `assessment.view` |
| `modalidades/{martialArt}/quiz` | banco de perguntas (`?graduacao=`): incluir, `{question}/editar`, PUT `{question}`, `{question}/situacao` | `quiz.manage` |
| `acompanhamento` | painel que compara dojos e professores (`?modalidade=&de=&ate=`) | `progression.report` |
| `acompanhamento/configuracoes` | perguntas por quiz, nota mínima e semanas do lembrete (GET/PUT) | `curriculum.manage` |
| `modalidades/{martialArt}/curriculo` | currículo por graduação (`?graduacao=`), `curriculo/{rank}` grava exigência e `curriculo/{rank}/{item}/restaurar` desfaz o ajuste | leitura `martial_art.view`; escrita `curriculum.manage` |
| `alunos` | alunos/responsáveis/histórico; `alunos/{student}/turmas` matricula e `.../turmas/{enrollment}/encerrar` encerra | `student.view`, `student.manage`; turmas com `enrollment.manage` |
| `professores` | perfil, habilitação e convites | `teacher.view`, `teacher.manage` |
| `turmas` | agenda e matrículas | `class.view`, `class.manage`, `enrollment.manage` |
| `aulas` | ocorrências (`?date=&dojo=&turma=&professor=`; sem filtros abre no dia de hoje, data em branco mostra todas), visitantes e chamada | `session.view`, `session.manage`, `attendance.manage`, `attendance.reopen`, `visitor.manage` |
| `documentos` | tipos, metadados, upload/download | `document.view`, `document.manage`, `document.download` |
| `importacoes` | alunos e presenças | `import.manage` + permissão da operação |
| `federacoes` | federações e filiações | `federation.view`, `federation.manage` |
| `campeonatos` | evento, inscrição, decisão e resultado | `championship.*` específico |
| `financeiro` | planos, benefícios, cobranças, pagamentos e relatórios | permissões financeiras específicas |

## Área operacional do dojo

O prefixo `/operacao/dojos/{dojo}` oferece:

- alunos e cadastro direto; `alunos/{student}/dados` (GET/PUT) para o gestor com `student.manage` corrigir dados pessoais do aluno do dojo;
- turmas e aulas autorizadas;
- turma no dojo: `turmas/nova`, `turmas/{classGroup}` (abas Alunos, Professores, Horários e Aulas), `turmas/{classGroup}/editar`, `PUT turmas/{classGroup}`, `turmas/{classGroup}/matriculas` e `.../matriculas/{enrollment}/encerrar` (`enrollment.manage`), `turmas/{classGroup}/professores` e `.../professores/{assignment}/encerrar`, `agenda/substituir`, `aulas/atualizar` e `aulas/passadas` (`class.manage`);
- chamada e visitantes; `POST aulas/{session}/reabrir` (`attendance.reopen`, motivo obrigatório) reabre a chamada finalizada;
- acompanhamento técnico: `alunos/{student}/acompanhamento` (gestor com `assessment.view` ou professor que avalia o aluno) e `alunos/{student}/acompanhamento/{martialArt}/avaliar` (GET/POST, só professor principal ou auxiliar da turma, `assessment.manage`); `POST alunos/{student}/acompanhamento/{martialArt}/quiz` libera e `POST alunos/{student}/acompanhamento/quiz/{release}/cancelar` cancela a prova oficial (mesmo professor); `POST aulas/{session}/tecnicas` grava as técnicas trabalhadas (`attendance.manage`); `acompanhamento` abre o painel das turmas (`progression.report`, `?modalidade=&turma=&de=&ate=`); `turmas/{classGroup}/cobertura` abre a cobertura do currículo da turma, geral e por faixa (`progression.report`, `?de=&ate=`);
- equipe de professores da associação;
- importação assistida;
- planos, gratuidades, cobranças e pagamentos do dojo;
- campeonatos da associação e inscrições somente da unidade.

O papel `dojo_teacher` recebe apenas funções de aula designada e a avaliação técnica dos alunos das próprias turmas. O papel `dojo_operator` recebe as capacidades operacionais definidas em `OperationalScope::DOJO_PERMISSIONS`. Alterar essa constante exige atualizar `OperationalRolesSeeder`, RBAC, testes e este documento.

## Comandos JUDOMA/SIGAM

| Comando | Objetivo | Idempotência/observação |
|---|---|---|
| `sigam:sessions:generate` | completar 4/8 próximas aulas | lock por turma e constraint de ocorrência |
| `sigam:charges:generate` | gerar cobranças por competência | unicidade origem/competência |
| `sigam:charges:mark-overdue` | marcar abertas vencidas | só transita cobranças elegíveis |
| `sigam:notifications:generate` | detectar eventos | chave idempotente por evento/marco |
| `sigam:notifications:send` | enviar e-mails em lote | reserva `sending`, retentativas e limite 1–500 |
| `sigam:teacher-invitations:send` | entregar convites | reserva, revalidação e até cinco tentativas |
| `sigam:imports:purge-files` | aplicar retenção da planilha privada | ignora arquivos já eliminados |
| `sigam:access:backfill-teachers` | inferir vínculo institucional comprovado | dry-run; `--apply` não cria conta/acesso local |
| `sigam:access:sync-teacher-dojos` | criar a concessão `dojo_teacher` para vínculos de professor vigentes ou futuros sem acesso | dry-run; `--apply` idempotente, não altera concessões existentes |
| `sigam:operation:audit-origins` | diagnosticar origem financeira legada | dry-run; `--apply` apenas evidência inequívoca |
| `sigam:production:check` | preflight sem imprimir segredos; informa também se a extensão GD (fotos) está disponível, sem mudar o resultado | código não zero bloqueia liberação técnica |

Use `php artisan help nome-do-comando` para opções atuais.

## Agenda padrão

| Horário | Processo |
|---|---|
| a cada 5 minutos | e-mails de notificações e convites de professor |
| a cada hora | detecção de notificações e reposição de aulas |
| 01:10 | cobranças recorrentes |
| 01:30 | marcação de vencidas |
| 02:10 | retenção de arquivos de importação |

Produção configura apenas `php artisan schedule:run` por minuto, ou a menor frequência segura do provedor. Não duplique o scheduler com chamadas diretas simultâneas.
