# Sprint 21 — Consolidação da operação

## Entrega técnica

- página final do dojo com alunos, turmas, aulas, professores, importação, financeiro e campeonatos conforme permissões;
- financeiro local com vínculo de plano de origem explícita, solicitação/decisão local de gratuidade, cobranças e pagamentos restritos ao dojo;
- cobrança recorrente derivada de `student_plans.dojo_id`, sem usar dojo principal e sem recalcular histórico;
- campeonato único da associação, inscrições locais filtradas antes da paginação e participação do dojo idempotente;
- graduação da inscrição local derivada do cadastro esportivo, sem confiar em ID informado no formulário;
- migration incremental `000023`, diagnóstico/backfill conservador e documentação de manutenção.

## Comandos de validação

```powershell
scripts/php.ps1 artisan test
scripts/php.ps1 artisan test --configuration=phpunit.mysql.xml
scripts/composer.ps1 pint -- --test
scripts/php.ps1 artisan sigam:operation:audit-origins --association=ID
scripts/php.ps1 artisan sigam:operation:audit-origins --association=ID --apply
```

O comando de origem não mostra nomes, documentos ou contatos. A primeira execução deve ser sem `--apply`; faça backup e registre as contagens antes da aplicação em homologação.

## Matriz coberta

`Sprint21Test` verifica aluno multi-dojo com dojo principal diferente da origem, cobrança na unidade correta, negação de outra unidade, campeonato compartilhado, ocultação de inscrição alheia, bloqueio de aluno sem vínculo e migração seca/aplicada/idempotente sem reatribuir cobrança histórica. As suítes anteriores continuam cobrindo associação externa, rotas antigas, pagamentos, gratuidades, portal, documentos e visitas.

Evidência local em 11/10/2026:

- SQLite: 133 testes, 710 asserções, 1 teste condicionado ao ambiente ignorado;
- MySQL 8.4 em contêiner isolado: 133 testes e 715 asserções aprovados;
- Pint: aprovado em modo `--test`;
- banco de homologação Docker: migration `000023` aplicada, seeder sincronizado, diagnóstico seco com zero pendências e aplicação saudável em `http://127.0.0.1:8080`.

## Aceite pendente fora do código

A implementação conclui o roadmap técnico das Sprints 1–21, mas não libera produção. Permanecem os gates humanos e operacionais do `RELEASE_CHECKLIST.md`: aceite com usuários representativos, privacidade/jurídico, credenciais reais, backup/restauração no provedor e decisão formal de go-live.
