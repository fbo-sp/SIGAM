# Cadastro direto de alunos, responsáveis e professores

## Objetivo

Criar pessoa e perfil esportivo em uma única tarefa, preservando identidade central, escopo, histórico e arquivos privados. A entrada do aluno fica em **Meu dojo → Alunos → Novo aluno**; o professor é cadastrado pela associação.

## Fluxos

### Aluno

1. o backend deriva a associação e valida o dojo da rota/contexto;
2. cria pessoa nova ou reutiliza pessoa acessível com confirmação explícita por CPF;
3. gera matrícula `ALU-{associação}-{sequência}`;
4. cria ou reutiliza o perfil de aluno, o vínculo regular do dojo e a modalidade;
5. cria o responsável na mesma transação, quando informado;
6. salva como pré-cadastro ou ativa após validar nascimento e responsável legal de menor;
7. opcionalmente guarda documento privado ligado ao aluno ou ao responsável.

Um aluno já existente pode receber outro vínculo regular. Tornar o novo dojo principal é explícito e não encerra os demais períodos.

### Professor

Somente `teacher.manage` associativo abre o fluxo. Pessoa, perfil, matrícula `PROF-{associação}-{sequência}`, habilitação e `teacher_association_memberships` são criados juntos. Documento opcional recebe `teacher_profile_id`. Quando “Preparar convite” está marcado, a mesma transação provisiona conta pendente e convite; senha e acesso só existem após o aceite descrito em `teacher-invitations.md`.

## Autorização e privacidade

- gestor local precisa de concessão vigente com `student.manage`; documento no cadastro exige também `student.document.manage`;
- papel local nunca abre a rota associativa de alunos, pessoas ou documentos;
- IDs de dojo, pessoa, modalidade, graduação e documento são revalidados no serviço;
- a seleção associativa mostra no máximo 100 pessoas ativas e apenas o final do CPF;
- no dojo, não há lista ampla de pessoas. Reutilização ocorre por CPF exato e confirmação, sem mostrar dados de outro dojo;
- CPF encontrado somente fora da associação não é vinculado nem identificado ao operador;
- arquivos usam armazenamento privado, UUID, validação de MIME/extensão/assinatura e auditoria existente.

## Persistência e código

- migration: `2026_10_07_000017_create_direct_registration_tables.php`;
- orquestração: `DirectRegistrationService`;
- HTTP: `DirectRegistrationController`, `DirectStudentRegistrationRequest` e `DirectTeacherRegistrationRequest`;
- idempotência: `DirectRegistrationRequest` com unicidade por associação, operação e chave;
- sequência: `RegistrationCounter` bloqueado em transação e verificação contra matrículas legadas;
- documentos: `DocumentService` valida aluno, professor, responsável e associação antes de persistir;
- telas: `sports/students/direct.blade.php` e `sports/teachers/direct.blade.php`.

## Testes de manutenção

`DirectRegistrationTest` cobre cadastro completo de menor e responsável, ativação, pré-cadastro, matrícula automática, replay, segundo dojo, CPF externo, escopo local, professor institucional e documentos de responsável/professor em SQLite e MySQL.

Ao alterar o fluxo, mantenha casos de outro dojo, outra associação, replay, menor sem responsável e arquivo com contexto incoerente. Não transforme `primary_dojo_id`, CPF ou ID recebido em autorização.
