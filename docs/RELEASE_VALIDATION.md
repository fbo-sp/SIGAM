# Evidências da Sprint 14

> Evidência histórica do hardening inicial. O roadmap avançou até a Sprint 21; consulte `SPRINT21_VALIDATION.md` para a fotografia automatizada mais recente e `RELEASE_CHECKLIST.md` para o estado formal de produção.

Data: 30/08/2026. Ambiente: Windows local e Docker Desktop, PHP 8.4.25/MySQL 8.4.10 no Docker. **Não é um laudo de produção nem aceite de usuário.**

| Verificação | Resultado observado |
|---|---|
| Suíte SQLite | 78 testes, 363 assertions, sucesso; última execução 10,99 s |
| Suíte MySQL isolada | 78 testes, 364 assertions, sucesso; última execução 2 min 55,876 s |
| Pint | `vendor/bin/pint --test`: passed |
| Dependências | `composer audit --locked --format=json`: zero advisories e zero pacotes abandonados na consulta desta execução |
| Build/deploy Docker | Imagem reconstruída; app e db healthy; volumes preservados |
| Banco local | Preflight confirmou conexão, usuário não root e migrations atuais; nenhuma migration nova nesta sprint |
| Preflight de produção no Docker | Retornou 1 corretamente: ambiente local/debug/HTTP/cookie não Secure e SMTP não configurado impedem aprovação |
| Envio no ambiente local | `sigam:notifications:send --limit=1` retornou 1 com envio desabilitado; canal log não consome a fila |
| Agenda | `schedule:list` listou geração de cobranças, atraso, eventos e envio; não comprova cron do provedor |
| Browser | Login após rebuild acessível; em 390×844, largura de conteúdo 390 sem overflow horizontal; rótulos, foco visível, botão e instrução de ajuda conferidos |
| Backup wrapper | `sh -n scripts/backup.sh` aprovado; execução com credenciais/volume de produção não realizada |
| Restauração sintética | `RESTORE_OK tables=54 elapsed_seconds=13`, saída 0; schema de sigam_test + probe decimal e arquivo privado sintético recuperados |

## Desempenho medido

`ProductionHardeningTest` cria mil alunos fictícios e executa dez consultas sequenciais ao serviço de dashboard associativo. Limite verificado: até 30 consultas por leitura. Última rodada MySQL: p50 **44,9 ms**, p95 **60,9 ms**. `EXPLAIN` da busca de alunos por associação/status indicou `type=ref` e índice `students_association_id_registration_number_unique`.

Esses tempos medem o serviço PHP com banco local, não HTTP completo, usuários concorrentes, geração financeira em volume, SMTP ou uploads. Não são garantia de capacidade. Antes do go-live, preencher volume previsto, concorrência e metas, executar ensaio dos fluxos críticos no host alvo e revisar planos SQL das consultas com maior custo. Não foi criado índice especulativo sem evidência.

## Recuperação verificada

O ensaio lê exclusivamente `sigam_test`, cria banco novo temporário, importa o dump e compara schema/dados por SHA-256 após normalizar somente a explicitação redundante de charset utf8mb4 em colunas com collation utf8mb4. Linhas de dados, índices e constraints permanecem comparados. O valor decimal `123.45` também é consultado diretamente.

Um arquivo privado **sintético**, não um documento de usuário, é compactado/restaurado e comparado. SHA-256 observado: `cc793103500129818ff6916089145bd1afaf9752f6c11abfe768758c588f7688`.

A primeira tentativa detectou ausência de `cmp` na imagem mínima; a segunda expôs a representação de charset após importação. O script foi corrigido e a execução final passou. Objetos temporários das tentativas e da execução final foram removidos; nenhum banco `sigam` foi apagado/restaurado. O tempo de 13 s não mede RTO de produção e a base sintética não representa volume real.

## Limites e próximos gates

Não houve sessão autenticada disponível no navegador; fluxos autenticados foram cobertos pelos testes automatizados, não por aceite visual humano nesta rodada. Leitor de tela, navegação completa por teclado, acessibilidade dos fluxos e treinamento com usuários seguem pendentes. A skill de navegador orientou a inspeção visual local e a reposição do viewport, sem alterar configurações de usuário.

Sem hospedagem definitiva configurada não foi possível ensaiar TLS público, SMTP real, cron real, troca/rollback de releases no provedor ou restauração de conjunto real. MFA, privacidade e matriz de acesso ainda exigem fechamento. Estado formal e responsáveis: `RELEASE_CHECKLIST.md`.
