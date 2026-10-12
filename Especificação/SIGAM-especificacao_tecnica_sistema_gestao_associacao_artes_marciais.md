# Especificação Técnica --- Sistema de Gestão de Associação de Artes Marciais

**Versão:** 1.0\
**Base funcional:** Especificação Funcional v1.0\
**Plataforma-alvo:** Hospedagem Linux compartilhada/comum, PHP e MySQL\
**Objetivo:** orientar Codex, Claude Code ou equipe de desenvolvimento
na implementação incremental do sistema, reduzindo decisões implícitas e
evitando dependências incompatíveis com hospedagem convencional.

------------------------------------------------------------------------

# 1. Premissas Técnicas

A aplicação deve ser projetada para operar em hospedagem Linux
convencional com:

-   Apache ou Nginx;
-   PHP 8.2 ou superior;
-   MySQL 8.0 ou MariaDB equivalente;
-   HTTPS;
-   acesso a cron;
-   acesso ao sistema de arquivos da aplicação;
-   possibilidade de configuração por `.env`;
-   Composer disponível durante deploy ou dependências previamente
    empacotadas;
-   sem dependência obrigatória de Docker;
-   sem dependência obrigatória de Redis;
-   sem dependência obrigatória de filas externas;
-   sem necessidade de serviços residentes/daemons;
-   sem necessidade de Node.js em produção.

Node.js poderá ser utilizado apenas no ambiente de desenvolvimento/build
do frontend.

------------------------------------------------------------------------

# 2. Arquitetura Recomendada

Arquitetura monolítica modular.

``` text
Browser / Mobile Browser
          |
        HTTPS
          |
   Apache / Nginx
          |
        PHP
          |
  Application Layer
          |
 Domain / Services
          |
 Repository / ORM
          |
        MySQL
          |
   File Storage
```

Não adotar microserviços na primeira versão.

Motivos:

-   menor complexidade operacional;
-   compatibilidade com hospedagem comum;
-   deploy simplificado;
-   menor custo;
-   transações financeiras mais simples;
-   facilidade de manutenção.

------------------------------------------------------------------------

# 3. Stack Recomendada

## Backend

Preferencialmente:

-   PHP 8.2+;
-   Laravel 12 ou versão estável compatível com PHP disponível;
-   Composer;
-   Eloquent ORM;
-   Laravel Validation;
-   Laravel Policies/Gates;
-   Laravel Scheduler executado por cron.

Caso a hospedagem não suporte adequadamente Laravel, utilizar PHP
estruturado em MVC mantendo os mesmos princípios arquiteturais deste
documento.

Não desenvolver aplicação PHP procedural monolítica.

## Banco

-   MySQL 8.0+;
-   InnoDB;
-   `utf8mb4`;
-   timezone consistente.

## Frontend

Priorizar renderização server-side:

-   Blade;
-   HTML5;
-   CSS;
-   Bootstrap 5;
-   JavaScript modular;
-   Fetch/AJAX somente onde trouxer benefício.

Evitar SPA completa na primeira versão.

## Build

Vite poderá ser utilizado no desenvolvimento.

Os assets compilados deverão ser enviados ao servidor.

------------------------------------------------------------------------

# 4. Estrutura de Projeto

Estrutura conceitual recomendada:

``` text
app/
  Domain/
    Association/
    Dojo/
    Person/
    Student/
    MartialArt/
    Classes/
    Attendance/
    Finance/
    Federation/
    Championship/
    Documents/
    Notifications/
    Audit/

  Http/
    Controllers/
    Requests/
    Middleware/

  Models/
  Policies/
  Services/
  Repositories/
  Jobs/
  Console/

database/
  migrations/
  seeders/
  factories/

resources/
  views/
  js/
  css/

routes/
  web.php
  api.php

storage/
  app/private/
  logs/

tests/
  Feature/
  Unit/
```

Não é obrigatório reproduzir literalmente essa árvore, mas manter
separação funcional equivalente.

------------------------------------------------------------------------

# 5. Padrão de Banco de Dados

## Convenções

-   nomes de tabelas em `snake_case`;
-   nomes no plural;
-   PK: `id BIGINT UNSIGNED`;
-   FK: `<entidade>_id`;
-   timestamps:
    -   `created_at`;
    -   `updated_at`;
-   exclusão lógica quando necessária:
    -   `deleted_at`;
-   valores monetários:
    -   `DECIMAL(12,2)`;
-   nunca usar FLOAT para dinheiro;
-   datas sem horário: `DATE`;
-   timestamps de negócio: `DATETIME`;
-   textos curtos: `VARCHAR`;
-   descrições extensas: `TEXT`;
-   JSON somente quando a estrutura realmente for variável.

------------------------------------------------------------------------

# 6. Multi-Associação

O banco deve ser preparado desde a primeira versão para múltiplas
associações.

Não significa necessariamente disponibilizar SaaS multi-tenant
imediatamente.

Entidades operacionais deverão possuir direta ou indiretamente
`association_id`.

Exemplos:

``` text
associations
dojos -> association_id
students -> association_id
class_groups -> association_id
charges -> association_id
championships -> association_id
```

Toda consulta operacional deve respeitar o escopo autorizado.

