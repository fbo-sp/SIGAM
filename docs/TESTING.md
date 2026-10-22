# Testes

Testes locais usam SQLite em memória para feedback rápido. A suíte de integração de homologação também executa contra MySQL/MariaDB para cobrir diferenças de constraints, locking e tipos.

```powershell
.\scripts\php.ps1 artisan test
.\scripts\php.ps1 vendor\bin\pint --test
```

Cada regra crítica deve ter teste unitário e/ou feature. Autorização exige casos negativos. Pagamentos possuem testes de baixa parcial/integral, replay idempotente, conflito de chave, bloqueio de excedente, estorno, reconciliação, escopo e exportação. Federação testa snapshot de graduação, integração somente leitura, renovação sem sobrescrita, duplicidade anual, graduação inexistente no histórico, relatório/CSV, documento privado e associação incorreta. Campeonatos testam janela, papel, duplicidade, snapshot de taxa/idade, aprovação, cobrança única, pagamento obrigatório, resultado futuro, modalidade/graduação, histórico, CSV e isolamento. A suíte MySQL é obrigatória para validar locks, constraints e tipos no banco equivalente à produção.

O portal testa enumeração de outro aluno, dependente com vínculo encerrado, responsável não legal, documento de outro aluno e payload tentando substituir pessoa/aluno em campeonato. Esses casos devem permanecer mesmo quando a interface mudar.

Notificações testam geração repetida, uma entrega por canal, preferências, isolamento da caixa, envio em lote e falha SMTP com retentativa. Dashboards testam separadamente associação, dojo e professor, sempre com dados fora do escopo para detectar regressões.

`TeacherInvitationTest` cobre conta pendente sem senha padrão, GET sem mutação, conta nova e existente, preservação de hash/papéis, senha incorreta, uso único, expiração, revogação, recusa, reenvio, mudança de e-mail, usuário sem permissão, falha SMTP, recuperação de reserva interrompida e recuperação de senha não enumerável. Mantenha esses casos em SQLite e MySQL.

`Sprint18Test` cobre múltiplos dias/horários, duplicação, intervalo inválido, conflito de professor, versionamento e reconciliação sem apagar presença. Para visitantes, cobre replay, associação externa, aluno regular, ausência de permissão, professor local designado, responsável de menor, capacidade, presença com proveniência e ausência de lançamento financeiro.

`AssistedImportTest` cobre o XLSX oficial, criação e replay de aluno, confirmação para CPF existente, presença em aula aberta sem duplicação e associação externa. Inclua ainda arquivos malformados/fórmulas e chamada fechada ao ampliar o leitor ou estados. O teste usa `Storage::fake('local')`; a suíte MySQL valida as tabelas JSON, índices e transações.

`TechniqueCatalogTest` cobre a validade do modelo do judô frente ao template de graduações, aplicação idempotente que preserva ajustes do currículo, faixas ausentes, herança do currículo (novo, alterado, herdado, retirado e restaurado), item inativo, regras da árvore, isolamento entre associações e permissões.

`TechniqueAssessmentTest` cobre gravação só do que mudou com histórico preservado, o que falta e radar, idade e tempo mínimo, quem avalia (principal, auxiliar, professor de fora, auxiliar encerrado, outra modalidade), data futura, item inativo, consulta do gestor do dojo e da associação, isolamento e aluno com duas modalidades.

`Sprint20Test` cobre meta 4/8, vários dias, geração na criação, reexecução, redução sem exclusão, cancelamento sem ressurreição, aula atrasada, término curto, permissão/escopo e execução operacional. MySQL deve validar duas reposições concorrentes sobre a mesma turma; o lock e a constraint precisam resultar no máximo na meta configurada.

`Sprint21Test` cobre origem obrigatória de plano, coexistência do mesmo aluno em dojos distintos, listagem financeira local, baixa idempotente, negações fora do dojo, separação das etapas de gratuidade, participação local em campeonato, inscrição derivada pelo backend e bloqueio das ações exclusivamente associativas.

