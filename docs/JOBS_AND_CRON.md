# Jobs e cron

O JUDOMA não exige worker residente. Rotinas recorrentes são comandos idempotentes acionados pelo scheduler/cron da hospedagem. Os comandos continuam usando o prefixo técnico legado `sigam:`.

## Geração de aulas

`php artisan sigam:sessions:generate` completa a meta configurada de 4 ou 8 próximas aulas de cada turma ativa. O scheduler executa a rotina a cada hora, em lotes de 100 turmas. `--association` restringe o reparo; `--from` e `--until` permanecem aceitas apenas por compatibilidade e são ignoradas. Lock por turma e a chave única da ocorrência protegem reexecuções concorrentes. O serviço lê versões atuais e substituídas dentro de `valid_from/valid_until`, limita a busca a 366 dias e nunca recria uma data/horário já cancelada ou reagendada.

## Cobranças recorrentes

`php artisan sigam:charges:generate --competence=AAAA-MM` gera obrigações de planos mensais, semestrais e anuais. A chave única de origem/competência e o lock do vínculo protegem reexecuções. `php artisan sigam:charges:mark-overdue` marca somente abertas anteriores à referência. Ambos aceitam `--association` para reprocessamento controlado e registram resultado em `scheduled_job_runs`.

Produção deverá chamar `php artisan schedule:run` a cada minuto quando a hospedagem permitir, ou chamar comandos específicos na periodicidade documentada. O scheduler usa `withoutOverlapping`; ainda assim, consulte `scheduled_job_runs` em incidentes ou reprocessamentos.

## Notificações

`php artisan sigam:notifications:generate` varre eventos notificáveis e pode receber `--association`. Chaves únicas tornam a reexecução segura. A agenda padrão é horária. O evento `assessment.due` avisa o professor principal e os auxiliares vigentes de cada turma quando há alunos sem avaliação técnica na modalidade há mais de N semanas (`progression_settings`); a chave inclui turma, usuário e o período de N semanas, então o aviso se repete no máximo uma vez por período.

`php artisan sigam:notifications:send --limit=50` entrega e-mails pendentes em lote. Falha deixa a entrega como `failed`, guarda mensagem resumida e agenda nova tentativa exponencial; após cinco tentativas, ela permanece visível para diagnóstico e não é selecionada automaticamente. A agenda padrão é a cada cinco minutos. Caixa interna não depende de SMTP. O mesmo comando entrega, com o que sobra do lote, os e-mails dos avisos escritos por pessoas (`announcement_recipients`, `AnnouncementEmailService`), com texto completo, mesma reserva `sending`, mesmas cinco tentativas e espera crescente; a revalidação suprime a entrega quando a conta foi inativada, o e-mail mudou ou a preferência de e-mail para avisos foi desligada.

Na Sprint 14, `--limit` aceita somente inteiros de 1 a 500; lote com falha retorna código 1 e registra `error_count`. Fora dos testes, o envio exige `MAIL_MAILER=smtp`: `log` não consome a fila. Reserva condicional em `sending` evita disputa entre execuções CLI; estados presos exigem reconciliação conforme `OPERATIONS_RUNBOOK.md`. O corpo SMTP é genérico, sem dados pessoais. Endereço, conta, associação e preferências são revalidados antes do envio.

## Convites de professor

`php artisan sigam:teacher-invitations:send --limit=50` envia a mensagem transacional própria a cada cinco minutos. Fora dos testes exige SMTP. O comando reserva a linha com update condicional, revalida associação, professor, pessoa, conta, e-mail e vínculo institucional e limita a cinco tentativas. Falha recebe resumo sem segredo e retentativa exponencial. Reserva `sending` interrompida há mais de 15 minutos volta automaticamente à fila; o link continua idempotente caso o provedor tenha recebido a mensagem antes da interrupção.

Convite enviado remove a cópia cifrada do token. Para outra mensagem, o gestor usa **Reenviar**, que revoga o token anterior e cria outro. Não altere estados por SQL e não execute convite em massa.

## Retenção de arquivos de importação

`php artisan sigam:imports:purge-files --days=30` roda diariamente às 02:10. Arquivos e payloads pessoais de lotes mais antigos que o prazo são removidos; lotes pendentes passam a `expired`, enquanto linhas, estados, mensagens, resultados e auditoria permanecem. O intervalo aceito é de 7 a 365 dias. A reexecução ignora arquivos já marcados com `file_deleted_at`.

## Comandos manuais de manutenção

- `php artisan sigam:access:backfill-teachers`: prepara vínculos de acesso de professores legados de forma controlada. Revisar opções com `--help`, executar primeiro em cópia de homologação e auditar o resultado.
- `php artisan sigam:operation:audit-origins`: detecta vínculos financeiros legados sem origem de dojo inequívoca. Não inventa origem e não deve ser substituído por `UPDATE` genérico.
- `php artisan sigam:production:check`: preflight somente leitura para configuração e requisitos operacionais; código diferente de zero bloqueia liberação técnica.

Esses comandos não fazem parte do scheduler. Registrar operador, release, parâmetros e resultado de toda execução em ambiente compartilhado.

## Instalação do cron

No painel do provedor, configurar uma chamada por minuto com caminho absoluto do PHP e do projeto: `cd /srv/sigam && /usr/bin/php artisan schedule:run`. Direcionar saída para log protegido com rotação, nunca para pasta pública. Ajustar os caminhos reais e conferir timezone do servidor/aplicação e `php artisan schedule:list` antes de habilitar. Não instalar dois schedulers para a mesma instância.

A geração de aulas já integra a agenda Laravel horária. Em hospedagem cujo cron mínimo seja 15 minutos, `schedule:run` a cada 15 minutos é suficiente: a expressão horária só dispara no horário devido. Não configurar uma segunda chamada direta de `sigam:sessions:generate` na mesma instância. Pausar o scheduler durante backup/restauração/publicação e monitorar `MaintainFutureClassSessions` em `scheduled_job_runs`.