------------------------------------------------------------------------

# 7. Tabelas Principais

## 7.1 associations

``` text
id
legal_name
trade_name
cnpj
foundation_date
email
phone
website
logo_path
postal_code
street
number
complement
district
city
state
country
status
notes
created_at
updated_at
deleted_at
```

Índices:

``` text
UNIQUE(cnpj)
INDEX(status)
```

------------------------------------------------------------------------

# 8. people

``` text
id
full_name
social_name
cpf
identity_document
identity_issuer
birth_date
email
phone
mobile
photo_path
postal_code
street
number
complement
district
city
state
country
emergency_contact_name
emergency_contact_phone
status
notes
created_at
updated_at
deleted_at
```

CPF deve ser único quando informado.

------------------------------------------------------------------------

# 9. association_person_roles

``` text
id
association_id
person_id
role_type
start_date
end_date
status
notes
created_at
updated_at
```

`role_type` poderá inicialmente utilizar tabela de domínio ou enum
controlado.

Exemplos:

-   president;
-   vice_president;
-   treasurer;
-   secretary;
-   technical_director;
-   administrative_manager.

------------------------------------------------------------------------

# 10. dojos

``` text
id
association_id
code
name
email
phone
postal_code
street
number
complement
district
city
state
country
start_date
status
notes
created_at
updated_at
deleted_at
```

Restrição:

``` text
UNIQUE(association_id, code)
```

------------------------------------------------------------------------

# 11. dojo_person_roles

``` text
id
dojo_id
person_id
role_type
start_date
end_date
status
created_at
updated_at
```

------------------------------------------------------------------------

# 12. martial_arts

``` text
id
association_id NULL
name
description
status
created_at
updated_at
```

`association_id NULL` poderá representar modalidade global do sistema.

------------------------------------------------------------------------

# 13. ranks

``` text
id
martial_art_id
name
display_name
sort_order
color_name
minimum_age
minimum_months
status
created_at
updated_at
```

Não hardcode faixas no código.

------------------------------------------------------------------------

# 14. students

``` text
id
association_id
person_id
registration_number
entry_date
primary_dojo_id
status
exit_date
exit_reason
notes
created_at
updated_at
deleted_at
```

Restrições:

``` text
UNIQUE(association_id, registration_number)
UNIQUE(association_id, person_id)
```

------------------------------------------------------------------------

# 15. student_guardians

``` text
id
student_id
guardian_person_id
relationship_type
is_legal_guardian
is_financial_guardian
is_primary
can_receive_notifications
start_date
end_date
created_at
updated_at
```

------------------------------------------------------------------------

# 16. student_dojo_history

``` text
id
student_id
dojo_id
start_date
end_date
is_primary
reason
created_at
updated_at
```

Não depender exclusivamente de `students.primary_dojo_id` para
relatórios históricos.

------------------------------------------------------------------------

# 17. student_martial_arts

``` text
id
student_id
martial_art_id
start_date
end_date
status
current_rank_id
created_at
updated_at
```

------------------------------------------------------------------------

# 18. teacher_profiles

``` text
id
person_id
registration_number
status
notes
created_at
updated_at
```

------------------------------------------------------------------------

# 19. teacher_martial_arts

``` text
id
teacher_profile_id
martial_art_id
rank_id
federation_registration
certification_expiration
status
created_at
updated_at
```

------------------------------------------------------------------------

# 20. class_groups

``` text
id
association_id
dojo_id
martial_art_id
name
description
main_teacher_id
age_group
minimum_age
maximum_age
capacity
start_date
end_date
status
created_at
updated_at
deleted_at
```

------------------------------------------------------------------------

# 21. class_schedules

Representa recorrência semanal.

``` text
id
class_group_id
weekday
start_time
end_time
teacher_id
location_name
valid_from
valid_until
status
created_at
updated_at
```

`weekday`:

``` text
1 = segunda
2 = terça
...
7 = domingo
```

------------------------------------------------------------------------

# 22. class_enrollments

``` text
id
class_group_id
student_id
start_date
end_date
status
exit_reason
created_at
updated_at
```

Evitar duplicidade de matrícula ativa.

------------------------------------------------------------------------

# 23. class_sessions

Ocorrências concretas.

``` text
id
class_group_id
class_schedule_id
session_date
start_time
end_time
planned_teacher_id
actual_teacher_id
status
cancellation_reason
notes
created_at
updated_at
```

Índice único:

``` text
UNIQUE(class_schedule_id, session_date, start_time)
```

Este índice ajuda na idempotência.

------------------------------------------------------------------------

# 24. attendance

``` text
id
class_session_id
student_id
status
notes
recorded_by
recorded_at
created_at
updated_at
```

Restrição:

``` text
UNIQUE(class_session_id, student_id)
```

Estados:

``` text
present
absent
justified_absence
makeup
trial
```

------------------------------------------------------------------------

# 25. documents

``` text
id
association_id
person_id NULL
student_id NULL
document_type_id
original_name
stored_name
storage_path
mime_type
file_size
issue_date
expiration_date
status
uploaded_by
created_at
updated_at
deleted_at
```

------------------------------------------------------------------------

# 26. document_types

``` text
id
association_id NULL
name
description
requires_expiration
status
created_at
updated_at
```

