# Changelog

## 2026-10-12 — Usuários e perfis da associação

- perfis fixos Gestor da Associação, Financeiro da Associação e Portal do aluno/responsável (`AssociationRoleCatalog`, `AssociationRolesSeeder`);
- tela **Usuários** em `/associacoes/{association}/usuarios` (permissão `user.manage`) com lista, busca, situação, ficha, troca de perfis, inativação, reativação e reenvio de convite;
- conta criada a partir de uma pessoa do cadastro central e ativada por convite de 72 horas em `/ativar-conta/{token}`, sem senha padrão;
- botão **Liberar portal** na ficha do aluno para o aluno e cada responsável;
- regras no serviço: sem escalonamento de perfil, sempre um gestor ativo, conta do administrador do sistema protegida, sem autoinativação, sessões encerradas ao inativar e auditoria `user_account.*`;
- documentação de RBAC, identidade, rotas, guia do usuário, estado e testes atualizada.

## 2026-10-12 — Correções financeiras

- cobrança parcialmente paga com vencimento passado passa a contar como vencida no resumo da central de cobranças, na inadimplência dos dashboards e na notificação `charge.overdue`; o resumo "em aberto" passa a incluir parciais ainda no prazo;
- escopos `Charge::pastDue()` e `Charge::notYetDue()` centralizam a regra; o status `partially_paid` não muda;
- o portal conta cobranças parciais entre as cobranças em aberto;
- quem solicitou uma gratuidade não pode mais aprová-la na etapa do dojo nem na da associação;
- testes de regressão em `PaymentsAndReportsTest` e `FinancialPlansAndBenefitsTest`.

## 2026-10-12 — Controle de versão, CI e dependências

- projeto versionado no GitHub (`fbo-sp/SIGAM`) com workflow de CI: Pint, testes em SQLite, testes em MySQL 8.4 e `composer audit`;
- CI executa em PHP 8.4, versão mínima exigida pelo `composer.lock` atual (pacotes Symfony 8.1 requerem PHP >= 8.4.1);
- `laravel/framework` 12.68.0 → 12.69.3 e `league/commonmark` 2.10.0 → 2.10.3, com dependências, para corrigir alertas de segurança apontados pelo `composer audit`.

## 2026-10-11 — Backlog de experiência móvel

- backlog canônico pós-roadmap criado em `IMPROVEMENT_BACKLOG.md`;
- melhorias móveis priorizadas para professor, aluno e responsável, com IDs UXM-01 a UXM-12;
- navegação por perfil, chamada, portal, feedback, linguagem, notificações, formulários, acessibilidade e desempenho detalhados com critérios de aceite;
- PWA separada de chamada offline, preservando privacidade e exigindo discovery de sincronização antes de armazenar dados pessoais no dispositivo;
- índice, estado implementado, guia de manutenção, README e instruções de agentes atualizados para distinguir proposta de entrega.

## 2026-10-11 — Consolidação documental pós-Sprint 21

- estado implementado centralizado em `IMPLEMENTATION_STATUS.md`, separando conclusão técnica de liberação para produção;
- guia de manutenção e mapa de rotas/comandos adicionados para desenvolvedores e agentes de IA;
- arquitetura, banco, RBAC, segurança, testes, deploy, jobs, runbook e módulos reconciliados com o código atual;
- planos e evidências antigas marcados como históricos para não serem confundidos com requisitos vigentes;
- pendências reais de produto/operação preservadas no checklist de go/no-go e no registro de decisões.

## 2026-10-11 — Sprint 21

- operação final do dojo com financeiro local e campeonatos compartilhados da associação;
- origem explícita em `student_plans.dojo_id`, sem inferência pelo dojo principal;
- cobranças recorrentes e gratuidades vinculadas à mesma origem local, preservando histórico;
- planos simultâneos permitidos em dojos distintos com vínculo regular comprovado;
- inscrições locais derivando aluno, pessoa, dojo e graduação no backend e ocultando outras unidades;
- participação dojo–campeonato idempotente, sem copiar evento, regras ou taxa;
- diagnóstico/backfill conservador `sigam:operation:audit-origins`, seco por padrão e sem dados pessoais;
- migration incremental, ADR, guia, documentação técnica e testes transversais SQLite/MySQL.

## 2026-10-11 — Sprint 20

