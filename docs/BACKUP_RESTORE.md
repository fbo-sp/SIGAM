# Backup e restauração

## Política a aprovar

Proposta inicial: backup diário e antes de cada release; RPO de até 24 horas e RTO de até 4 horas. **São metas propostas, não SLAs comprovados.** Aprovar janela, retenção, localização, operador e custódia considerando volume real e obrigações de privacidade. Manter cópia externa criptografada e testar recuperação periodicamente. SHA-256 verifica integridade, não criptografa nem autentica um backup contra adulteração maliciosa.

Conjunto recuperável: dump MySQL, `storage/app/private`, versão exata de código/lockfile e configuração/APP_KEY em cofre separado. Sem a chave preservada podem ficar irrecuperáveis dados cifrados e sessões. Não arquivar `.env` em diretórios públicos nem imprimir credenciais.

## Gerar backup em Linux

Requer PHP, cliente MySQL compatível, gzip, tar e sha256sum. O operador prepara um arquivo de opções do cliente MySQL fora do projeto com permissões 600, guardando nele host/usuário/senha; nunca passa senha nos argumentos. Confirmar privilégios de leitura necessários ao dump.

1. Suspender o cron e demais escritores; aguardar operações em andamento. Modo de manutenção HTTP sozinho não bloqueia CLI.
2. Escolher pasta nova absoluta, fora do projeto e de qualquer raiz web. O script não sobrescreve destinos existentes.
3. Executar, adaptando apenas caminhos e nome do banco:

```sh
sh scripts/backup.sh /srv/sigam /srv/backups/sigam-release-001 /srv/secrets/mysql-backup.cnf sigam --writers-stopped
```

O script entra em manutenção se necessário, usa dump transacional, compacta banco/documentos, grava manifesto e o confere. Se já estava em manutenção, mantém esse estado; caso contrário, libera HTTP na saída. Só reativar cron após verificar sucesso. Falha deixa pasta parcial para diagnóstico: **não usar backup sem manifesto validado**. Não apagar a última cópia boa.

4. Transferir para armazenamento externo criptografado autorizado, verificar novamente o manifesto e registrar responsável, horário, tamanho e identificador de release. Não há upload automático para terceiros.

## Restaurar com segurança

1. Trabalhar em ambiente isolado, sem SMTP de saída e sem cron. Verificar `sha256sum -c SHA256SUMS` antes de usar o conjunto.
2. Criar um banco **novo** e um diretório privado novo, confirmar seus nomes com outro operador e importar o SQL descompactado usando arquivo de opções do cliente, nunca credencial na linha de comando.
3. Inspecionar a lista do arquivo tar de fonte confiável antes de extrair para o diretório novo. Restaurar documentos fora de `public`, preservando nomes e permissões.
4. Configurar a versão de código correspondente e a chave do cofre; verificar schema, contagem de registros, valores decimais, referências a documentos e hashes. Executar consulta, login e download autorizado; verificar negações de acesso cruzado.
5. Cron/e-mail permanecem pausados. Reconciliar notificações `sending` e transações posteriores ao backup para não repetir envios nem perder pagamentos. Só trocar o destino ativo após aprovação registrada.

Não fornecemos comando automático destrutivo sobre produção. A restauração deve preservar o original até aceite. `mysql-restore-drill.sh` exercita apenas schema/dados sintéticos de `sigam_test`, mais um arquivo privado sintético, e remove exclusivamente seus próprios objetos temporários após sucesso. Esse ensaio não substitui recuperação de backup real no provedor nem mede RTO com volume real.
