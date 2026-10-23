# Notificações e dashboards

Atualização de segurança/entrega da Sprint 14: consulte `production-hardening.md`. E-mail usa aviso genérico, reserva `sending` e revalida preferências. Caixa interna revalida detalhes pessoais e associação; avisos legados sem contexto verificável são redigidos na apresentação. Templates personalizados não são sobrescritos pelo seeder.

## Limites do módulo

`Notifications` recebe eventos internos, aplica templates e preferências e cria entregas por canal. `Reporting` apenas consulta as fontes de verdade para montar dashboards; não mantém saldos, presenças ou contadores paralelos.

A V1 oferece caixa interna e e-mail. A separação evento → template → entrega permite adicionar WhatsApp futuramente sem alterar os módulos que originam o aviso. Não existe dependência de Redis ou worker residente.

## Persistência e idempotência

- `notification_events`: fato canônico, contexto mínimo e chave única por associação;
- `notification_templates`: assunto e corpo por evento/canal, com personalização futura por associação;
- `notifications`: uma entrega por evento, usuário e canal, incluindo tentativa, agenda, envio, leitura e erro;
- `notification_preferences`: habilitação de caixa interna e e-mail por usuário/evento;
- `users.dojo_id`: escopo opcional e explícito do dashboard de dojo.

`NotificationService::publish` usa transação e `firstOrCreate` nos dois níveis. Reexecutar a geração não duplica evento nem entrega. O contexto contém somente valores necessários ao texto; documentos, CPF e dados financeiros detalhados não são copiados.

## Eventos iniciais

O comando `sigam:notifications:generate` detecta cobrança próxima/vencida (inclusive parcialmente paga com saldo após o vencimento), documento e filiação vencendo, campeonato aberto/prazo próximo, gratuidade pendente, aula cancelada, três faltas consecutivas e avaliação técnica pendente (`assessment.due`, ao professor da turma; ver `progression.md`). Destinatários de aluno são o próprio usuário e responsáveis vigentes autorizados a receber comunicação. Aprovações usam a permissão adequada e o escopo do dojo.

Eventos de varredura usam chaves derivadas do recurso e marco relevante. Os módulos podem chamar `NotificationService` diretamente em evoluções futuras sem conhecer canal ou template.

## Entrega e falha

Caixa interna é materializada imediatamente. `sigam:notifications:send --limit=50` envia e-mails pendentes ou falhos em lotes, com até cinco tentativas e espera exponencial limitada a 60 minutos. A entrega guarda erro resumido, contador e último instante. Cada execução aparece em `scheduled_job_runs`.

O scheduler gera eventos a cada hora e tenta e-mails a cada cinco minutos. Em hospedagem sem cron por minuto, os comandos podem ser chamados diretamente na periodicidade disponível.

## Dashboards e escopo

`DashboardService` escolhe uma única visão, na ordem:

1. `dashboard.association`: associação completa;
2. `dashboard.dojo`: somente `users.dojo_id`;
3. `dashboard.teacher`: somente turmas cujo professor principal corresponde à pessoa da conta. "Próximas aulas" vem de `OperationalScope::sessions` nos dojos com concessão vigente e leva à chamada do dojo (`operation.session`); só quem tem `session.view` associativo recebe também o link da associação. Sem concessão, a lista explica que é preciso pedir acesso ao dojo;
4. visão pessoal com atalhos permitidos.

Associação/dojo exibem alunos ativos, entradas, desligamentos, frequência do mês, inadimplência, receita prevista/recebida, benefícios, filiações e campeonatos. Professor vê aulas de hoje/próximas, chamadas pendentes, alunos, faltas consecutivas e campeonatos abertos. As consultas são agregadas sobre tabelas indexadas e não aceitam escopo do navegador.

## UX e privacidade

A caixa interna usa estados “Nova” e “Lida”; todas as preferências ficam em uma seção opcional. Indicadores priorizam pendências e texto, sem depender apenas de cor. O layout reduz quatro colunas para duas e uma em telas menores.

E-mail usa o contato da pessoa quando disponível e, como fallback, o e-mail da conta. Nenhum destinatário inativo ou de outra associação é aceito pelo serviço.

## Mapa de código

- migration: `2026_08_30_000015_create_notifications_and_dashboard_scope.php`;
- domínio: `app/Services/Notifications/` e `app/Services/Reporting/DashboardService.php`;
- catálogo: `app/Support/Notifications/NotificationCatalog.php`;
- comandos: `GenerateNotifications.php` e `SendNotificationEmails.php`;
- HTTP/UI: `NotificationController.php`, `DashboardController.php`, `resources/views/notifications/` e `dashboard.blade.php`;
- testes: `NotificationsAndDashboardTest.php`.

## Manutenção futura

Novos eventos exigem código estável no catálogo, templates dos canais, chave idempotente documentada e teste de destinatário. Nunca coloque HTML não confiável no template; a caixa interna renderiza texto escapado. Antes de criar cache ou tabela-resumo, registre métricas, execute `EXPLAIN` e comprove degradação.
