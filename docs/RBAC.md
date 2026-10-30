# RBAC e escopo

Permissões administrativas continuam associativas. A Sprint 15 adicionou concessões operacionais por dojo sem ampliar as rotas antigas. A matriz por campo dos cadastros compostos ainda deve ser concluída antes da produção; ver `RELEASE_CHECKLIST.md`.

Autorização exige simultaneamente autenticação, usuário ativo, permissão e escopo. A associação do recurso nunca é aceita apenas porque veio no request.

## Perfis iniciais

- Administrador do Sistema;
- Gestor da Associação;
- Financeiro da Associação;
- Responsável/Administrativo do Dojo;
- Professor;
- Aluno;
- Responsável.

## Fundação implementada

`roles`, `permissions`, `role_permissions` e `user_roles` separam capacidade de escopo. Permissões globais são semeadas por código. O usuário comum começa associado a uma associação; papéis globais são reservados à administração do sistema.

Toda nova rota protegida deve possuir teste de permissão positiva, ausência de permissão e tentativa fora do escopo.

## Perfis fixos da associação e tela de usuários

`App\Support\Access\AssociationRoleCatalog` define três papéis globais (`association_id` nulo) de escopo `association`, atribuídos em `user_roles` sempre com a associação no pivot:

| Código | Nome | Permissões |
|---|---|---|
| `association_manager` | Gestor da Associação | todas as permissões cadastradas, exceto as pessoais (`portal.access`, `dashboard.teacher`) |
| `association_finance` | Financeiro da Associação | visão de associação/dojos/pessoas/alunos, planos, descontos, gratuidades (pedido e aprovação associativa), cobranças, pagamentos, estornos, relatórios financeiros e dashboard da associação |
| `portal_user` | Portal do aluno/responsável | somente `portal.access` |

O seeder `AssociationRolesSeeder` e o próprio serviço criam ou completam esses papéis sem remover permissões. Não existe editor livre de permissões: mudar um perfil exige alterar o catálogo, este documento e os testes.

A tela `/associacoes/{association}/usuarios` exige `user.manage` e é servida por `UserAccountService`, que aplica:

- conta da mesma associação do ator (ou ator sem associação, como o administrador do sistema); conta de outra associação responde 404;
- ninguém concede, retira, inativa ou reativa perfil com permissões que não possui na associação. Permissões pessoais (`portal.access`, `dashboard.teacher`) não contam, por isso quem administra usuários pode liberar o portal;
- a associação mantém pelo menos um gestor ativo; a verificação roda sob lock da associação;
- contas com papel `system` não são alteradas pela tela;
- ninguém inativa a própria conta; inativar apaga as sessões abertas e tokens de senha da conta;
- toda alteração gera `audit_logs` (`user_account.*`).

## Organização e pessoas

As permissões `dojo.view` e `dojo.manage` complementam as permissões de associação e pessoa. Rotas aninhadas resolvem o escopo pela associação da URL, e controllers confirmam a pertença do dojo/pessoa. Criar uma associação exige `association.manage` em papel global; a mesma permissão em papel associativo permite somente editar a associação correspondente.

Acompanhamento técnico adiciona `curriculum.manage` (catálogo de técnicas e currículo por graduação, só no contexto da associação); consultar catálogo e currículo exige `martial_art.view`. O Gestor da Associação recebe a permissão pelo `db:seed`. `assessment.view` consulta o acompanhamento do aluno (associação, e no dojo pelo papel `dojo_operator`, só para alunos regulares do dojo). `assessment.manage` existe só no papel `dojo_teacher`: o professor avalia apenas alunos com matrícula ativa em turma ativa da modalidade em que ele é principal ou auxiliar vigente; gestor do dojo e associação não avaliam. O mesmo professor libera e cancela a prova oficial do quiz. A consulta do material de estudo do professor (`/professor/estudar`) não usa permissão: basta perfil de professor ativo, e mostra só o catálogo ativo da associação da conta, sem dados de aluno.

`quiz.manage` administra o banco de perguntas do quiz (associação); as configurações do acompanhamento usam `curriculum.manage`. `progression.report` abre os painéis: na associação, a comparação entre dojos e professores; no dojo, o painel das turmas (gestor com `dojo_operator` vê todas; professor com `dojo_teacher` vê só as turmas em que é principal ou auxiliar vigente). O `db:seed` acrescenta `quiz.manage` e `progression.report` ao Gestor da Associação e `progression.report` aos papéis `dojo_operator` e `dojo_teacher`. Marcar técnicas na aula usa `attendance.manage` sobre as aulas visíveis em `OperationalScope::sessions`. No portal, o progresso, o quiz e a área de estudo seguem `portal.access` e a titularidade ou responsabilidade do aluno. A ficha da técnica (`/tecnicas/{item}/ficha`) só exige conta da mesma associação do item e item ativo, porque é material de estudo da associação.