- meta de oito próximas aulas por turma, configurável para quatro;
- geração inicial transacional e reposição somente do déficit entre todos os dias semanais;
- respeito a timezone, vigências, término, associação/dojo/turma ativos e limite de busca;
- reposição em criação/troca de agenda, fechamento, cancelamento e reagendamento;
- cancelamentos, reagendamentos, histórico e excesso preservados sem ressurreição de ocorrência;
- botão manual e comando horário compartilhando o mesmo serviço idempotente;
- lock por turma, constraint de ocorrência, auditoria e resultado operacional;
- migration incremental, ADR, documentação funcional/técnica e testes SQLite/MySQL.

## 2026-10-08 — Sprint 19

- importação assistida e separada de alunos e presenças por CSV/XLSX;
- modelos oficiais com instruções simples, exemplos fictícios e listas de valores;
- arquivo privado, prévia pronta/revisão/inválida/já processada, confirmação e relatório neutralizado contra fórmulas;
- lotes de 100 linhas retomáveis, hash contra replay e reutilização dos serviços transacionais existentes;
- CPF existente sob confirmação, matrícula/aula exatas e bloqueio de chamada fechada ou recurso fora do escopo;
- permissão `import.manage`, suporte a gestor associativo/local, auditoria sem dados pessoais e retenção do arquivo por 30 dias;
- migration incremental, ADR, documentação técnica/usuário e testes MySQL.

## 2026-10-08 — Planejamento da Sprint 19

- importação assistida adicionada ao roadmap em fluxos separados para alunos e presenças;
- sequência revisada: importação passa à Sprint 19, aulas automáticas à Sprint 20 e consolidação à Sprint 21;
- modelos CSV/XLSX, prévia, revisão de duplicidades/ambiguidades, confirmação e relatório de erros previstos;
- isolamento por associação/dojo, arquivos privados, auditoria, idempotência e preservação de chamadas fechadas definidos como critérios de aceite;
- processamento planejado em lotes compatíveis com hospedagem compartilhada, sem worker permanente.

## 2026-10-08 — Marca visual JUDOMA

- nome apresentado na interface, títulos do navegador, login, e-mails, notificações e documentos operacionais alterado de SIGAM para JUDOMA;
- configuração padrão e ambiente Docker ajustados para a nova marca;
- identificadores técnicos legados `sigam:*`, `SIGAM_*`, bancos, arquivos e infraestrutura preservados para evitar quebra de compatibilidade.

## 2026-10-08 — Template de graduações

- planilha revisável para carga de graduações de Judô, Jiu-Jitsu Brasileiro e Karatê;
- campos alinhados ao catálogo atual, com validação de obrigatórios, nomes e ordens duplicadas;
- referências oficiais da CBJ, CBJJ/IBJJF e CBKD registradas na própria planilha;
- trilha infantil do Jiu-Jitsu separada por incompatibilidade com a sequência linear atual;
- avisos explícitos para regras variáveis, graduações superiores e confirmação pela associação.
- seleção opcional de template diretamente no cadastro de modalidade;
- catálogo operacional em JSON, separado das regras de domínio e com referências de origem;
- preenchimento progressivo de nome/descrição e resumo simples antes de salvar;
- criação transacional e auditada de modalidade e graduações, com rejeição de chave inválida e ausência de permissão.

## 2026-10-07 — Sprint 18

- criação de turma com vários dias da semana, horário comum ou horários diferentes;
- conjunto semanal versionado, professor/local por dia e bloqueio de conflito do professor;
- prévia das próximas aulas e reconciliação transacional de ocorrências futuras;
- preservação de chamadas, presenças, cancelamentos e reagendamentos históricos;
- visitante gratuito por aula, localizado por matrícula exata e restrito à mesma associação;
- permissão `visitor.manage` para gestor e professor local designado;
- validações de modalidade, idade, responsável legal, capacidade e matrícula regular;
- chamada e portal distinguem matriculado de visitante, sem criar vínculo ou cobrança;
- migration incremental, ADR, documentação e testes SQLite/MySQL.

## 2026-10-07 — Sprint 17

- convite associativo de professor integrado ao cadastro direto, sem senha padrão ou acesso antecipado;
- conta pendente, token de 72 horas com hash e cópia cifrada somente até a entrega;
- mensagem transacional própria, lote por cron, retentativa limitada, falha visível e recuperação de execução interrompida;
- GET sem mutação e POST transacional de aceite, verificação de e-mail e uso único;
- conta nova define senha; conta existente comprova e conserva senha, associação e papéis;
- reenvio invalida token anterior; recusa/revogação preservam perfil, vínculo e conta;
- papel mínimo `teacher_account` somente com dashboard e revalidação da autoridade do emissor;
- recuperação de senha genérica, limitada e com token descartável;
- páginas responsivas, headers restritivos, ADR, documentação técnica/operacional e testes SQLite/MySQL.

