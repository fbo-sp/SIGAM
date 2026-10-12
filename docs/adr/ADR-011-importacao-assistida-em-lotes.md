# ADR-011 — Importação assistida em lotes retomáveis

## Status

Aceita em 2026-10-08.

## Contexto

Planilhas reduzem o custo de implantação, mas podem duplicar pessoas, alterar chamadas encerradas, misturar dojos e expor dados. A aplicação precisa continuar compatível com hospedagem compartilhada, sem worker ou Redis obrigatório.

## Decisão

Cada arquivo privado origina um lote durável identificado por hash e escopo. A prévia normaliza e resolve referências sem gravar cadastros finais. A confirmação explícita processa até 100 linhas por requisição, usando os serviços de domínio existentes e uma transação por linha. Estados persistidos permitem retomada e impedem replay. Alunos e presenças têm contratos e fluxos separados.

XLSX é lido diretamente com `ZipArchive`/XML, rejeita fórmulas e limita volume; CSV de resultado neutraliza fórmulas. Arquivos são removidos após 30 dias, mantendo metadados, resultado e auditoria. Nenhum conteúdo integral da planilha ou CPF é copiado para o log de auditoria.

## Consequências

O usuário pode corrigir somente linhas problemáticas e reenviar sem duplicar o lote original. O processamento não depende de processo residente, mas lotes grandes exigem cliques de continuação. Nomes de modalidade, graduação e turma precisam coincidir com o cadastro; essa rigidez evita correspondência ambígua. A remoção do arquivo é irreversível após a retenção, mas o histórico técnico permanece.