------------------------------------------------------------------------

# 27. Armazenamento de Documentos

Não armazenar arquivos binários diretamente no MySQL na primeira versão.

Usar filesystem privado:

``` text
storage/app/private/documents/{association_id}/{person_id}/
```

ou diretório equivalente fora do `public_html`.

O download deve ocorrer por controller autorizado:

``` text
GET /documents/{id}/download
```

Fluxo:

``` text
Request
 -> Authentication
 -> Authorization
 -> Locate file
 -> Audit access when required
 -> Stream file
```

Nunca expor caminho físico ao usuário.

Gerar nome armazenado aleatório/UUID.

Validar:

-   MIME;
-   extensão;
-   tamanho;
-   permissões.

------------------------------------------------------------------------

# 28. financial_plans

``` text
id
association_id
dojo_id NULL
martial_art_id NULL
name
description
billing_period
amount
valid_from
valid_until
status
created_at
updated_at
deleted_at
```

------------------------------------------------------------------------

# 29. student_plans

``` text
id
student_id
financial_plan_id
start_date
end_date
base_amount
preferred_due_day
status
created_at
updated_at
```

------------------------------------------------------------------------

# 30. discounts

``` text
id
student_id
student_plan_id NULL
discount_type
discount_value
reason
start_date
end_date
requested_by
approved_by NULL
status
created_at
updated_at
```

Tipos:

``` text
percentage
fixed
```

------------------------------------------------------------------------

# 31. gratuity_requests

``` text
id
association_id
dojo_id
student_id
reason
justification
start_date
end_date
requested_by
requested_at
dojo_approver_id NULL
dojo_decision NULL
dojo_decision_at NULL
dojo_notes NULL
association_approver_id NULL
association_decision NULL
association_decision_at NULL
association_notes NULL
status
created_at
updated_at
```

Não permitir atualização direta para `approved` sem passar pelo serviço
de domínio.

------------------------------------------------------------------------

# 32. charges

Tabela central do financeiro.

``` text
id
association_id
dojo_id NULL
student_id NULL
person_id NULL
source_type
source_id NULL
description
competence
issued_at
due_date
original_amount
discount_amount
final_amount
paid_amount
balance_amount
status
created_by
created_at
updated_at
```

Exemplos `source_type`:

``` text
monthly_fee
championship
graduation_exam
federation
manual
```

Índice recomendado:

``` text
INDEX(association_id, due_date, status)
INDEX(student_id, status)
INDEX(source_type, source_id)
```

------------------------------------------------------------------------

# 33. payments

``` text
id
association_id
charge_id
payment_date
posted_at
amount
payment_method
reference
receipt_path NULL
notes
status
created_by
reversed_by NULL
reversed_at NULL
reversal_reason NULL
created_at
updated_at
```

Estados:

``` text
valid
reversed
```

------------------------------------------------------------------------

# 34. Integridade Financeira

Todas as baixas devem utilizar transação.

Pseudoalgoritmo:

``` text
BEGIN

SELECT charge FOR UPDATE

validar status
validar saldo
validar valor > 0

INSERT payment

recalcular total pago

UPDATE charge
  paid_amount
  balance_amount
  status

INSERT audit_log

COMMIT
```

Em erro:

``` text
ROLLBACK
```

Não confiar em valores calculados enviados pelo navegador.

------------------------------------------------------------------------

# 35. federações

## federations

``` text
id
name
acronym
type
state
country
email
phone
website
status
created_at
updated_at
```

------------------------------------------------------------------------

# 36. federation_memberships

``` text
id
student_id
federation_id
martial_art_id
registration_number
reference_year
registration_date
expiration_date
rank_id
points
status
notes
created_at
updated_at
```

Índice:

``` text
INDEX(student_id, reference_year)
```

------------------------------------------------------------------------

# 37. championships

``` text
id
association_id
dojo_id NULL
martial_art_id
name
description
championship_type
organizer
location_name
start_date
end_date
registration_open_at
registration_close_at
rules_document_path NULL
default_fee
allow_competitor
allow_referee
allow_assistant
status
created_by
created_at
updated_at
deleted_at
```

------------------------------------------------------------------------

# 38. championship_registrations

``` text
id
championship_id
person_id
student_id NULL
dojo_id NULL
participation_type
category
age_at_event
weight
rank_id NULL
team_name NULL
referee_level NULL
assistant_function NULL
registration_fee
status
approved_by NULL
approved_at NULL
created_at
updated_at
```

Restrição recomendada:

``` text
UNIQUE(championship_id, person_id, participation_type)
```

------------------------------------------------------------------------

# 39. championship_results

``` text
id
championship_registration_id
participation_confirmed
placement
medal
points
walkover
disqualified
notes
recorded_by
created_at
updated_at
```

------------------------------------------------------------------------

# 40. student_rank_history

``` text
id
student_id
martial_art_id
rank_id
achieved_at
teacher_person_id NULL
source_type
source_id NULL
notes
created_at
updated_at
```

------------------------------------------------------------------------

# 41. Exames de Graduação

Preparar:

``` text
graduation_exams
graduation_exam_registrations
graduation_exam_results
```

A implementação completa poderá ser posterior.