Cadastro esportivo adiciona `martial_art.*`, `student.*` e `teacher.*`. Possuir acesso à pessoa não concede automaticamente acesso ao perfil esportivo; cada rota exige a permissão específica e o mesmo escopo de associação.

Turmas separam leitura (`class.view`), configuração (`class.manage`) e matrícula (`enrollment.manage`). Isso permite que uma equipe administrativa matricule alunos sem alterar agenda ou professor. No contexto do dojo, o papel `dojo_operator` recebe as duas permissões e só as usa nas turmas da própria unidade. O professor principal e os auxiliares designados em `class_group_teachers` veem as aulas da turma e fazem a chamada, mas não editam nem matriculam. Corrigir a data inicial de uma matrícula exige `enrollment.manage` de papel associativo ou de sistema (administrador e gestor da associação); a concessão local do `dojo_operator` não libera essa correção.

Aulas separam `session.view`, `session.manage`, `attendance.manage` e `attendance.reopen`. A permissão de reabrir é deliberadamente distinta da chamada comum. No dojo, `attendance.reopen` faz parte dos papéis `dojo_operator` (aulas do dojo) e `dojo_teacher` (aulas designadas ao professor); a reabertura exige motivo e é auditada. Nenhum perfil registra ou finaliza chamada antes do dia da aula.

`visitor.manage` autoriza incluir ou revogar participação eventual. No contexto local, gestor do dojo ou professor designado ainda precisa de concessão, vínculo e acesso à aula validado por `OperationalScope`; conhecer matrícula ou ID não basta. A permissão não concede diretório, matrícula, cadastro, documentos ou financeiro.

Documentos separam consulta de metadados (`document.view`), alterações (`document.manage`) e acesso ao conteúdo (`document.download`). Todas exigem também o escopo da associação da rota; conhecer um ID não concede acesso.

Financeiro separa leitura/simulação (`finance.view`), configuração de planos (`finance.manage`), solicitação e aprovação de desconto e as duas etapas de gratuidade. Separação de função também é validada no domínio: permissão ampla não permite que o mesmo usuário realize as duas aprovações de uma gratuidade, nem que o solicitante aprove a própria solicitação em qualquer etapa.

Cobranças usam `charge.view` para central/extrato e `charge.generate` para geração manual idempotente. Execuções pelo cron não possuem usuário e ficam identificadas por `scheduled_job_runs`; cada cobrança mantém auditoria da criação.

Pagamentos separam baixa (`payment.manage`) de estorno (`payment.reverse`). Relatórios e CSV exigem `finance.report`. Todas as consultas e escritas validam associação no middleware, controller e/ou serviço; o filtro de dojo também é restrito à associação atual.

Federações usam `federation.view` para consulta/relatório e `federation.manage` para catálogo, filiação e upload federativo. Download continua exigindo `document.download`, mantendo separação entre visualizar metadados e acessar conteúdo privado.

Campeonatos separam consulta (`championship.view`), configuração (`championship.manage`), inscrição (`championship.register`), decisão (`championship.approve`) e resultado (`championship.result`). Aprovar não concede baixa financeira, e registrar resultado não concede acesso automático à cobrança.

O portal usa `portal.access`, mas essa permissão isolada não libera nenhum aluno. `PortalAccessService` exige a pessoa vinculada à conta e confirma titularidade ou responsabilidade vigente. Responsável não legal tem leitura, mas não altera contatos. Downloads usam a mesma relação e não concedem a permissão administrativa global `document.download`.

Dashboards usam permissões exclusivas: `dashboard.association`, `dashboard.dojo` e `dashboard.teacher`. O dojo vem de `users.dojo_id` e o professor vem de `users.person_id → teacher_profiles`; parâmetros do request não ampliam a consulta. A caixa de notificações não exige permissão adicional porque todas as ações filtram obrigatoriamente `notifications.user_id` pela conta autenticada.

Convites exigem `teacher.manage` associativo na rota e novamente no serviço. O papel técnico global `teacher_account`, anexado com o `association_id` do convite, contém somente `dashboard.teacher`. Ele não satisfaz permissões de cadastro, turma, chamada ou documentos. A autoridade do emissor é revalidada no aceite; perder a permissão torna o convite inutilizável.

## Relatórios (`report.export`)

`report.export` libera a tela Relatórios e os downloads (`modules/reports.md`). Na associação vem do perfil Gestor; no dojo está nos papéis `dojo_operator` (todo o dojo) e `dojo_teacher` (só as turmas em que é principal ou auxiliar vigente). O alcance é montado por `ReportScope`; filtros do pedido só restringem. A lista de alunos do professor não traz CPF nem e-mail.

