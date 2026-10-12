# Evidências da Sprint 16

> Evidência histórica daquele incremento. O estado atual está em `IMPLEMENTATION_STATUS.md` e a validação consolidada em `SPRINT21_VALIDATION.md`.

Data: 07/10/2026. Ambiente: Docker Desktop, PHP 8.4.25, SQLite em memória e MySQL 8.4.10 isolado em `sigam_test`. O volume local de homologação não foi apagado.

| Verificação | Resultado |
|---|---|
| Migration incremental | `2026_10_07_000017_create_direct_registration_tables`: aplicada |
| Matriz focada SQLite | 8 testes, 54 assertions, sucesso |
| Matriz focada MySQL | 8 testes, 54 assertions, sucesso dentro da regressão completa |
| Regressão SQLite | 95 testes, 468 assertions, sucesso |
| Regressão MySQL | 95 testes, 469 assertions, sucesso |
| Pint | 214 arquivos verificados, sucesso |
| Views Blade | compilação concluída |

## Cobertura específica

- aluno, pessoa e responsável criados na mesma tarefa;
- ativação de menor com responsável legal e bloqueio sem responsável;
- pré-cadastro permitido quando ainda existem pendências;
- matrícula automática e replay sem duplicar pessoa, aluno, vínculo ou modalidade;
- segundo dojo regular sem duplicar aluno nem encerrar o primeiro vínculo;
- CPF externo sem identificação ou vínculo indevido;
- gestor local limitado ao dojo concedido e sem upload por payload forjado;
- professor direto com vínculo institucional e matrícula automática;
- documento privado vinculado explicitamente a responsável ou professor;
- tipo de documento de outra associação recusado antes de criar cadastro ou arquivo.

## Limites do aceite

Os testes comprovam a implementação técnica. A homologação humana ainda deve executar em celular e teclado: pré-cadastro, cadastro completo de menor, aluno já existente em segundo dojo, professor novo e documento de responsável. Naquele incremento, conta e convite ficaram destinados à Sprint 17 e foram implementados posteriormente; visitantes, vários dias e aulas automáticas continuam nas Sprints 18–19. Os gates de produção de `RELEASE_CHECKLIST.md` permanecem obrigatórios.