O resultado aprovado deve criar `student_rank_history` via serviço de
domínio.

------------------------------------------------------------------------

# 42. Autenticação

Tabela `users`:

``` text
id
person_id NULL
association_id NULL
name
email
password
status
last_login_at
email_verified_at
remember_token
created_at
updated_at
```

Usar mecanismo nativo e seguro do framework.

Senhas:

-   `password_hash`/Argon2id ou bcrypt;
-   nunca criptografia reversível;
-   nunca texto puro.

------------------------------------------------------------------------

# 43. RBAC

Tabelas:

``` text
roles
permissions
role_permissions
user_roles
```

## roles

``` text
id
association_id NULL
name
code
scope_type
status
```

## permissions

``` text
id
code
description
```

## role_permissions

``` text
role_id
permission_id
```

## user_roles

``` text
user_id
role_id
association_id NULL
dojo_id NULL
```

------------------------------------------------------------------------

# 44. Permissões Iniciais

Exemplos:

``` text
association.view
association.manage

dojo.view
dojo.manage

person.view
person.manage

student.view
student.create
student.update
student.deactivate

document.view
document.upload
document.delete

class.view
class.manage

attendance.view
attendance.record

finance.view
charge.create
payment.post
payment.reverse

discount.manage

gratuity.request
gratuity.approve_dojo
gratuity.approve_association

federation.view
federation.manage

championship.view
championship.manage
championship.register
championship.approve
championship.result

report.view

user.manage
role.manage

audit.view
```

------------------------------------------------------------------------

# 45. Escopo de Autorização

A autorização não pode considerar somente a permissão.

Deve verificar também escopo.

Exemplo:

``` text
Usuário possui attendance.record?
        |
       Sim
        |
A aula pertence a dojo autorizado?
        |
       Sim
        |
Professor pode acessar esta turma?
        |
       Sim
        |
Permitir
```

Nunca utilizar IDs enviados pelo frontend como prova de autorização.

------------------------------------------------------------------------

# 46. Middleware

Criar middleware para:

-   autenticação;
-   usuário ativo;
-   associação atual;
-   permissão;
-   escopo;
-   proteção CSRF;
-   rate limiting onde aplicável.

------------------------------------------------------------------------

# 47. Auditoria

Tabela:

``` text
audit_logs
```

Campos:

``` text
id
association_id NULL
user_id NULL
event_type
entity_type
entity_id
action
old_values JSON NULL
new_values JSON NULL
ip_address
user_agent
context JSON NULL
created_at
```

Não permitir update/delete pela aplicação normal.

------------------------------------------------------------------------

# 48. Serviços de Domínio

Regras críticas não devem ficar espalhadas nos controllers.

Criar serviços como:

``` text
StudentService
ClassSessionGeneratorService
AttendanceService
StudentPlanService
ChargeGeneratorService
PaymentService
DiscountService
GratuityService
FederationMembershipService
ChampionshipRegistrationService
ChampionshipResultService
DocumentService
AuditService
NotificationService
```

Controller deve coordenar request/response.

------------------------------------------------------------------------

# 49. Geração Recorrente de Aulas

Executada por cron.

Exemplo:

``` bash
* * * * * php /path/artisan schedule:run
```

Scheduler pode executar diariamente:

``` text
GenerateClassSessions
```

Regra:

-   gerar aulas para próximos X dias;
-   sugestão inicial: 30 dias;
-   utilizar `class_schedules`;
-   respeitar `valid_from`;
-   respeitar `valid_until`;
-   não gerar duplicidades;
-   não alterar aulas realizadas.

------------------------------------------------------------------------

# 50. Geração de Mensalidades

Job agendado:

``` text
GenerateRecurringCharges
```

Pode executar diariamente.

Para cada plano ativo:

1.  verificar competência;
2.  verificar se cobrança já existe;
3.  calcular valor base;
4.  aplicar desconto vigente;
5.  verificar gratuidade vigente;
6.  determinar vencimento;
7.  criar cobrança.

Criar chave lógica/idempotente equivalente a:

``` text
student_plan_id + competence + source_type
```

Preferencialmente reforçada por constraint no banco.

------------------------------------------------------------------------

# 51. Atualização de Cobranças Vencidas

Job diário:

``` text
MarkOverdueCharges
```

Regra:

``` text
status = open
AND due_date < hoje
=> overdue
```

Não alterar cobranças pagas, canceladas ou isentas.

------------------------------------------------------------------------

# 52. Notificações

Tabela:

``` text
notifications
```

Campos:

``` text
id
association_id
person_id NULL
user_id NULL
event_type
channel
subject
body
status
scheduled_at
sent_at
error_message
created_at
updated_at
```

Como não haverá worker obrigatório, envio poderá ocorrer:

-   imediatamente em operações leves;
-   ou via cron em lote.

Evitar dependência obrigatória de Redis/queue worker.

------------------------------------------------------------------------

# 53. Cron

A aplicação deve funcionar com uma única entrada de cron quando
framework permitir:

``` text
* * * * * php artisan schedule:run
```

Jobs previstos:

-   gerar aulas;
-   gerar mensalidades;
-   marcar vencidos;
-   verificar documentos vencendo;
-   verificar filiações vencendo;
-   enviar notificações;
-   limpeza controlada de temporários.

