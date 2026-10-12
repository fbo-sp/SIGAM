# ADR-009 — Agenda semanal e visitante por aula

**Estado:** aceito e implementado na Sprint 18.

## Contexto

Uma turma pode ocorrer em vários dias e horários. Guardar uma lista textual impediria versionamento e geração confiável. Alunos também podem visitar gratuitamente outro dojo da mesma associação, mas uma visita não pode criar matrícula, vínculo local, acesso a dados ou obrigação financeira.

## Decisão

- cada combinação de dia/horário continua sendo uma linha de `class_schedules`, com período de vigência;
- criação recebe o conjunto semanal em uma transação. A interface começa com horário comum e revela horários distintos somente quando solicitado;
- substituição encerra todas as linhas da agenda atual no dia anterior e cria um novo conjunto com vigência futura;
- aulas futuras previstas, abertas e sem presença são conciliadas: ocorrência ainda compatível muda para a nova versão; ocorrência removida é cancelada; novos horários são gerados até o horizonte já existente;
- aula realizada, fechada, reagendada, cancelada manualmente ou com presença nunca é apagada;
- `SessionGenerator` considera versões `active` e `replaced` dentro da respectiva vigência;
- visita é uma autorização em `class_session_visitors`, única por aluno/aula, com associação, dojo anfitrião, autor e estado;
- localização usa matrícula exata e retorna erro genérico. Não existe pesquisa ampla de alunos de outro dojo;
- somente `visitor.manage` associativo, gestor local ou professor designado pode incluir/revogar;
- o serviço revalida mesma associação, aula/dojos, modalidade, idade, responsável legal, capacidade e ausência de matrícula regular;
- presença registra `participation_type=regular|visitor`; a lista e o portal mostram a origem sem duplicar o aluno;
- visita não grava `student_dojo_history`, `class_enrollments`, plano, cobrança ou pagamento.

## Consequências

Mudanças de agenda têm impacto explícito e auditável. A visita concede apenas participação naquela ocorrência; não libera diretório, documentos, responsáveis ou financeiro. Revogação é lógica e bloqueada depois que há chamada. A reposição automática por quantidade permaneceu separada e foi implementada na Sprint 20 conforme `ADR-012-meta-de-proximas-aulas.md`.
