# Contexto e autorização operacional

## Objetivo

Permitir que uma conta trabalhe em um ou mais dojos sem receber visão associativa ampla. A interface começa em **Meus dojos** e apresenta somente funções disponíveis na unidade escolhida.

## Matriz implementada

| Perfil | Pode fazer | Não pode fazer por concessão local |
|---|---|---|
| Gestor da associação | administrar concessões e manter visão consolidada conforme seus papéis associativos | conceder conta externa, papel fora da associação ou sobrescrever histórico |
| Gestor do dojo | consultar e cadastrar alunos regulares, corrigir dados pessoais desses alunos (sem CPF e documentos), criar e editar turmas, matricular e encerrar matrículas, designar professores auxiliares, operar aulas/chamadas, escolher professores elegíveis, administrar cobranças locais e inscrever seus alunos em campeonatos da associação | cadastrar professor institucional, acessar outro dojo, central/download amplo de documentos, configurar catálogo financeiro/campeonato, exports e administração associativa |
| Professor do dojo | ver turmas/aulas designadas (como principal, previsto, substituto ou auxiliar) registrar/finalizar a chamada correspondente a partir do dia da aula e reabri-la com motivo | ver diretório de alunos, aula alheia, cancelar/reagendar, documentos, financeiro ou rotas associativas |
| Aluno/responsável | continua no portal por relacionamento | concessão de dojo não é usada pelo portal |

Os papéis semeados são `dojo_operator` (`scope_type=dojo`) e `dojo_teacher` (`scope_type=teacher`). Alterações futuras nas capacidades devem ser explícitas; o seeder não sobrescreve papel já existente.

## Regras

1. associação, dojo, conta e papel precisam estar ativos;
2. início e término são inclusivos; concessão futura, vencida ou revogada não funciona;
3. mesma função não aceita períodos sobrepostos; repetição exata é idempotente;
4. revogar uma unidade não revoga as demais;
5. `association_id`, `dojo_id`, `user_id`, `role_id` e IDs de aula são revalidados no backend;
6. professor local precisa de vínculo institucional e local, ambos na associação correta;
7. a consulta de aula do professor é filtrada no SQL antes da paginação e do carregamento da lista;
8. aluno aparece para o dojo somente por vínculo regular vigente em `student_dojo_history`; dojo principal isolado e visita não concedem diretório;
9. visitante é localizado por matrícula exata e autorizado somente na aula; ele não aparece na lista geral de alunos do dojo;
10. chamada revalida a autorização dentro de `AttendanceService`, após recarregar a aula sob lock.

## Fluxos e rotas

- `GET /meus-dojos`: unidades derivadas das concessões vigentes ou de autorização associativa;
- `/operacao/dojos/{dojo}`: início, alunos regulares, turmas, aulas/chamada, equipe, financeiro local e campeonatos compartilhados;
- `/operacao/dojos/{dojo}/alunos/novo`: cadastro composto restrito por `student.manage`; upload contextual exige `student.document.manage`;
- `/associacoes/{association}/acessos-dojos`: criar e revogar concessões, exige `role.manage` associativo;
- cadastro institucional de professor continua nas rotas da associação;
- vínculo local de professor escolhe somente `teacher_association_memberships` ativos;
- o vínculo local libera automaticamente a concessão `dojo_teacher` no mesmo período quando o professor já tem conta (ativa ou pendente) na associação, e encerrar o vínculo revoga as concessões de professor ativas daquele usuário no dojo (`TeacherDojoAccess`, decisão de 22/10/2026). Professor sem conta recebe a concessão ao aceitar o convite.

## Persistência e serviços

- migrations `2026_08_30_000016_create_operational_access_tables.php` e `2026_10_11_000023_consolidate_dojo_operation.php`;
- models `DojoRoleAssignment`, `TeacherAssociationMembership` e `DojoTeacherAssignment`;
- `OperationalScope` compõe autorização e consultas restritas;
- `AccessAdministration` usa transações, locks, valida associação/período e audita `dojo_access.granted|revoked` e `dojo_teacher.linked|revoked`;
- `DirectRegistrationService::registerTeacher` cria idempotentemente o vínculo institucional no cadastro associativo;
- `TeacherDojoAccess` concede e revoga o acesso de professor a partir do vínculo local, sem duplicar concessão existente;
- comandos `sigam:access:backfill-teachers`, `sigam:access:sync-teacher-dojos` e `sigam:operation:audit-origins` são simulação por padrão.

## Testes de manutenção

`OperationalContextAuthorizationTest` cobre dois dojos, rota antiga, `users.dojo_id` sem concessão, período futuro/vencido/revogado, revogação independente, recurso externo, principal sem vínculo regular, professor institucional/local/designado, chamada alheia, replay e backfill conservador.

Ao adicionar operação local, inclua caso positivo, ausência de permissão, outro dojo da mesma associação, outra associação, revogação e rota legada. Nunca filtre uma coleção depois de paginar.
