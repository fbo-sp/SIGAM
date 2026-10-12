# Validação da Sprint 18

> Evidência histórica daquele incremento. A continuidade foi concluída até a Sprint 21; para o estado atual, consulte `IMPLEMENTATION_STATUS.md` e `SPRINT21_VALIDATION.md`.

Data: 07/10/2026.

## Escopo validado

- criação de turma com vários dias, horário comum e horários diferentes;
- conjunto semanal versionado, professor/local por dia e conflito de professor;
- reconciliação de aulas futuras e preservação de presença/exceções;
- geração por versão histórica dentro da respectiva vigência;
- visitante gratuito por aula, sem matrícula, vínculo com dojo ou cobrança;
- identificação por matrícula exata, mesma associação, modalidade, idade, responsável e capacidade;
- gestor associativo, gestor local e professor designado com `visitor.manage`;
- chamada, indicador operacional e portal distinguindo visita de matrícula regular.

## Evidências automatizadas

| Verificação | Resultado |
|---|---|
| `scripts/php.ps1 vendor/bin/pint --test` | aprovado |
| `scripts/php.ps1 artisan test` (SQLite) | 113 testes, 607 asserções, aprovado |
| `scripts/docker-test.ps1` (MySQL 8.4) | 113 testes, 608 asserções, aprovado |
| `Sprint18Test` isolado | 9 testes, 50 asserções, aprovado |
| `php artisan view:cache` | aprovado |
| migration `000019` no Docker | aplicada |
| rotas de visitante | 4 rotas administrativas/operacionais presentes |
| aplicação e login no Docker | HTTP 200 |

## Segurança e integridade

Os testes incluem aluno de outra associação, matrícula inexistente, aluno já regular, usuário sem permissão e professor tentando acessar aula alheia. Replay não duplica visita. Menor sem responsável e turma lotada são recusados. O fechamento registra proveniência da presença, enquanto contagens confirmam ausência de nova matrícula e cobrança.

A substituição da agenda cancela apenas ocorrência futura prevista, aberta e sem presença. Aula realizada ou com presença permanece ligada à versão histórica. O gerador continua idempotente em SQLite e MySQL.

## Limites do aceite

É necessário homologar com usuários em celular e teclado: seleção de dois/três dias, modo de horários diferentes, leitura da prévia, inclusão por matrícula, revogação e chamada com visitante. A Sprint 18 não implementa a meta automática de quatro/oito próximas aulas; essa reposição pertence à Sprint 20. Os gates de produção continuam em `RELEASE_CHECKLIST.md`.

## Continuidade concluída

A importação assistida foi entregue na Sprint 19, a reposição de 4/8 aulas na Sprint 20 e a consolidação financeira/esportiva local na Sprint 21.
