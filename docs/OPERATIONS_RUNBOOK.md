# Operação e incidentes

## Verificação diária

- Conferir HTTP `/up` (disponibilidade do processo, não teste completo de banco/SMTP), login e avisos de erro.
- Revisar `scheduled_job_runs`: última execução, status e contagem de erros; ausência de execução também é incidente. Ver `JOBS_AND_CRON.md`.
- Conferir entregas `failed`, `sending` antigas e pendentes; espaço livre de banco/disco, idade do último backup externo e vencimento do certificado TLS.
- Usar logs com acesso restrito e retenção aprovada. Não enviar dumps, dados pessoais ou configuração em tickets; registrar identificador de recurso/erro sanitizado.

## Falha de publicação / erro 500

Pausar cron quando houver risco de escrita inconsistente, manter manutenção, preservar evidência e verificar release ativa, plataforma, permissões e migration. Não habilitar debug público. Rodar preflight sem imprimir configuração. Seguir rollback de `DEPLOYMENT.md` se a correção não for segura na janela. Definir responsável e comunicar indisponibilidade em linguagem simples.

## Cobrança ou pagamento divergente

Não apagar lançamentos, editar saldo por SQL ou gerar nova chave para contornar erro. Verificar origem, competência, chave idempotente, auditoria e soma de pagamentos não estornados. Reexecutar geração com mesma competência/escopo se comprovadamente necessário; baixa/estorno devem usar serviços e permissões existentes. Salvar parecer da conciliação.

### Origem de dojo ausente em vínculo legado

Executar `php artisan sigam:operation:audit-origins` primeiro no modo seco e guardar apenas contagens/IDs técnicos. Aplicar correção automática somente quando o próprio comando classificar a origem como inequívoca e após backup. Caso haja mais de um dojo possível, manter a linha fora da geração e decidir manualmente com evidência; `primary_dojo_id` não prova origem financeira. Nunca preencher em massa por SQL.

## Importação interrompida

Não reenviar o mesmo arquivo para contornar estado: o hash impede replay e o lote existente é retomável. Verificar `import_batches`, linhas pendentes/inválidas, arquivo privado e auditoria. Se a retenção já marcou `file_deleted_at`, preserve o histórico e crie um novo lote somente com novo envio autorizado. Não restaure payload pessoal em log ou ticket.

## Aulas futuras ausentes

Conferir se associação, dojo e turma estão ativos, se existe agenda vigente, se a meta é 4 ou 8 e se o scheduler executou `sigam:sessions:generate`. Uma execução manual idempotente pode recompor déficit. Não recriar por SQL e não reativar ocorrência cancelada/reagendada; essas exceções são intencionais.

## E-mail falho ou preso

Verificar SMTP no painel do provedor sem expor segredos. `failed` com menos de cinco tentativas tem retentativa agendada. Após cinco, não há envio automático. `suppressed` significa alteração de conta/endereço/preferência. Não reabilitar automaticamente.

`sending` antigo exige conciliação manual: confirmar que o processo terminou e consultar evidência do provedor. Se aceito, registrar conclusão; se comprovadamente não aceito, operador autorizado pode preparar reprocessamento controlado do ID, com registro de auditoria operacional. Em dúvida, não reenviar. Nunca executar UPDATE global para voltar todos a `pending`. O JUDOMA não tem painel de reconciliação de SMTP nesta versão.

Convites usam uma fila separada e recuperam automaticamente uma reserva `sending` com mais de 15 minutos. Uma eventual entrega duplicada não duplica o aceite. Se chegar a cinco falhas, corrija SMTP e use **Reenviar** na tela do professor: isso revoga o token anterior. Não recupere `encrypted_token`, não copie links de banco/log e não altere `token_hash`. `suppressed` em convite indica mudança de cadastro/vínculo e exige revisão antes de novo envio.

## Arquivo ausente / recuperação

Não tornar o disco público para tentar corrigir downloads. Conferir referência privada, associação e vínculo; verificar arquivo no backup. Recuperar para destino isolado e conferir hash antes de recompor arquivo autorizado. Procedimento completo em `BACKUP_RESTORE.md`.

## Suspeita de acesso indevido

Restringir a conta afetada e preservar auditoria, logs e backups; envolver responsável técnico e de privacidade. Não apagar histórico nem copiar dados para serviços externos. Avaliar sessões existentes, papéis e associações. Prazos e obrigações de comunicação devem ser definidos pela governança competente; este documento não é parecer jurídico.