## Aniversariantes

Sem permissão nova. A tela da associação exige `student.view` na associação; a do dojo, `student.view` pela regra do dojo (`OperationalScope::canDojo`); a do professor, ser principal ou auxiliar vigente de turma que ele consegue abrir. O aviso do portal segue `PortalAccessService` e mostra só nome e turma dos colegas.

## Cadastros incompletos

Sem permissão nova: o mesmo alcance dos aniversariantes. O professor vê as pendências dos alunos ativos das turmas que abre, sem editar. A correção usa as permissões já existentes (`person.manage` na associação, `student.manage` no dojo, contatos no portal pelo próprio aluno ou responsável legal).

## Responsáveis dos alunos

Sem permissão nova (decisão de Fabio em 30/10/2026). Associação: `student.manage`. Dojo: `student.manage` pela regra do dojo, só alunos com vínculo vigente no dojo. Professor: só alunos com matrícula vigente em turma ativa em que é principal ou auxiliar vigente, no dojo em que entra. Todos adicionam, editam, encerram e liberam o portal (`GuardianService::canManage`). Na edição de pessoas, os blocos Dependentes e Responsáveis exigem `person.manage` (rota) e `student.manage`. O portal abre para `portal.access` ou para quem tem o próprio cadastro de aluno ou vínculo vigente de responsável; só o próprio aluno e o responsável legal alteram dados por lá.

## Avisos (`announcement.send`)

`announcement.send` libera o envio de avisos escritos por pessoas (`AnnouncementService`, ver `modules/notifications-dashboards.md`). Na associação vem do perfil Gestor (todas as permissões não pessoais) e permite toda a associação, dojos, turmas ou pessoas. No dojo está nos papéis `dojo_operator` (todo o dojo, turmas do dojo, alunos com vínculo vigente e professores vinculados ao dojo) e `dojo_teacher` (só turmas em que é principal ou auxiliar vigente e os alunos delas, sem professores como destinatários). O alcance é recalculado no serviço; ids do formulário só restringem. Avisos do professor saem direto (decisão do Fabio, 2026-10-27); o gestor do dojo vê os avisos do dojo, o professor só os próprios e a associação todos.

## Contexto operacional por dojo

Papéis `dojo` e `teacher` existem somente em `dojo_role_assignments`; não entram em `user_roles` e não satisfazem `User::hasPermission`. O serviço `OperationalScope` exige concessão vigente para a unidade. Professor também exige os vínculos institucional/local e designação na aula. `users.dojo_id` não concede acesso.

O gestor local com `student.manage` cadastra aluno somente no dojo concedido e corrige os dados pessoais (nome, nascimento, contatos, endereço e emergência) dos alunos com vínculo vigente na unidade; CPF, documento, situação, observações e pessoas que também são professoras continuam exigindo `person.manage` associativo. `student.document.manage` permite anexar um arquivo privado durante esse cadastro, sem liberar central, listagem, download amplo ou rota associativa de documentos. Configuração associativa, relatórios/CSV centrais, manutenção institucional, decisão de inscrição, estorno e aprovação associativa de gratuidade continuam negados. Operações financeiras e inscrições liberadas na Sprint 21 existem exclusivamente nas rotas locais e com filtro persistido de origem. Matriz, estados e testes estão em `modules/operational-access.md`, `modules/direct-registration.md` e `modules/finance-plans-benefits.md`.

`import.manage` apenas abre o mecanismo de planilha. Cada lote exige também `student.manage` ou `attendance.manage`; ambas as permissões e o dojo são revalidados no serviço. O papel local `dojo_operator` recebe a capacidade, mas o papel `dojo_teacher` não.

A concessão `dojo_teacher` é criada automaticamente quando o gestor vincula ao dojo um professor que já tem conta na associação, e revogada quando o vínculo é encerrado; professor sem conta a recebe ao aceitar o convite. Os demais requisitos do professor (perfil ativo, vínculo institucional, vínculo local e designação na aula) continuam valendo. A listagem de lotes é filtrada pelos dojos atualmente permitidos antes da paginação.

Na Sprint 21, o papel local passou a receber `charge.view`, `student_plan.manage`, `payment.manage`, `gratuity.request`, `gratuity.approve_dojo`, `championship.view` e `championship.register`. Essas permissões só funcionam nas rotas `/operacao/dojos/{dojo}` porque `User::hasPermission` continua sem aceitar papel local nas rotas associativas. Consultas filtram `dojo_id` antes de carregar ou paginar; IDs enviados não escolhem associação, dojo, pessoa, graduação ou origem. Configurar planos, aprovar gratuidade pela associação, decidir inscrições, alterar campeonato, estornar e emitir relatórios seguem associativos.
