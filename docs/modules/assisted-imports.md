# Importação assistida

## Objetivo e limites

O módulo importa alunos ou presenças por CSV/XLSX sem criar dependências de infraestrutura. Os fluxos são independentes: presença nunca cria pessoa, aluno, matrícula, turma ou aula; aluno nunca registra presença. Os arquivos ficam no disco privado `local`, não têm rota de download e são removidos após 30 dias pelo scheduler. Payloads de planilha também são expurgados; número da linha, estado, mensagem, referências de resultado e auditoria permanecem.

## Componentes

- `AssistedImportController`: navegação, envio, confirmação, modelos e CSV de avisos/erros.
- `ImportUploadRequest` e `ImportProcessRequest`: formato, tamanho e confirmação explícita.
- `SpreadsheetReader`: leitura limitada a 1.000 linhas, CSV UTF-8 com `;`/`,` e XLSX sem fórmulas; seleciona as abas `Alunos` ou `Presenças`.
- `AssistedImportService`: autorização, normalização, resolução no escopo, classificação, lote de até 100 linhas e retomada.
- `DirectRegistrationService`, `ClassGroupService` e `AttendanceService`: continuam sendo os únicos caminhos para regras críticas e transações dos cadastros finais.
- `PurgeImportFiles`: retenção idempotente dos arquivos privados.
- `import_batches` e `import_rows`: estado durável e rastreabilidade, sem copiar conteúdo pessoal para a auditoria.

## Estados

O lote começa em `reviewing`, passa a `processing` e termina em `completed`. Lote não concluído antes da retenção passa a `expired`. Reenvio do mesmo hash, tipo, associação e dojo abre o lote existente. Uma linha começa em `ready`, `review`, `invalid` ou `already_processed`; durante a confirmação vai para `processed` ou `failed`. `initial_status` preserva avisos mesmo após processamento.

`review` exige confirmação da tela: CPF já conhecido reutiliza a pessoa autorizada e presença existente com estado diferente é atualizada somente se a chamada continuar aberta. `invalid` nunca é processada. `already_processed` representa o mesmo registro já existente.

## Escopo e autorização

É necessária `import.manage` junto de `student.manage` ou `attendance.manage`. Papéis associativos usam `User::hasPermission`; gestor local usa `OperationalScope::canDojo`. O dojo é revalidado no serviço. Modalidade, graduação, turma, aula, matrícula, vínculo regular/visita e CPF são resolvidos novamente no backend, sempre na associação e no dojo permitidos. Professores não recebem `import.manage` no papel padrão.

## Contratos dos modelos

Alunos: `nome_completo`, `cpf`, `data_nascimento`, `email`, `celular`, `data_entrada`, `modalidade`, `graduacao`, `turma`, `ativar`, dados do responsável e `observacoes`. Nome, nascimento, entrada e modalidade são obrigatórios; turma/graduação/responsável são opcionais conforme regra de ativação.

Presenças: `matricula_aluno`, `turma`, `data_aula`, `horario_inicio`, `situacao`, `observacoes`. Situações aceitas: presente, falta, falta justificada, reposição e experimental. A combinação turma/data/início precisa localizar exatamente uma aula aberta.

Os modelos oficiais ficam em `resources/imports`. O XLSX contém instruções e listas de escolha. O relatório CSV usa `CsvWriter`, neutralizando células que poderiam virar fórmula.

## Operação e manutenção

Cada clique processa no máximo 100 linhas. Em interrupção, o usuário abre o lote e continua durante a retenção; linhas concluídas não voltam à fila. Falhas por linha são isoladas e não desfazem as anteriores. O cron diário executa `sigam:imports:purge-files --days=30`; a opção aceita 7–365 dias, remove o arquivo, limpa payloads e expira lotes pendentes. Investigue `failed`, corrija a origem e envie novo arquivo — não edite payload JSON diretamente.

Ao alterar colunas, mantenha compatibilidade ou crie nova versão de template, atualize leitor, serviço, modelos, testes e este documento. Nunca flexibilize correspondência de nome/ID como substituto de escopo.
