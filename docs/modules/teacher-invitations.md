# Convites e acesso do professor

## Objetivo

Entregar ao professor uma conta individual sem senha padrão e sem ampliar acesso antes do aceite. Cadastro esportivo, vínculo institucional, convite, conta e atuação em dojo permanecem entidades independentes.

## Estados

`teacher_invitations.status` aceita `pending`, `sent`, `accepted`, `declined` e `revoked`. A interface deriva `expired` quando um convite pendente/enviado ultrapassa `expires_at`. Transporte usa `pending`, `sending`, `sent`, `failed` ou `suppressed`; falha SMTP não muda automaticamente a decisão do convite.

`users.status = pending` impede autenticação da conta nova. Somente o aceite muda para `active`. Para conta já ativa, nenhuma senha ou função anterior é substituída.

## Fluxos

### Emissão e entrega

1. `TeacherInvitationService::issue` confirma associação ativa, `teacher.manage`, vínculo institucional, pessoa e e-mail;
2. em transação, reutiliza uma conta coerente ou cria conta pendente e revoga convites anteriores ainda utilizáveis;
3. persiste hash do token, cópia cifrada para entrega, expiração de 72 horas e auditoria sem dados pessoais/segredo;
4. `sigam:teacher-invitations:send` reserva até `--limit`, revalida o cadastro e envia `TeacherInvitationMail` por SMTP;
5. sucesso remove a cópia cifrada. Falha registra mensagem sanitizada e retentativa limitada. Reserva `sending` interrompida há 15 minutos volta à fila com segurança.

### Aceite ou recusa

O GET não escreve. No POST, `TeacherInvitationService::accept` bloqueia convite e conta, valida uso único/prazo e repete todas as verificações de identidade, escopo e autoridade. Conta nova fornece senha de no mínimo 12 caracteres com letras e números; conta ativa fornece sua senha atual. E-mail verificado e aceite são gravados separadamente. `decline` preserva cadastro e conta.

### Reenvio, revogação e recuperação

Reenvio cria outro convite e invalida o anterior. Revogação não desativa a conta. Recuperação de senha é pública, limitada por taxa e retorna sempre a mesma orientação, exista ou não conta ativa.

## Autorização e segurança

- rotas administrativas são associativas, autenticadas e protegidas por `teacher.manage`; o serviço repete a verificação;
- `users.association_id` não autoriza: um usuário vinculado a outra associação é recusado, e o administrador do sistema (sem associação, papel de escopo `system`) é aceito pelo `hasPermission`, como em `AccessAdministration` (ADR-006);
- gestor de dojo não cria ou convida professor;
- token de URL não aparece em auditoria, listagem ou mensagem de erro;
- páginas de convite/recuperação usam `no-store` e `Referrer-Policy: no-referrer`, sem recursos externos;
- alteração de e-mail, conta, pessoa, associação ou vínculo invalida o uso/entrega;
- a permissão inicial é somente `dashboard.teacher`. Acesso operacional a dojo/aula ainda exige as concessões e vínculos da Sprint 15;
- concorrência é contida por transação, locks, token único e `syncWithoutDetaching`.

## Mapa do código

- migration: `2026_10_07_000018_create_teacher_invitations_table.php`;
- model: `TeacherInvitation`; relações em `TeacherProfile` e `User`;
- domínio: `TeacherInvitationService` e `TeacherInvitationDeliveryService`;
- HTTP: controllers `TeacherInvitationController` e `TeacherInvitationManagementController`, request `AcceptTeacherInvitationRequest`;
- e-mail: `TeacherInvitationMail`, `PasswordResetMail` e views em `resources/views/emails`;
- comando/agenda: `SendTeacherInvitations` e `routes/console.php`;
- telas: `auth/teacher-invitation`, `auth/forgot-password`, `auth/reset-password` e gestão em `sports/teachers/index`;
- testes: `TeacherInvitationTest`.

## Manutenção

Não transforme `email`, `user_id` ou token em autorização. Não envie no controller nem depois de criar senha padrão. Ao adicionar permissões ao professor, não amplie `teacher_account`: use concessão local e vínculos explícitos. Preserve os casos de conta existente, senha errada, GET sem mutação, token expirado/revogado/reutilizado, mudança de e-mail, emissor sem autoridade, falha SMTP e entrega interrompida.