Jobs devem ser idempotentes.

------------------------------------------------------------------------

# 54. API

Mesmo com interface Blade, estruturar rotas de maneira preparada para
API futura.

Prefixo:

``` text
/api/v1/
```

Exemplos:

``` text
GET    /api/v1/students
POST   /api/v1/students
GET    /api/v1/students/{id}
PUT    /api/v1/students/{id}

GET    /api/v1/class-sessions
POST   /api/v1/class-sessions/{id}/attendance

GET    /api/v1/charges
POST   /api/v1/charges/{id}/payments

GET    /api/v1/championships
POST   /api/v1/championships/{id}/registrations
```

Não é obrigatório expor toda a API publicamente na V1.

------------------------------------------------------------------------

# 55. Padrão de Resposta API

Sucesso:

``` json
{
  "success": true,
  "data": {},
  "message": null
}
```

Erro:

``` json
{
  "success": false,
  "data": null,
  "message": "Não foi possível concluir a operação.",
  "errors": {
    "field": ["Mensagem de validação"]
  }
}
```

Não retornar stack trace em produção.

------------------------------------------------------------------------

# 56. Validação

Criar classes de Request/Validator.

Exemplos:

``` text
StoreStudentRequest
UpdateStudentRequest
PostPaymentRequest
RequestGratuityRequest
ApproveGratuityRequest
StoreChampionshipRegistrationRequest
```

Validar novamente no serviço regras que não sejam apenas formato de
campo.

------------------------------------------------------------------------

# 57. Concorrência

Operações críticas devem utilizar:

-   transações;
-   row locking quando necessário;
-   constraints;
-   índices únicos.

Principalmente:

-   pagamentos;
-   gratuidades;
-   geração de cobranças;
-   geração de aulas;
-   inscrições;
-   atualização de graduação.

------------------------------------------------------------------------

# 58. Soft Delete

Usar preferencialmente em:

-   pessoas;
-   alunos;
-   dojos;
-   planos;
-   turmas;
-   campeonatos;
-   documentos.

Não utilizar exclusão física em:

-   pagamentos;
-   auditoria;
-   histórico de graduação;
-   presença consolidada;
-   filiações históricas.

------------------------------------------------------------------------

# 59. Segurança de Upload

Permitir somente tipos configurados.

Exemplo inicial:

``` text
PDF
JPG
JPEG
PNG
```

Definir limite, por exemplo:

``` text
10 MB
```

Regras:

-   validar MIME real;
-   renomear arquivo;
-   não confiar na extensão;
-   impedir execução;
-   armazenar fora da área pública;
-   sanitizar nome apresentado;
-   impedir path traversal.

------------------------------------------------------------------------

# 60. Segurança Web

Obrigatório:

-   CSRF;
-   escaping de saída;
-   prepared statements/ORM;
-   proteção XSS;
-   proteção SQL Injection;
-   validação de autorização;
-   proteção IDOR;
-   cookies `Secure`;
-   cookies `HttpOnly`;
-   `SameSite`;
-   HTTPS;
-   headers de segurança;
-   rate limiting em autenticação;
-   sessão regenerada após login.

------------------------------------------------------------------------

# 61. Configuração

Usar `.env`.

Exemplo:

``` text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sistema.exemplo.com

DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=martial_management
DB_USERNAME=...
DB_PASSWORD=...

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=...
MAIL_USERNAME=...
MAIL_PASSWORD=...

FILESYSTEM_DISK=local
```

Nunca versionar `.env` real.

Criar `.env.example`.

------------------------------------------------------------------------

# 62. Logs

Separar:

-   application log;
-   security events;
-   audit log.

Não registrar:

-   senhas;
-   tokens completos;
-   documentos;
-   dados pessoais desnecessários.

Rotacionar logs quando possível.

------------------------------------------------------------------------

# 63. Backup

Documentar rotina mínima:

``` text
Banco MySQL
+
storage privado
```

O backup de apenas um dos dois é insuficiente.

Recomenda-se:

-   backup diário;
-   retenção configurável;
-   cópia externa à hospedagem quando possível;
-   teste periódico de restauração.

------------------------------------------------------------------------

# 64. Relatórios

Consultas pesadas devem ser executadas no servidor com filtros
obrigatórios.

Evitar carregar toda a base no navegador.

Filtros:

-   associação;
-   dojo;
-   modalidade;
-   período;
-   status.

Exportações:

-   CSV prioritário;
-   XLSX;
-   PDF.

Para grandes volumes, preferir CSV.

------------------------------------------------------------------------

# 65. Dashboard

Não recalcular métricas históricas complexas sem necessidade.

Inicialmente usar queries agregadas indexadas.

Se houver degradação futura, criar tabelas de resumo/cache no MySQL.

Não introduzir Redis antecipadamente.

------------------------------------------------------------------------

# 66. Paginação

Todas as listagens potencialmente grandes devem usar paginação
server-side.

Padrão sugerido:

``` text
25 registros
```

Opções:

``` text
25
50
100
```

------------------------------------------------------------------------

# 67. Busca

Busca de aluno deve aceitar:

