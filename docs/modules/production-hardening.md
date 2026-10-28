# Sprint 14 — Hardening e preparação de produção

## Estado

Incremento técnico disponível para homologação. **NO-GO para produção** até a conclusão do checklist em `../RELEASE_CHECKLIST.md`. Não houve publicação em hospedagem externa nem envio real de e-mails.

## Componentes e manutenção

| Componente | Responsabilidade e invariantes |
|---|---|
| `AddSecurityHeaders` | CSP (quadros só de `www.youtube-nocookie.com` e `player.vimeo.com`, para os vídeos da área de estudo), proteção de frames, respostas autenticadas sem cache; HSTS somente em produção por HTTPS |
| `CsvWriter` | Neutraliza células iniciadas por fórmulas, incluindo espaços/controles; usado em relatórios financeiros, federativos e campeonatos |
| `ProductionReadiness` / `CheckProduction` | Preflight sem imprimir valores de configuração; retorna falha se qualquer requisito técnico não passa |
| `EmailDeliveryService` | Reserva atômica por entrega, revalida conta/preferências/endereço, retentativas e supressão; não envia conteúdo pessoal |
| `NotificationContentService` | Revalida acesso atual a detalhes pessoais na caixa; aviso antigo sem identidade do aluno fica redigido, sem apagar histórico |
| `DatabaseSeeder` | Conta administrativa existente não tem senha, status ou papel alterados; textos personalizados são preservados |
| `public/js/common.js` | Foco no erro e impressão por script externo compatível com CSP |
| `scripts/backup.sh` | Cópia de banco e arquivos privados com manutenção, verificação SHA-256 e destino externo ao projeto |
| `scripts/mysql-restore-drill.sh` | Ensaio exclusivamente sintético sobre `sigam_test`, sem ler o banco `sigam` |

A Sprint 14 não adicionou migration. O schema atual possui incrementos posteriores até `000023`. Eventos novos usam o JSON `context` existente para guardar identidade de aluno ou permissão de aprovação; eventos pessoais antigos sem esse contexto não exibem detalhes. Avisos gerais de campeonato/cancelamento de aula mantêm o histórico entregue. Novas APIs de notificações devem aplicar a mesma revalidação, nunca serializar diretamente o corpo armazenado.

## E-mail: garantia real

Estados: `pending → sending → sent`, `sending → failed` ou `sending → suppressed`. A reserva usa UPDATE condicional e impede que dois processos enviem simultaneamente a mesma linha. `sent` não é selecionado novamente. Falha SMTP é resumida sem credenciais; o lote falha operacionalmente e registra contagem de erros. Há até cinco tentativas com espera crescente.

Uma falha de processo/banco depois da aceitação SMTP deixa `sending`. Não há recuperação automática dessa condição: investigar no provedor antes de reenviar. SMTP não fornece garantia absoluta de entrega exatamente uma vez; uma falha ambígua de transporte também pode resultar em duplicação na retentativa. Não prometer garantia superior.

Fora dos testes unitários, o comando recusa canais `log`/`array` para não marcar como enviado algo que não saiu. O endereço vem da conta autenticável, não do contato editável da pessoa. O assunto e o corpo enviados são genéricos; templates personalizados continuam servindo à caixa interna, não ao conteúdo SMTP.

## Segurança e limites

Foram reforçados o isolamento por associação da caixa, o vínculo ativo do dojo nos indicadores, a presença de pessoa no portal e a coerência pessoa/aluno/filiação nos documentos. Não se trata de certificação nem pentest completo. MFA, matriz de acesso por campo/papel, retenção e tratamento de dados pessoais exigem decisão e implementação/validação antes de liberar uso real. Permissão de dashboard de dojo/professor não restringe automaticamente permissões administrativas de outros módulos.

## Verificação

`ProductionHardeningTest` cobre CSV, headers, preservação de contas, disputa de reserva de e-mail, revogação de preferências, mensagens sem segredos, escopo da caixa e consulta com mil alunos sintéticos. Demais suítes verificam transações, idempotência financeira, documentos e portal. Evidências e limites: `../RELEASE_VALIDATION.md`.
