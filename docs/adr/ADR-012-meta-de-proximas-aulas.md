# ADR-012 — Meta de próximas aulas por turma

**Estado:** aceito e implementado na Sprint 20.

## Contexto

Gerar aulas por um intervalo fixo criava volumes diferentes conforme a frequência semanal e dependia de comando técnico. A operação precisa encontrar uma agenda curta pronta para chamada, sem perder cancelamentos, reagendamentos ou presenças.

## Decisão

- cada turma mantém `future_session_target` igual a 4 ou 8, com padrão 8;
- a meta conta todas as ocorrências futuras `planned` da turma em ordem cronológica, somando todos os dias da semana;
- `FutureSessionMaintainer` executa sob transação e `lockForUpdate` da turma, calcula somente o déficit e usa a chave única da ocorrência para idempotência;
- uma ocorrência é futura pela data e hora no timezone da aplicação; chamada antiga aberta não participa da contagem;
- a busca começa no maior valor entre hoje e o início da turma, termina no fim da turma ou após 366 dias e nunca inventa datas;
- somente associação, dojo e turma ativos recebem aulas;
- qualquer linha existente na mesma data/horário, inclusive cancelada ou reagendada, impede recriar a ocorrência original;
- reduzir a meta não exclui aulas já criadas;
- criação/troca de agenda e fechamento/cancelamento/reagendamento repõem a meta pelo serviço de domínio;
- o botão manual e o comando horário `sigam:sessions:generate` chamam o mesmo serviço. As opções legadas por intervalo são aceitas, mas ignoradas;
- `SessionGenerator` permanece interno para reconciliação histórica da agenda versionada e não é exposto como operação de lote futuro.

## Consequências

A agenda fica previsível para turmas com frequências distintas e recupera falhas pelo scheduler sem worker permanente. O lock da turma serializa duas reposições no MySQL; a chave única continua sendo a última defesa contra duplicação. Se a vigência não comportar a meta, o resultado informa a quantidade faltante sem ultrapassar o término.