-   nome;
-   matrícula;
-   CPF;
-   e-mail;
-   telefone.

Evitar consultas `%texto%` indiscriminadas em tabelas muito grandes.

Adicionar índices conforme uso real.

------------------------------------------------------------------------

# 68. Responsividade

Prioridades mobile:

1.  chamada;
2.  turmas;
3.  aluno;
4.  campeonato;
5.  portal do responsável.

Chamada deve permitir marcar rapidamente todos como presentes e alterar
exceções individualmente.

------------------------------------------------------------------------

# 69. Fluxo Técnico da Chamada

Endpoint conceitual:

``` text
POST /class-sessions/{session}/attendance
```

Payload:

``` json
{
  "attendance": [
    {
      "student_id": 100,
      "status": "present"
    },
    {
      "student_id": 101,
      "status": "absent"
    }
  ]
}
```

Backend:

``` text
autenticar
autorizar aula
validar alunos matriculados
BEGIN
upsert attendance
audit
COMMIT
```

------------------------------------------------------------------------

# 70. Fluxo Técnico de Gratuidade

Não aceitar:

``` text
UPDATE gratuity_requests SET status='approved'
```

diretamente por controller genérico.

Usar:

``` text
GratuityService.request()
GratuityService.approveByDojo()
GratuityService.rejectByDojo()
GratuityService.approveByAssociation()
GratuityService.rejectByAssociation()
GratuityService.cancel()
```

Cada método valida estado atual e permissão.

------------------------------------------------------------------------

# 71. Máquina de Estados da Gratuidade

``` text
draft
  |
requested
  |
pending_dojo
  |-------- rejected
  |
pending_association
  |-------- rejected
  |
approved
  |
active
  |
expired
```

Cancelamento conforme regra autorizada.

------------------------------------------------------------------------

# 72. Máquina de Estados da Cobrança

``` text
open
 | \
 |  \ pagamento parcial
 |   partially_paid
 |       |
 |       | quitação
 |       v
 +-----> paid

open/partially_paid
      |
      +--> overdue

open --> cancelled
open --> exempt
```

Não permitir transições arbitrárias.

------------------------------------------------------------------------

# 73. Campeonatos e Financeiro

Ao confirmar inscrição paga:

``` text
ChampionshipRegistrationService
        |
há taxa?
 |           |
não         sim
 |           |
 |       ChargeService
 |           |
 |       charge_id
 |___________|
       |
registration
```

A inscrição não deve implementar tabela financeira paralela.

------------------------------------------------------------------------

# 74. Histórico Esportivo

Não criar tabela duplicando todos os eventos.

Construir histórico a partir de:

``` text
student_rank_history
federation_memberships
championship_registrations
championship_results
graduation_exam_results
```

Caso performance futura exija, criar projeção/materialização, mantendo
as tabelas originais como fonte de verdade.

------------------------------------------------------------------------

# 75. LGPD

Implementar:

-   finalidade clara para dados coletados;
-   acesso por necessidade;
-   trilha de auditoria;
-   retenção configurável;
-   proteção especial de documentos;
-   proteção de dados de menores;
-   mecanismo administrativo para atendimento de solicitações de
    titular.

Exclusão LGPD não deve destruir registros cuja retenção seja necessária
por obrigação legal/financeira. Nestes casos avaliar anonimização ou
restrição de acesso.

------------------------------------------------------------------------

# 76. Testes Automatizados

Cobertura prioritária:

## Unit

``` text
PaymentServiceTest
ChargeCalculationTest
DiscountCalculationTest
GratuityWorkflowTest
ClassSessionGeneratorTest
ChampionshipRegistrationTest
```

## Feature

``` text
StudentCrudTest
AttendanceAuthorizationTest
PaymentAuthorizationTest
DojoScopeTest
DocumentAccessTest
ChampionshipFlowTest
```

------------------------------------------------------------------------

# 77. Casos Críticos de Teste

Obrigatórios:

1.  usuário do Dojo A não visualiza aluno exclusivo do Dojo B;
2.  professor não registra presença em turma sem autorização;
3.  rotina de aulas executada duas vezes não duplica aula;
4.  rotina financeira executada duas vezes não duplica cobrança;
5.  dois requests simultâneos não baixam a mesma cobrança duas vezes;
6.  desconto expirado não é aplicado;
7.  gratuidade sem segunda aprovação não é ativada;
8.  arquivo privado não abre por URL direta;
9.  pagamento estornado preserva histórico;
10. alteração de plano não modifica cobrança já paga;
11. menor sem responsável é bloqueado quando a regra exigir;
12. inscrição paga em campeonato aparece no histórico correto.

------------------------------------------------------------------------

# 78. Seed Inicial

Criar seeders para:

-   permissões;
-   perfil Administrador;
-   tipos básicos de documentos;
-   métodos de pagamento;
-   status/domínios quando tabelados.

Não inserir automaticamente modalidades ou graduações específicas como
dependência estrutural.

Podem existir dados demonstrativos somente em ambiente dev/test.

------------------------------------------------------------------------

# 79. Deploy em Hospedagem Comum

Fluxo esperado:

