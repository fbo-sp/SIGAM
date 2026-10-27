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

## Avisos escritos por pessoas

Proposta aprovada em `/mnt/project-files/sigam/proposta-avisos-por-nivel.md` (Fabio, 2026-10-27: e-mail com texto completo, envio direto do professor, sem respostas).

- Telas: `/associacoes/{association}/avisos` e `/operacao/dojos/{dojo}/avisos` (lista, "Enviar aviso", revisão e detalhe). Atalhos: card "Avisos" na associação e no início do dojo, "Enviar aviso" no "Mais" do professor e "Enviar aviso à turma" na página da turma (já com a turma marcada). Na aba Alunos do professor, cada linha tem "Aviso" (já com o aluno marcado) e, acima da lista, "Avisar estes N alunos" leva os alunos do filtro atual (busca, Faltando ou Prontos), um botão por dojo quando há mais de um; os botões só aparecem nos dojos em que a conta tem `announcement.send`.
- Alcance (`AnnouncementService::level` e `options`): associação = todos, dojos, turmas ou pessoas; gestor do dojo = todo o dojo, turmas do dojo ou pessoas do dojo; professor = as próprias turmas (principal ou auxiliar vigente) ou alunos delas. Em cada envio marca-se quem recebe: alunos, responsáveis (vigentes com `can_receive_notifications`) e, fora do nível professor, professores. Professor escolhido pelo nome sempre recebe.
- Revisão: antes de enviar, a tela mostra quantas pessoas recebem, quantas têm conta, quantos e-mails diferentes e quem fica sem contato. O envio usa uma chave única gerada na revisão; repetir o envio devolve o mesmo aviso.
- Entrega: quem tem conta recebe na caixa Notificações (evento `announcement.sent`, sempre exibido); o e-mail leva título e texto completos e vai também para quem não tem conta, pelo e-mail do cadastro, uma vez por endereço. As preferências "Avisos da associação, do dojo e dos professores" da caixa e do e-mail são respeitadas.
- Histórico: `announcement_recipients` guarda os destinatários do momento do envio; o detalhe mostra quem leu no JUDOMA e o estado de cada e-mail. Associação vê todos os avisos, gestor do dojo os do dojo (inclusive dos professores) e professor só os próprios. Sem anexos, agendamento ou respostas nesta versão.
- Não lidos no portal (`PortalAccessService::unreadNotices`): conta qualquer notificação interna não lida da conta, escrita ou automática. O início do portal mostra sempre o indicador "Avisos" depois de "Frequência no mês": em vermelho leve com "N novo(s)" e o título do mais recente, ou "Nada novo" em cor neutra. O atalho "Avisos" troca o sino por um sino com ponto, ganha o número e fundo vermelho leve, e a aba "Mais" do celular ganha um ponto vermelho. A caixa Notificações lista as não lidas primeiro. O responsável vê os não lidos da própria conta em qualquer aluno que abrir.

## Dashboards e escopo

`DashboardService` escolhe uma única visão, na ordem:

1. `dashboard.association`: associação completa;
2. `dashboard.dojo`: somente `users.dojo_id`;
3. `dashboard.teacher`: tela "Hoje" do professor, montada por `TeacherWorkspaceService`. Indicadores (aulas de hoje, chamadas abertas, minhas turmas); alerta expansível com todas as chamadas ainda abertas de dias anteriores (sem limite de período); cartões das aulas de hoje com "Fazer chamada" ou "Ver chamada"; "Próximos dias" (7 dias, até 8 aulas). As aulas vêm de `OperationalScope::sessions` nos dojos com concessão vigente, só as próprias (principal ou auxiliar vigente), e levam à chamada do dojo (`operation.session`); só quem tem `session.view` associativo recebe o link da associação quando falta a concessão. Sem concessão, a tela explica que é preciso pedir acesso ao dojo. Botão padrão "← Início" (`partials/back-to-dashboard`, leva a `dashboard`, que abre o painel, o "Hoje" do professor ou o portal conforme a conta): aparece nas telas do dojo, nas abas Turmas e Alunos do professor, nos avisos, na caixa Notificações e em Minha conta (para quem usa o portal, Minha conta mostra "← Meus dados"), também quando a navegação do professor aparece. No portal, as subtelas, o progresso e o quiz voltam ao início do aluno.

   Navegação do professor (`TeacherWorkspaceService::usesNavigation`): abas Hoje, Turmas (`/professor/turmas`: turmas como principal ou auxiliar, horário, papel, "Abrir" e cobertura do currículo dos últimos 90 dias quando `ProgressionPanelService::dojoClasses` libera), Alunos (`/professor/alunos`: busca, filtros "Faltando" com três faltas seguidas nos últimos seis meses e "Prontos" com o currículo da próxima faixa todo trabalhado, faixa, turmas, celular com toque para ligar, "Emergência" e "Ficha") e Mais (acompanhamento técnico por dojo, meus dojos, avisos, meu portal e campeonatos abertos). No celular as abas ficam numa barra inferior fixa; no computador, logo abaixo do menu. Aparecem no painel, nas telas `/professor/*`, `/operacao/*` e em notificações. O gestor do dojo (`dashboard.dojo`) que também é professor com turma ativa recebe as mesmas abas e o bloco "Hoje" antes do painel do dojo; quem tem `dashboard.association` não recebe;
4. visão pessoal com atalhos permitidos.

Associação/dojo exibem alunos ativos, entradas, desligamentos, frequência do mês, inadimplência, receita prevista/recebida, benefícios, filiações e campeonatos. Professor vê aulas de hoje/próximas, chamadas pendentes, alunos, faltas consecutivas e campeonatos abertos. As consultas são agregadas sobre tabelas indexadas e não aceitam escopo do navegador.

## UX e privacidade

A caixa interna usa estados “Nova” e “Lida”; todas as preferências ficam em uma seção opcional. Indicadores priorizam pendências e texto, sem depender apenas de cor. O layout reduz quatro colunas para duas e uma em telas menores.

E-mail usa o contato da pessoa quando disponível e, como fallback, o e-mail da conta. Nenhum destinatário inativo ou de outra associação é aceito pelo serviço.

## Mapa de código

- migration: `2026_08_30_000015_create_notifications_and_dashboard_scope.php`;
- domínio: `app/Services/Notifications/` (inclui `AnnouncementService` e `AnnouncementEmailService`), `app/Services/Reporting/DashboardService.php` e `app/Services/Classes/TeacherWorkspaceService.php`;
- catálogo: `app/Support/Notifications/NotificationCatalog.php`;
- comandos: `GenerateNotifications.php` e `SendNotificationEmails.php`;
- HTTP/UI: `NotificationController.php`, `AnnouncementController.php`, `resources/views/announcements/`, `DashboardController.php`, `Classes/TeacherWorkspaceController.php`, `resources/views/notifications/`, `resources/views/teacher/` e `dashboard.blade.php`;
- testes: `NotificationsAndDashboardTest.php`, `TeacherViewTest.php` e `AnnouncementTest.php`.

## Manutenção futura

Novos eventos exigem código estável no catálogo, templates dos canais, chave idempotente documentada e teste de destinatário. Nunca coloque HTML não confiável no template; a caixa interna renderiza texto escapado. Antes de criar cache ou tabela-resumo, registre métricas, execute `EXPLAIN` e comprove degradação.
