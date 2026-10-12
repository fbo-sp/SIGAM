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
- bootstrap: `database/seeders/DatabaseSeeder.php` e `OperationalRolesSeeder.php`;
- regressão: `AuthenticationTest`, `AssociationScopeTest`, `TeacherInvitationTest` e `OperationalContextAuthorizationTest`.

## Invariantes de manutenção

Nunca crie senha conhecida pelo gestor para professor, nunca use mensagem de recuperação para enumerar contas e nunca trate associação/papel vindo do request como autorização. Toda nova permissão exige seeder idempotente, middleware/serviço, casos positivo e negativo, tentativa fora do escopo e atualização de `RBAC.md`.

