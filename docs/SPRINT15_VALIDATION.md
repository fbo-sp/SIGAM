# Evidências da Sprint 15

> Evidência histórica daquele incremento. O estado atual está em `IMPLEMENTATION_STATUS.md` e a validação consolidada em `SPRINT21_VALIDATION.md`.

Data: 07/10/2026. Ambiente: Docker Desktop, PHP 8.4.25 e MySQL 8.4.10. O banco de testes isolado é `sigam_test`; o volume local de homologação não foi apagado.

| Verificação | Resultado |
|---|---|
| Migration incremental | `2026_08_30_000016_create_operational_access_tables`: aplicada |
| Matriz focada | 9 testes, 51 assertions, sucesso em MySQL |
| Regressão SQLite | 87 testes, 414 assertions, sucesso |
| Regressão MySQL | 87 testes, 415 assertions, sucesso |
| Pint | 205 arquivos verificados, sucesso |
| Aplicação e banco | contêineres saudáveis |
| HTTP público | `/entrar` e `/up` retornaram 200 |
| Backfill | simulação executada; zero vínculos comprováveis/ausentes neste conjunto, nenhuma escrita |

## Cobertura específica

- dois dojos da mesma associação e recurso de associação externa;
- `users.dojo_id` sem valor autorizativo;
- concessão futura, vencida, revogada e múltiplos dojos independentes;
- rota associativa antiga negada para papel local;
- professor institucional, vínculo local, turma/aula designada e aula alheia;
- chamada autorizada revalidada no serviço de domínio;
- período sobreposto recusado e replay exato idempotente;
- aluno principal sem vínculo regular não aparece no diretório;
- backfill sem criação de conta, papel ou concessão.

## Limites do aceite

Esta validação comprova implementação técnica, não aceite humano. Fluxos autenticados foram renderizados pelos testes e as views foram compiladas; ainda é recomendável o usuário homologar em celular as tarefas “conceder acesso”, “escolher dojo”, “vincular professor” e “fazer chamada”. Produção continua condicionada aos gates de `RELEASE_CHECKLIST.md` e às Sprints 16–20.
