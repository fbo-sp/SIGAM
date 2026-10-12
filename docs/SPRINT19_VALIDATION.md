# Sprint 19 — Evidências de validação

> Evidência histórica daquele incremento. O estado atual está em `IMPLEMENTATION_STATUS.md` e a validação consolidada em `SPRINT21_VALIDATION.md`.

Data: 2026-10-08.

## Entrega

- modelos CSV separados e XLSX com instruções, exemplos fictícios e validações;
- upload privado, prévia com estados simples, confirmação de avisos e relatório protegido;
- processamento retomável de 100 linhas, hash de replay e serviços de domínio transacionais;
- escopo por associação/dojo e permissões distintas para alunos/presenças;
- bloqueio de chamada fechada/cancelada, matrícula ausente e pessoa fora do escopo;
- retenção automática de 30 dias para o arquivo, preservando histórico e auditoria.

## Automação executada

- `AssistedImportTest`: 7 testes e 35 asserções aprovados em MySQL e SQLite, cobrindo leitura do XLSX oficial, aluno, reutilização confirmada de CPF, presença/replay, chamada fechada, operador tentando outro dojo e expurgo de arquivo/payload;
- regressão MySQL 8.4 no serviço isolado `sigam_test`: 122 testes e 657 asserções aprovados;
- Laravel Pint: 244 arquivos aprovados sem desvio.

## Homologação manual recomendada

No celular e no computador, baixar cada modelo, apagar o exemplo, importar uma linha válida e outra inválida, compreender a mensagem sem ajuda e baixar o relatório. Repetir o mesmo arquivo, interromper um lote com mais de 100 linhas e continuar. Confirmar que arquivo não possui URL pública e que professor padrão não vê a função.
