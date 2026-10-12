# Validação da Sprint 17

> Evidência histórica daquele incremento. A continuidade foi concluída até a Sprint 21; para o estado atual, consulte `IMPLEMENTATION_STATUS.md` e `SPRINT21_VALIDATION.md`.

Data: 07/10/2026.

## Escopo validado

- criação integrada de professor, conta pendente e convite sem senha padrão;
- entrega em lote, falha observável, retentativa e recuperação de reserva interrompida;
- GET sem mutação; aceite de conta nova e existente; verificação de e-mail e uso único;
- expiração, revogação, recusa, reenvio, mudança de e-mail e perda de autorização;
- papel mínimo do professor e bloqueio de login antes do aceite;
- recuperação de senha com resposta genérica e token descartável;
- migration incremental, seeder idempotente, rotas, scheduler e templates Blade.

## Evidências automatizadas

| Verificação | Resultado |
|---|---|
| `scripts/php.ps1 vendor/bin/pint --test` | aprovado |
| `scripts/php.ps1 artisan test` (SQLite) | 104 testes, 560 asserções, aprovado |
| `scripts/docker-test.ps1` (MySQL 8.4) | 104 testes, 561 asserções, aprovado |
| `TeacherInvitationTest` isolado | 9 testes, 92 asserções, aprovado |
| `php artisan migrate:status` no Docker | migration `000018` aplicada |
| `php artisan view:cache` | aprovado |
| rotas e `schedule:list` | 6 rotas de convite e lote a cada 5 minutos presentes |
| `GET /up` no Docker | HTTP 200 |
| SMTP local Mailpit | mensagem sintética capturada; interface HTTP 200 |

O ambiente local permaneceu saudável em `http://127.0.0.1:8080`, com MySQL exposto apenas em `127.0.0.1:3307`. O Mailpit local capturou a mensagem sintética em `http://127.0.0.1:8025`, sem entrega externa.

## Segurança e dados

Os testes inspecionam que a conta pendente não autentica, token não fica em texto puro, falha de transporte não copia a exceção, senha/papéis de conta existente são preservados e usuário sem `teacher.manage` não emite convite. Auditoria registra IDs e estados, não token, senha ou e-mail.

## Limites do aceite

`Mail::fake` captura o e-mail automatizado com link e a falha SMTP é simulada; o Mailpit permite o teste manual sem destinatário real. A validação de credenciais e reputação de um servidor SMTP externo depende da configuração autorizada de homologação. Ainda é necessário aceite humano em celular/teclado para cadastro, leitura da mensagem, aceite, recusa, expiração e recuperação. Os gates de produção de `RELEASE_CHECKLIST.md` continuam obrigatórios.

## Continuidade concluída

A agenda em vários dias, visitantes, importação assistida, reposição automática e consolidação local foram entregues nas Sprints 18–21.
