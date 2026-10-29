# Importação assistida

## Objetivo e limites

O módulo importa alunos ou presenças por CSV/XLSX sem criar dependências de infraestrutura. Os fluxos são independentes: presença nunca cria pessoa, aluno, matrícula ou turma, e cria somente a aula passada prevista pelo horário da turma; aluno nunca registra presença. Os arquivos ficam no disco privado `local`, não têm rota de download e são removidos após 30 dias pelo scheduler. Payloads de planilha também são expurgados; número da linha, estado, mensagem, referências de resultado e auditoria permanecem.

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

`duplicate` é aluno da associação já cadastrado (ver "Alunos já cadastrados"); `ignored` é a linha que o gestor mandou ignorar. `review` exige confirmação da tela: CPF já conhecido de pessoa que ainda não é aluna reutiliza a pessoa autorizada e presença existente com estado diferente é atualizada somente se a chamada continuar aberta. `invalid` nunca é processada. `already_processed` representa o mesmo registro já existente.

## Escopo e autorização

É necessária `import.manage` junto de `student.manage` ou `attendance.manage`. Papéis associativos usam `User::hasPermission`; gestor local usa `OperationalScope::canDojo`. O dojo é revalidado no serviço. Modalidade, graduação, turma, aula, matrícula, vínculo regular/visita e CPF são resolvidos novamente no backend, sempre na associação e no dojo permitidos. Professores não recebem `import.manage` no papel padrão.

## Contratos dos modelos

Alunos: `nome_completo`, `cpf`, `data_nascimento`, `email`, `celular`, `data_entrada`, `modalidade`, `graduacao`, `turma`, `ativar`, dados do responsável, `observacoes` e, opcionais, `rg`, `telefone`, `cep`, `endereco`, `numero`, `complemento`, `bairro`, `cidade` e `uf` (decisão de Fabio em 29/10/2026). Telefone com DDD (10 ou 11 dígitos), CEP com 8 dígitos e UF com 2 letras; valor fora disso recusa a linha. Esses dados preenchem pessoa nova; para aluno já cadastrado valem as escolhas de "Alunos já cadastrados". Nome, entrada e modalidade são obrigatórios; turma/graduação/responsável são opcionais conforme regra de ativação. Sem data de nascimento (decisão de Fabio em 29/10/2026) o aluno é importado normalmente, ativo e matriculado, e aparece em Cadastros incompletos. Aluno em pré-cadastro (`ativar=não`) não é matriculado na turma da planilha: a matrícula fica para depois da ativação, e a prévia avisa.

Presenças: `matricula_aluno`, `turma`, `data_aula`, `horario_inicio`, `situacao`, `observacoes`. Situações aceitas: presente, falta, falta justificada, reposição e experimental. A combinação turma/data/início precisa localizar exatamente uma aula aberta. Se a aula ainda não existe, a data é até hoje, está dentro do período da turma e há horário da turma nesse dia da semana e início, a linha fica em revisão e a aula é criada ao processar (`SessionGenerator::generateForGroup`). Datas futuras são recusadas.

Os modelos oficiais ficam em `resources/imports`. O XLSX contém instruções e listas de escolha. O relatório CSV usa `CsvWriter`, neutralizando células que poderiam virar fórmula.

## Alunos já cadastrados

Decisões de Fabio em 29/10/2026. Na prévia, `StudentDuplicateFinder` procura cada linha válida entre os alunos da associação (qualquer situação), parando no primeiro encontrado: CPF igual, depois RG igual (só letras e números) e depois nome completo igual sem acentos e maiúsculas, conferindo a data de nascimento quando os dois têm. CPF ou RG preenchidos dos dois lados e diferentes descartam o candidato. A linha fica `duplicate`, com o aluno, a forma de encontro, as diferenças campo a campo e a turma a matricular guardados em `normalized_payload.duplicate`.

A tela lista essas linhas acima do botão de importar, com a escolha por linha (começa em Ignorar) e os botões "Ignorar todas" e "Atualizar todas" (`data-check-all` em `common.js`). A escolha é gravada na linha ao enviar (`acoes[id]`) e executada no processamento:

- **Ignorar**: a linha fica `ignored` e nada muda no aluno.
- **Atualizar com a planilha**: substitui nome, CPF, RG, nascimento, e-mail, celular, telefone e endereço pelos valores preenchidos da planilha; célula vazia mantém o que existe, e RG só com pontuação diferente ou nome só com acentos ou maiúsculas diferentes não contam como mudança. Grava por `PersonService::update` (auditoria `person.updated`). Se o aluno ativo não estiver na turma da planilha, matricula nela sem encerrar outras matrículas, a partir da data mais recente entre entrada, início da turma, vínculo com o dojo e modalidade; se não for possível (aluno inativo, outro dojo, outra modalidade), os dados continuam atualizados e o motivo fica na mensagem da linha. CPF que já pertence a outra pessoa recusa a linha.
- **Cadastrar como novo aluno**: só quando foi encontrado pelo nome (homônimo); segue o cadastro normal.

Linha processada ou ignorada não volta a ser executada (trava da linha e situação final).

## Operação e manutenção

Cada clique processa no máximo 100 linhas. Em interrupção, o usuário abre o lote e continua durante a retenção; linhas concluídas não voltam à fila. Falhas por linha são isoladas e não desfazem as anteriores. O cron diário executa `sigam:imports:purge-files --days=30`; a opção aceita 7–365 dias, remove o arquivo, limpa payloads e expira lotes pendentes. Investigue `failed`, corrija a origem e envie novo arquivo — não edite payload JSON diretamente.

Ao alterar colunas, mantenha compatibilidade ou crie nova versão de template, atualize leitor, serviço, modelos, testes e este documento. Nunca flexibilize correspondência de nome/ID como substituto de escopo.