## 2026-10-07 — Sprint 16

- cadastro direto de aluno no dojo e de professor na associação, sem passagem obrigatória por Pessoas;
- responsável criado ou reutilizado na mesma tarefa, com pré-cadastro e ativação segura de menor;
- matrículas automáticas transacionais e reenvio idempotente por UUID;
- reutilização confirmada de CPF dentro do escopo e resposta genérica para identidade externa;
- vários vínculos regulares de dojo no mesmo aluno, com troca explícita do principal sem encerrar os demais;
- documentos privados contextualizados por aluno, professor ou vínculo de responsável;
- permissões locais `student.manage` e `student.document.manage` sem ampliar rotas associativas;
- telas responsivas, ADR, documentação do módulo e testes em SQLite/MySQL.

## 2026-10-07 — Sprint 15

- contextos separados de administração da associação e operação por dojo;
- concessões locais com papel, unidade, vigência, revogação lógica, idempotência e auditoria;
- papéis locais impedidos de abrir rotas associativas antigas, inclusive por alteração de URL;
- vínculo institucional do professor separado da equipe local e da conta de acesso;
- professor restrito ao dojo e às aulas em que atua, com autorização revalidada na chamada;
- aluno local derivado de vínculo regular vigente, sem usar dojo principal ou visita como prova de acesso;
- telas responsivas de Meus dojos, alunos, turmas, aulas, chamada, professores e gestão de acessos;
- backfill conservador em modo simulação, sem criação automática de conta, convite ou permissão;
- testes negativos de isolamento, vigência, revogação, rotas antigas, recurso externo e replay.

## Planejamento — melhorias das Sprints 17–20 (histórico)

- decisões Q1–Q3 confirmadas: múltiplos dojos com principal, professores cadastrados/convidados somente pela associação e padrão de oito próximas aulas ajustável para quatro;
- participação visitante adicionada ao plano, separada de matrícula regular, com acesso mínimo por aula; Q4/Q5 confirmadas: somente na mesma associação e sempre gratuita, sem lançamento financeiro pela visita;

- proposta integrada de cadastro direto de aluno/professor/responsável e documentos na ficha;
- requisitos de convite, verificação de e-mail e aceite do professor, sem acesso antecipado;
- ampliação futura da operação por dojo para cadastros compostos, documentos, campeonatos e financeiro;
- agenda semanal com vários dias e reposição limitada das próximas aulas;
- sequência proposta de Sprints 15–20, migração preservando histórico e decisões abertas documentadas em `PLANO_MELHORIAS_CADASTRO_E_OPERACAO.md`.

## 2026-08-30 — Sprint 14 (homologação; produção ainda não liberada)

- preflight `sigam:production:check` sem exposição de segredos;
- reserva atômica de e-mails, revalidação de conta/preferências e conteúdo SMTP minimizado;
- caixa restrita à associação atual e revalidação de detalhes pessoais em avisos antigos;
- CSV protegido contra fórmulas, cache privado bloqueado e impressão compatível com CSP;
- seeder preserva senha, status, papéis e templates existentes;
- reforço de coerência no portal, documentos e vínculo de dojo;
- testes de regressão/hardening e ensaio de desempenho com dados sintéticos;
- procedimentos de deploy, rollback, backup, restauração, operação, treinamento e go/no-go explícito.

## 2026-08-30 — Sprint 13

- eventos, templates, preferências e entregas persistentes desacoplados de canal;
- caixa interna com leitura individual/em lote e contador no menu;
- e-mail em lotes por cron, com idempotência, até cinco tentativas e erro observável;
- nove detectores iniciais de cobranças, documentos, filiações, campeonatos, gratuidades, aulas e frequência;
- dashboards responsivos de associação, dojo e professor com escopo derivado no backend;
- permissões específicas de dashboard e vínculo opcional de dojo na conta;
- testes de isolamento, preferência, SMTP falho, idempotência e escopo de indicadores.

## 2026-08-30 — Sprint 12

- portal responsivo com alternância clara de dependentes;
- autorização por titularidade ou vínculo vigente, além de `portal.access`;
- consulta integrada de turmas, frequência, financeiro, documentos, histórico e campeonatos;
- edição transacional limitada a contatos pelo titular ou responsável legal;
- documentos privados por vínculo direto ao aluno e download auditado;
- inscrição com identidade e dojo derivados no backend;
- testes de enumeração, vínculo encerrado e adulteração de IDs.