``` text
1. enviar código
2. configurar document root para /public
3. configurar .env
4. instalar dependências Composer
5. executar migrations
6. executar seed inicial
7. criar link/storage quando necessário
8. configurar permissões de diretórios
9. configurar cron
10. configurar SMTP
11. habilitar HTTPS
12. testar backup
```

Se a hospedagem não permitir document root apontando para `/public`,
documentar configuração segura alternativa sem expor `.env`, `vendor`,
`storage` e código interno.

------------------------------------------------------------------------

# 80. Compatibilidade com Shared Hosting

Não assumir disponibilidade de:

-   sudo;
-   Docker;
-   Supervisor;
-   Redis;
-   RabbitMQ;
-   Elasticsearch;
-   WebSocket server;
-   processos persistentes.

Toda funcionalidade essencial deve operar com:

``` text
PHP request/response
+
MySQL
+
filesystem
+
cron
+
SMTP
```

------------------------------------------------------------------------

# 81. Performance

Desde a primeira versão:

-   evitar N+1;
-   eager loading quando necessário;
-   índices;
-   paginação;
-   filtros server-side;
-   evitar SELECT \* desnecessário;
-   não carregar documentos na memória sem necessidade;
-   streaming para downloads;
-   transações curtas.

------------------------------------------------------------------------

# 82. Índices Essenciais

Além das PK/FK:

``` text
people(cpf)
people(full_name)

students(association_id, status)
students(primary_dojo_id, status)

class_sessions(class_group_id, session_date)
attendance(student_id)
attendance(class_session_id, status)

charges(association_id, status, due_date)
charges(student_id, status)
payments(charge_id, status)

federation_memberships(student_id, reference_year)

championship_registrations(championship_id, status)
```

Revisar índices com EXPLAIN após volume real.

------------------------------------------------------------------------

# 83. Integridade Referencial

Utilizar foreign keys.

Exclusões:

-   preferir `RESTRICT`;
-   usar `CASCADE` apenas em tabelas auxiliares sem valor histórico;
-   nunca cascade-delete pagamentos ou auditoria.

------------------------------------------------------------------------

# 84. Datas e Timezone

Configuração padrão inicial:

``` text
America/Sao_Paulo
```

Porém manter timezone configurável.

Datas exibidas em formato brasileiro:

``` text
dd/mm/aaaa
```

Banco e aplicação devem possuir política consistente para timestamps.

------------------------------------------------------------------------

# 85. Internacionalização

Mesmo que a V1 seja em português brasileiro:

-   textos da interface devem ficar em arquivos de tradução;
-   não gravar labels de interface diretamente em regras de domínio;
-   preparar `pt_BR`.

------------------------------------------------------------------------

# 86. Mensagens de Erro

Usuário:

``` text
Não foi possível registrar o pagamento.
```

Log técnico:

``` text
Payment posting failed: charge_id=... reason=...
```

Não expor:

-   SQL;
-   paths;
-   stack traces;
-   credenciais.

------------------------------------------------------------------------

# 87. Observabilidade Básica

Criar tela administrativa ou comandos para verificar:

-   versão da aplicação;
-   versão PHP;
-   conexão MySQL;
-   storage gravável;
-   cron executando;
-   última execução dos jobs;
-   configuração SMTP sem revelar senha.

------------------------------------------------------------------------

# 88. Controle de Jobs

Tabela sugerida:

``` text
scheduled_job_runs
```

Campos:

``` text
id
job_name
started_at
finished_at
status
processed_count
error_count
message
created_at
```

Útil em hospedagem compartilhada para diagnosticar cron.

------------------------------------------------------------------------

# 89. Recibos

Preparar geração futura de recibos.

Recibo deve utilizar dados congelados da transação:

-   associação;
-   aluno;
-   valor;
-   data;
-   referência;
-   forma de pagamento.

Não depender exclusivamente dos dados cadastrais atuais para reproduzir
documento histórico.

------------------------------------------------------------------------

# 90. Auditoria Financeira

Eventos mínimos:

``` text
charge.created
charge.updated
charge.cancelled
payment.posted
payment.reversed
discount.created
discount.updated
gratuity.requested
gratuity.dojo_approved
gratuity.association_approved
gratuity.rejected
```

------------------------------------------------------------------------

# 91. Estratégia de Implementação para IA de Código

Codex/Claude Code não deve receber a instrução "implemente todo o
sistema".

Executar em etapas.

## Sprint Técnica 0

-   projeto;
-   configuração;
-   autenticação;
-   migrations base;
-   layout;
-   RBAC;
-   auditoria.

## Sprint 1

-   Association;
-   People;
-   Dojo;
-   papéis.

## Sprint 2

-   MartialArt;
-   Rank;
-   Student;
-   Guardian;
-   Teacher.

## Sprint 3

-   ClassGroup;
-   ClassSchedule;
-   Enrollment;
-   Session generation;
-   Attendance.

## Sprint 4

-   Documents;
-   segurança de upload/download.

## Sprint 5

-   FinancialPlan;
-   StudentPlan;
-   Discount;
-   Gratuity.

## Sprint 6

-   Charge;
-   Payment;
-   geração recorrente;
-   relatórios financeiros.

## Sprint 7

-   Federation;
-   FederationMembership.

## Sprint 8

