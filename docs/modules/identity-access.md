# Identidade e acesso

## Objetivo

Autenticar contas, bloquear usuários inativos, definir associação atual e verificar papéis/permissões com escopo.

## Fluxo inicial

1. usuário informa e-mail e senha;
2. backend normaliza e limita tentativas;
3. credenciais e estado ativo são validados;
4. sessão é regenerada;
5. `last_login_at` e auditoria são registrados;
6. rotas protegidas resolvem a associação atual da conta.

Não há cadastro público de usuário. O administrador inicial é criado por variáveis de ambiente durante o seed. Professores recebem conta exclusivamente por convite associativo: conta nova permanece pendente até definir a própria senha e aceitar; conta existente confirma sua senha sem perder papéis. Consulte `teacher-invitations.md` e ADR-008.

## Contas criadas pela tela de usuários

Quem tem `user.manage` abre **Usuários** na associação, escolhe uma pessoa ativa da associação que ainda não tem conta, informa o e-mail e marca os perfis fixos (ver `RBAC.md`). A conta nasce `pending`, com senha aleatória que ninguém conhece, e a pessoa recebe `AccountActivationMail` com link de 72 horas (broker `activations` em `config/auth.php`, mesma tabela `password_reset_tokens`). Em `/ativar-conta/{token}` a pessoa define a senha; só então a conta passa a `active` e `email_verified_at` é preenchido. Reenviar o convite invalida o link anterior. Se o SMTP falhar, a conta fica pendente, a falha é auditada e o gestor usa **Reenviar convite**.

O botão **Liberar portal** na ficha do aluno (para o aluno e para cada responsável) abre o mesmo formulário com a pessoa e o perfil Portal já escolhidos. O que o portal mostra continua decidido por `PortalAccessService`.

Inativar uma conta encerra as sessões abertas e descarta tokens de senha. Reativar devolve `active` a quem já entrou ou confirmou o e-mail; quem nunca ativou volta a `pending` e precisa de novo convite.

## Minha conta e troca de senha

Toda conta logada abre **Minha conta** (`/minha-conta`). Para quem usa o portal (`PortalAccessService::accountStudent`: o próprio cadastro de aluno, senão o primeiro aluno sob responsabilidade), o nome no topo leva a "Meus dados" do portal, que tem o bloco "Senha de acesso" com o botão "Trocar senha", e a tela Minha conta mostra "← Meus dados". Para as demais contas o nome leva direto a Minha conta; o professor também a encontra no "Mais". A troca de senha (`PUT /minha-conta/senha`, limite de 5 por minuto) pede a senha atual e a nova duas vezes (mínimo de 12 caracteres, letras e números, diferente da atual). `AccountPasswordService` confere a senha atual (erro auditado como `security.password_change_failed`), grava o novo hash, troca o `remember_token`, apaga as outras linhas de `sessions` da conta mantendo só a atual, audita `security.password_changed` e envia `PasswordChangedMail` com link para a recuperação. Falha no envio do aviso não desfaz a troca. Quem esqueceu a senha atual usa a recuperação por e-mail.

## Estados e escopo

- `users.status = pending`: conta provisionada, mas sem autenticação até o aceite válido;
- `active`: credenciais válidas podem abrir sessão;
- `inactive`: login e rotas autenticadas são bloqueados;
- `users.association_id` define a associação atual da experiência comum, mas não substitui permissão;
- papéis globais/associativos ficam em `user_roles`; concessões locais ficam exclusivamente em `dojo_role_assignments`;
- `users.person_id` conecta a conta à identidade e é obrigatório para portal/dashboard pessoal;
- `users.dojo_id` atende ao dashboard legado e nunca prova acesso operacional.

Recuperação de senha sempre responde de forma genérica. Tokens são temporários e descartáveis. Logout invalida a sessão e regenera o token CSRF. Rotas autenticadas aplicam os middlewares de conta ativa e contexto da associação antes das permissões específicas.

## Administrador inicial

`DatabaseSeeder` lê `SIGAM_ADMIN_NAME`, `SIGAM_ADMIN_EMAIL` e `SIGAM_ADMIN_PASSWORD` somente no processo de bootstrap. Não existe senha padrão no código. Se a conta já existe, o seeder não troca senha, não reativa e não promove. Remova o segredo de bootstrap do ambiente depois da criação supervisionada e use o fluxo de recuperação para mudanças posteriores.

## Mapa de código

- schema inicial: migrations `000000` e `000003`;
- sessão/recuperação: `app/Http/Controllers/Auth/`, requests em `app/Http/Requests/Auth/` e rotas públicas em `routes/web.php`;
- autorização: `app/Http/Middleware/EnsureUserIsActive.php`, `ResolveAssociationContext.php`, `RequirePermission.php` e model `User`;
- convites: `TeacherInvitationService`, `TeacherInvitationDeliveryService` e `teacher-invitations.md`;
- contas e perfis da associação: `UserAccountService`, `AssociationRoleCatalog`, `Access\UserController`, `Auth\AccountActivationController`, views em `resources/views/users/`;
- minha conta: `Auth\AccountController`, `ChangePasswordRequest`, `AccountPasswordService`, `PasswordChangedMail` e `resources/views/account/`;
- bootstrap: `database/seeders/DatabaseSeeder.php`, `OperationalRolesSeeder.php` e `AssociationRolesSeeder.php`;
- regressão: `AuthenticationTest`, `AssociationScopeTest`, `TeacherInvitationTest`, `OperationalContextAuthorizationTest`, `UserAccountManagementTest` e `AccountPasswordTest`.

## Invariantes de manutenção

Nunca crie senha conhecida pelo gestor para professor ou qualquer outra conta, nunca use mensagem de recuperação para enumerar contas e nunca trate associação/papel vindo do request como autorização. Toda nova permissão exige seeder idempotente, middleware/serviço, casos positivo e negativo, tentativa fora do escopo e atualização de `RBAC.md`.