## 2026-08-30 — Sprint 11

- campeonatos com modalidade, tipo, datas, janela, regulamento, taxa e papéis aceitos;
- inscrições transacionais por competidor, árbitro ou auxiliar, sem duplicidade de pessoa/papel;
- idade calculada no servidor e graduação/filiação validadas contra históricos existentes;
- aprovação com cobrança idempotente no motor financeiro compartilhado;
- bloqueio de participação paga sem quitação e de resultado antes do evento;
- resultados, medalhas, pontos, WO e desclassificação com auditoria;
- histórico esportivo consolidado, filtros, indicadores por papel/dojo e CSV.

## 2026-08-30 — Sprint 10

- catálogo de federações por associação, contatos, abrangência e inativação sem apagar histórico;
- filiações anuais imutáveis com número, validade, modalidade, graduação registrada, pontos e situação;
- validação transacional da graduação contra o histórico esportivo consolidado;
- proteção contra duplicidade por aluno, federação, modalidade e ano;
- documentos federativos no armazenamento privado existente;
- painel de vencimentos, filtros, relatório CSV, RBAC, auditoria e isolamento por associação.

## 2026-08-30 — Sprint 9

- pagamentos totais e parciais transacionais, com chave idempotente e bloqueio de excedente;
- reconciliação do saldo pela soma dos lançamentos válidos;
- estorno auditável com ator, instante e motivo, sem exclusão física;
- detalhe da cobrança e extrato do aluno com histórico completo;
- relatórios de previsto, recebido, pendente, atrasos, descontos e gratuidades por dojo/modalidade;
- exportação CSV UTF-8, RBAC específico, isolamento por associação e testes SQLite/MySQL.

## 2026-08-30 — Sprint 8

- cobrança central com origem, competência, saldos e snapshots completos do cálculo/vencimento;
- geração recorrente mensal, semestral e anual, idempotente e protegida por lock/constraint;
- gratuidade materializada como isenção e desconto congelado no histórico;
- atualização segura de vencidas e registro operacional dos jobs;
- central responsiva de abertas/vencidas, filtros, extrato do aluno e comandos agendáveis.

## 2026-08-30 — Sprint 7

- planos e vínculos de aluno com snapshot monetário e prevenção transacional de sobreposição;
- regras configuráveis para dia inexistente, fim de semana e feriados;
- descontos com vigência e separação entre solicitação/aprovação;
- gratuidade com dupla aprovação sequencial e atores distintos;
- simulador explicável, BCMath, formatação sem `float`, RBAC, auditoria e testes.

## 2026-08-30 — Sprint 6

- tipos configuráveis, metadados, validade e central responsiva de documentos;
- upload privado com UUID, validação de assinatura/MIME/extensão/tamanho e coerência pessoa/aluno;
- download por streaming autorizado, exclusão lógica, auditoria e testes de isolamento.

## Não lançado

### Adicionado

- baseline Laravel 12 e toolchain PHP/Composer portátil para desenvolvimento;
- migrations de associação, pessoa central, usuários, RBAC, auditoria e jobs;
- autenticação sem cadastro público, associação atual e bloqueio de contas inativas;
- auditoria de login/logout e headers básicos de segurança;
- layout responsivo inicial em português brasileiro;
- documentação viva, guia de agentes e testes automatizados da fundação.
- ambiente Docker opcional com PHP/Apache, MySQL persistente, healthchecks e suíte MySQL isolada.
- gestão responsiva de associações, dojos e pessoas com busca, paginação e exportação CSV;
- validação e normalização de CPF, CNPJ, contatos e códigos de dojo;
- autorização negativa por permissão/associação, bloqueio de novos vínculos em associação inativa e auditoria minimizada;
- documentação técnica e funcional do módulo Organização e Pessoas.
- catálogo configurável de modalidades e graduações, sem faixas fixas no código;
- pré-cadastro guiado de aluno, múltiplos responsáveis e ativação segura de menores;
- históricos de dojo, modalidade e graduação e habilitações de professores;
- permissões, auditoria e documentação do Cadastro Esportivo.
- turmas com capacidade, faixa etária, professor habilitado e agenda versionada;
- matrículas transacionais com validação de dojo, modalidade, idade, vigência, duplicidade e vaga;
- interface e documentação do módulo de Turmas, Horários e Matrículas.
- geração idempotente de aulas por comando e agenda versionada;
- chamada mobile com registro em lote, fechamento e reabertura auditada;
- cancelamento e reagendamento de aula sem sobrescrever histórico.