-   Championship;
-   Registration;
-   Results;
-   integração financeira.

## Sprint 9

-   dashboards;
-   notificações;
-   portal;
-   relatórios finais.

------------------------------------------------------------------------

# 92. Regra para Cada Sprint

Antes de gerar código, a IA deve:

1.  analisar migrations existentes;
2.  analisar models;
3.  analisar rotas;
4.  analisar policies;
5.  identificar impacto;
6.  apresentar plano curto;
7.  implementar;
8.  executar testes;
9.  corrigir falhas;
10. atualizar documentação.

Nunca recriar tabela existente sem analisar migrations anteriores.

------------------------------------------------------------------------

# 93. Definition of Done Técnica

Uma história só está concluída quando:

-   migration criada;
-   model criado/ajustado;
-   validação implementada;
-   autorização implementada;
-   serviço de domínio criado quando necessário;
-   controller enxuto;
-   interface implementada;
-   auditoria implementada quando aplicável;
-   testes executados;
-   erro tratado;
-   responsividade verificada;
-   documentação atualizada.

------------------------------------------------------------------------

# 94. README Obrigatório

Manter `README.md` contendo:

-   requisitos;
-   instalação;
-   `.env`;
-   banco;
-   migrations;
-   seeders;
-   cron;
-   SMTP;
-   storage;
-   backup;
-   testes;
-   deploy;
-   permissões de diretório.

------------------------------------------------------------------------

# 95. Documentação Técnica Viva

Criar diretório:

``` text
/docs
```

Arquivos recomendados:

``` text
ARCHITECTURE.md
DATABASE.md
RBAC.md
FINANCE.md
CRON.md
DEPLOYMENT.md
SECURITY.md
CHANGELOG.md
```

A IA deve atualizar esses arquivos conforme alterações estruturais.

------------------------------------------------------------------------

# 96. Decisões Arquiteturais

Criar:

``` text
/docs/adr/
```

Exemplos:

``` text
ADR-001-monolith.md
ADR-002-person-central-entity.md
ADR-003-financial-engine.md
ADR-004-private-file-storage.md
ADR-005-cron-without-workers.md
```

------------------------------------------------------------------------

# 97. Restrições para Codex / Claude Code

Não fazer sem autorização explícita:

-   trocar framework;
-   introduzir microserviços;
-   introduzir Docker como requisito de produção;
-   introduzir Redis como requisito;
-   introduzir queue worker permanente;
-   remover migrations existentes;
-   alterar regras financeiras;
-   desabilitar auditoria;
-   tornar documentos públicos;
-   armazenar senha em texto;
-   usar FLOAT para dinheiro;
-   remover foreign keys para "facilitar";
-   criar modalidades/faixas hardcoded;
-   fazer exclusão física de histórico financeiro.

------------------------------------------------------------------------

# 98. Prioridades Não Funcionais

Ordem:

1.  integridade dos dados;
2.  segurança;
3.  rastreabilidade;
4.  simplicidade operacional;
5.  facilidade de manutenção;
6.  responsividade;
7.  performance;
8.  extensibilidade.

Não sacrificar integridade financeira para simplificar interface.

------------------------------------------------------------------------

# 99. Arquitetura Final Resumida

``` text
                    INTERNET
                       |
                     HTTPS
                       |
                Apache / Nginx
                       |
                  PHP / Laravel
                       |
       +---------------+---------------+
       |               |               |
    Domain          Policies        Scheduler
    Services           |               |
       |               |              Cron
       +------- Application ------------+
                       |
                  Eloquent ORM
                       |
                     MySQL
                       |
          +------------+-------------+
          |                          |
      Dados estruturados       Audit / Histórico

PHP
 |
 +--> Private File Storage
 |
 +--> SMTP
```

------------------------------------------------------------------------

# 100. Resultado Técnico Esperado

A aplicação deverá ser implantável em hospedagem Linux convencional sem
infraestrutura especializada.

Dependências essenciais de produção:

``` text
Linux
Apache/Nginx
PHP 8.2+
MySQL/MariaDB
Filesystem privado
Cron
SMTP
HTTPS
```

O projeto deverá permanecer modular o suficiente para que, futuramente,
seja possível migrar para VPS/cloud, adicionar Redis, filas, object
storage, APIs externas, gateway de pagamento, WhatsApp ou aplicativo
móvel sem reescrever o domínio principal.

------------------------------------------------------------------------

# 101. Instrução Inicial Recomendada para Codex / Claude Code

Ao iniciar o desenvolvimento, utilizar esta especificação juntamente com
a Especificação Funcional.

A IA deverá inicialmente:

1.  ler integralmente ambas as especificações;
2.  criar `ARCHITECTURE.md`;
3.  propor o esquema inicial de banco;
4.  propor estrutura de diretórios;
5.  criar plano de implementação por sprint;
6.  listar decisões ainda pendentes;
7.  não iniciar módulos avançados antes da fundação;
8.  implementar a Sprint Técnica 0;
9.  executar testes;
10. apresentar arquivos criados/modificados e próximos passos.

Quando houver conflito entre conveniência técnica e requisito funcional,
o requisito funcional prevalece, salvo risco de segurança, integridade
ou incompatibilidade com a infraestrutura definida.