Para executar a mesma suíte contra MySQL isolado no Docker:

```powershell
.\scripts\docker-test.ps1
```

O serviço usa exclusivamente o banco `sigam_test`; `RefreshDatabase` recria as estruturas sem tocar no banco local `sigam`.

## Evidência do estado documentado

Na consolidação pós-Sprint 21, a suíte registrou 133 testes: SQLite com 710 assertions e uma omissão condicionada ao ambiente; MySQL com 715 assertions; Pint sem divergências. Esses números são referência histórica, não substituem nova execução após qualquer alteração. A evidência detalhada fica em `SPRINT21_VALIDATION.md`.

Em 12/10/2026, as correções de cobrança parcial vencida e de segregação na gratuidade elevaram a suíte para 136 testes (SQLite: 722 assertions). Desde então, o CI do GitHub Actions executa Pint, SQLite, MySQL 8.4, MySQL 5.7 (versão da hospedagem HostGator prevista) e `composer audit` em cada push e pull request.

Com a tela de usuários, a suíte chegou a 144 testes (SQLite: 816 assertions). `UserAccountManagementTest` cobre: convite sem senha conhecida, ativação e expiração do link, reenvio que invalida o link anterior, permissão e escopo, bloqueio de escalonamento, último gestor, conta do administrador do sistema, inativação que derruba sessões e liberação do portal pela ficha do aluno.

Com a correção do convite de professor pelo administrador do sistema, a suíte chegou a 147 testes (SQLite: 826 assertions). Com as mensagens de validação em português, 148 testes (SQLite: 832 assertions). Com a turma dentro do dojo, 160 testes (SQLite: 903 assertions); `DojoClassHubTest` cobre criação e edição pelo gestor do dojo, troca de professor nas aulas futuras, término e inativação, encerramento de matrícula e roster, auxiliar com e sem vínculo, turma de outro dojo, cadastro com turma e ficha do aluno. Com o atalho de edição de pessoa, 164 testes (SQLite: 946 assertions); `PersonEditShortcutTest` cobre o ícone por permissão, o retorno à lista sem redirecionamento externo e a edição restrita pelo gestor do dojo. Com a correção do painel do professor, 165 testes (SQLite: 953 assertions). Com a promoção de auxiliar a principal, 166 testes (SQLite: 963 assertions). Com o acesso automático do professor vinculado ao dojo, 170 testes (SQLite: 988 assertions); `TeacherDojoAccessTest` cobre concessão no vínculo, revogação no encerramento, concessão manual sem duplicidade, acesso no aceite do convite e o comando de sincronização. Com a chamada só no dia da aula e a reabertura pelo dojo, 171 testes (SQLite: 1014 assertions). Com as aulas passadas na turma e na importação, 173 testes (SQLite: 1037 assertions).

## Hardening e recuperação

`ProductionHardeningTest` cobre regressões de segurança, preservação de bootstrap, concorrência intercalada de entrega, revogação de acesso a notificações e preflight negativo. Inclui ensaio sequencial de dashboard com mil alunos; resultados variam por máquina. Não confundir com teste de carga HTTP concorrente.

Após a suíte MySQL terminar, o ensaio sintético pode ser executado separadamente:

```powershell
docker compose cp scripts/mysql-restore-drill.sh db:/tmp/sigam-sprint14-restore.sh
docker compose exec -T db sh /tmp/sigam-sprint14-restore.sh
```

Não executar simultaneamente com PHPUnit: ambos usam `sigam_test`. O script falha se encontrar sua tabela probe preexistente; em interrupção, investigar e limpar apenas os objetos temporários identificados antes de repetir. Nunca apontar esse ensaio para uma base real. O banco `sigam` não é utilizado. Evidências, comparação canônica e limitações estão em `RELEASE_VALIDATION.md`.
