# Plano de Desenvolvimento do SIGAM

**Versão:** 1.1 — plano concluído tecnicamente  
**Data:** 30/08/2026  
**Base:** Especificação Funcional v1.0 e Especificação Técnica v1.0  
**Objetivo:** transformar as especificações do SIGAM em uma sequência de desenvolvimento verificável, documentada e adequada a usuários com baixa familiaridade tecnológica.

> Documento histórico de planejamento. As Sprints 0–21 foram implementadas tecnicamente e o nome externo passou a ser JUDOMA. Consulte `docs/IMPLEMENTATION_STATUS.md` para o estado real, `docs/MAINTENANCE_GUIDE.md` para manutenção e `docs/RELEASE_CHECKLIST.md` para os gates ainda pendentes de produção. O identificador técnico `SIGAM` foi preservado por compatibilidade.

---

## 1. Síntese da análise

O SIGAM é uma plataforma web responsiva para administrar associações de artes marciais, do cadastro institucional à operação esportiva e financeira. O núcleo do domínio é a entidade `Pessoa`, que pode acumular papéis, sem duplicação cadastral. Dados históricos, registros financeiros e auditoria são fontes permanentes de verdade e não podem ser sobrescritos ou eliminados de forma destrutiva.

A arquitetura proposta nas especificações é coerente com a infraestrutura-alvo: monólito modular, preferencialmente Laravel/PHP, MySQL/MariaDB, renderização server-side, filesystem privado, cron e SMTP. Ela deve ser mantida na primeira versão. A modularidade deverá existir dentro da aplicação, com regras de negócio em serviços de domínio, autorização centralizada, migrations incrementais e integrações por contratos internos claros.

O MVP descrito é grande. Para reduzir risco, ele será entregue em três marcos utilizáveis:

1. **Fundação administrativa:** segurança, associação, dojos, pessoas e configurações.
2. **Operação esportiva:** alunos, responsáveis, professores, turmas, aulas, chamada e documentos.
3. **Operação financeira:** planos, benefícios, cobranças, pagamentos e relatórios essenciais.

Federações, campeonatos, portal, notificações e exames de graduação entram depois da estabilização operacional do MVP.

### 1.1 Princípios inegociáveis

1. Integridade de dados e segurança prevalecem sobre conveniência.
2. Toda autorização é validada no backend e considera permissão e escopo.
3. Alterações históricas relevantes geram novos registros; não sobrescrevem fatos passados.
4. Cobranças e pagamentos usam um único motor financeiro transacional e auditável.
5. Jobs recorrentes são idempotentes e observáveis.
6. Documentos permanecem privados e só são entregues após autorização.
7. Interface simples não significa regra de negócio simplificada ou removida.
8. Cada incremento atualiza código, testes e documentação na mesma entrega.

---

## 2. Decisões e lacunas encontradas

### 2.1 Decisões arquiteturais já suficientemente definidas

- monólito modular, sem microserviços na V1;
- Laravel/PHP e MySQL/MariaDB, sujeitos à validação da hospedagem na Sprint 0;
- Blade e renderização server-side, com JavaScript progressivo;
- banco preparado para múltiplas associações desde a primeira migration;
- armazenamento privado de documentos fora da raiz pública;
- scheduler acionado por cron, sem worker permanente obrigatório;
- RBAC granular com escopo de sistema, associação, dojo, turma e próprio usuário/dependentes;
- valores monetários em decimal, transações e bloqueio de linha nas baixas;
- soft delete somente onde compatível com retenção e histórico;
- textos de interface preparados para tradução, inicialmente `pt_BR`.

### 2.2 Decisões bloqueantes antes da implementação dos módulos afetados

| ID | Decisão necessária | Prazo máximo | Recomendação inicial |
|---|---|---|---|
| D-01 | Uma instalação atenderá uma ou várias associações na interface? | Sprint 0 | Banco multiassociação; interface V1 com associação atual explícita e sem operação SaaS pública. |
| D-02 | Quais papéis podem ver CPF, documentos, financeiro e dados de menores? | Sprint 0 | Matriz de acesso por campo e por ação, com menor privilégio. |
| D-03 | Quando um aluno menor pode ser ativado e quais responsáveis são obrigatórios? | Antes de Alunos | Regra configurável por associação, validada na ativação e não no rascunho. |
| D-04 | Aluno pode ter múltiplos dojos, modalidades, planos e turmas simultâneos? | Antes de Alunos | Permitir no modelo; parametrizar limites operacionais. |
| D-05 | O que caracteriza chamada finalizada e quem pode reabri-la? | Antes de Presença | Adicionar estado, responsável e timestamps de fechamento/reabertura. |
| D-06 | Regras de vencimento em dias inexistentes, fins de semana e feriados | Antes do Financeiro | Política configurável e snapshot na cobrança gerada. |
| D-07 | Tratamento de pagamento excedente, duplicado, parcial e estornado | Antes de Pagamentos | Bloquear excedente na V1; exigir idempotency key e estorno com motivo/permissão. |
| D-08 | Fluxo canônico e estados da gratuidade | Antes de Benefícios | Unificar especificações em uma única máquina de estados documentada e testada. |
| D-09 | Retenção, anonimização e atendimento LGPD | Antes de produção | Política aprovada pelo responsável legal e implementada por classe de dado. |
| D-10 | RPO, RTO, retenção e destino externo dos backups | Antes de homologação | Backup diário de banco e arquivos; teste de restauração antes do go-live. |

### 2.3 Pontos que podem permanecer parametrizáveis sem bloquear o MVP

- modalidades, graduações, categorias, faixas etárias e tipos de documento;
- formas adicionais de pagamento e periodicidade;
- regras familiares de desconto;
- aprovação de inscrição em campeonatos;
- ranking esportivo e cálculo automático de categoria;
- gateway PIX/boleto, WhatsApp, PWA e integrações federativas;
- fluxo completo de exames de graduação;
- repasse financeiro entre associação e dojo;
- aulas experimentais e funil de interessados.

### 2.4 Ajustes necessários no modelo antes das migrations definitivas

- formalizar a cardinalidade de `Person` e `Student` no cenário multiassociação;
- definir constraints para que um documento pertença a um contexto válido, evitando `person_id` e `student_id` incoerentes;
- documentar como referências polimórficas de `charges.source_type/source_id` mantêm integridade;
- adicionar ao modelo o fechamento e eventual reabertura de chamada;
- definir versão/vigência de agendas para que alterações não afetem aulas históricas;
- definir unicidade de plano ativo, matrícula ativa e vínculo principal por período;
- manter snapshots mínimos em cobranças, pagamentos e futuros recibos;
- definir política consistente de timestamps, timezone da aplicação e armazenamento no banco;
- impedir discrepância entre `paid_amount`, `balance_amount` e a soma de pagamentos válidos;
- não registrar dados pessoais completos em JSON de auditoria quando não forem necessários.

---

## 3. Arquitetura de referência

### 3.1 Visão lógica

```text
Navegador responsivo
        |
      HTTPS
        |
Rotas + Middleware
        |
Controllers + Form Requests
        |
Casos de uso / Serviços de domínio
        |---------------- Policies e escopo
        |---------------- Auditoria
        |---------------- Notificações
        |
Eloquent + transações + constraints
        |
MySQL/MariaDB

Serviços laterais: filesystem privado, SMTP e scheduler via cron
```

### 3.2 Módulos do monólito

| Módulo | Responsabilidade | Dependências principais |
|---|---|---|
| Identity & Access | autenticação, usuários, RBAC, escopo, associação atual | Pessoa, Auditoria |
| Organization | associação, dojos e responsáveis institucionais | Pessoa |
| People | cadastro único, contatos e papéis | Organization |
| Sports Registry | alunos, responsáveis, professores, modalidades, graduações | People, Organization |
| Classes | turmas, agendas, matrículas, aulas e presença | Sports Registry |
| Documents | metadados, upload, download privado, validade | Identity, People |
| Finance | planos, descontos, gratuidades, cobranças, pagamentos | Sports Registry, Audit |
| Federation | federações e filiações históricas | Sports Registry, Documents |
| Championships | eventos, inscrições, resultados e participação | Sports Registry, Finance |
| Portal | visão do aluno e do responsável | módulos de leitura autorizada |
| Notifications | eventos, templates, caixa interna e e-mail | Scheduler |
| Reporting | consultas, exportações e dashboards por escopo | todos, somente leitura |
| Audit & Operations | auditoria, saúde, jobs, logs e diagnóstico | transversal |

Não criar interfaces de repositório indiscriminadamente. Elas devem existir quando houver mais de uma fonte de dados, consulta complexa reutilizada ou benefício concreto para testes e isolamento. Regras do domínio não devem depender de controllers nem de detalhes da interface.

### 3.3 Regras de dependência

- controllers coordenam entrada e saída; não executam transições críticas diretamente;
- Form Requests validam formato; serviços validam invariantes de negócio;
- Policies/Gates resolvem permissão e escopo no servidor;
- serviços transacionais são os únicos pontos de escrita para pagamento, gratuidade, geração de cobranças, graduação e resultado;
- relatórios consultam fontes de verdade; projeções só serão criadas após evidência de desempenho;
- eventos internos desacoplam notificações e auditoria sem exigir mensageria externa;
- jobs guardam chave idempotente, execução, contadores e falhas em `scheduled_job_runs`.

### 3.4 Segurança e privacidade desde o desenho

- matriz de autorização testável por perfil, escopo, recurso e ação;
- proteção contra IDOR em toda rota com identificador;
- CSRF, XSS, SQL injection, rate limiting, cookies seguros e renovação de sessão;
- MFA preparado e priorizado para perfis administrativos;
- download de documento por streaming após autorização, sem URL pública permanente;
- MIME real, limite de tamanho, nome aleatório e diretório não executável;
- segregação entre log da aplicação, eventos de segurança e auditoria;
- minimização e mascaramento de CPF, contatos e informações de menores;
- backup conjunto do banco e dos arquivos, com restauração ensaiada;
- checklist de segurança antes de cada release e revisão dedicada antes da produção.

---

## 4. Estratégia de documentação viva

Documentação será parte do produto. Pull requests que alterem comportamento, banco, operação ou arquitetura deverão alterar a documentação relacionada.

### 4.1 Estrutura consolidada

A lista inicialmente proposta foi consolidada nos documentos abaixo. Regras de produto, finanças, arquivos, UX e observabilidade não ficaram órfãs: foram distribuídas entre o estado atual, guias de módulo e runbooks especializados.

```text
README.md                         instalação e comandos essenciais
AGENTS.md                         contexto e regras para agentes de código
docs/
  INDEX.md                        ordem de leitura e mapa por módulo
  IMPLEMENTATION_STATUS.md        produto implementado, limites e pendências
  IMPROVEMENT_BACKLOG.md          propostas priorizadas posteriores ao roadmap
  MAINTENANCE_GUIDE.md            mapa do código e procedimento de mudança
  ARCHITECTURE.md                 módulos, dependências e fluxo de escrita
  DATABASE.md                     schema, histórico e invariantes
  RBAC.md                         papéis, permissões e dois contextos de acesso
  ROUTES_AND_COMMANDS.md           entradas HTTP, CLI e scheduler
  SECURITY.md / DECISIONS.md      controles, riscos e decisões abertas
  TESTING.md                      estratégia, comandos e evidências vigentes
  JOBS_AND_CRON.md                agenda, idempotência e recuperação
  DEPLOYMENT.md / DOCKER.md       produção compartilhada e ambiente local
  BACKUP_RESTORE.md               conjunto recuperável e ensaio seguro
  OPERATIONS_RUNBOOK.md           falhas comuns e resposta operacional
  RELEASE_CHECKLIST.md            decisão formal de go/no-go
  USER_GUIDE.md                   tarefas e treinamento em linguagem simples
  CHANGELOG.md                    mudanças relevantes por incremento
  adr/                            decisões arquiteturais aceitas
  modules/                        contratos técnicos e funcionais por domínio
```

### 4.2 Padrão do guia de cada módulo

Cada `docs/modules/<modulo>.md` deverá conter:

1. objetivo e limites do módulo;
2. atores, permissões e escopos;
3. entidades, relacionamentos e invariantes;
4. fluxos felizes, alternativos e de erro;
5. máquina de estados, quando aplicável;
6. rotas, requests, serviços, eventos e jobs;
7. tabelas, constraints, índices e política de exclusão;
8. auditoria, dados pessoais e riscos;
9. testes automatizados e critérios de aceite;
10. telas, estados vazios, mensagens e decisões de UX;
11. limitações conhecidas e extensões previstas.

### 4.3 Documentação para Codex, Claude Code e novas equipes

O `AGENTS.md` deverá ser curto e operacional: comandos seguros, estrutura do projeto, arquivos obrigatórios antes de uma alteração, restrições arquiteturais, Definition of Done e mapa para `docs/INDEX.md`. Não deve duplicar toda a arquitetura.

Cada tarefa deverá referenciar IDs de requisitos e decisões. O fluxo recomendado para agentes é:

```text
ler AGENTS.md e docs/INDEX.md
 -> localizar módulo e ADRs
 -> inspecionar migrations/models/routes/policies/testes atuais
 -> descrever impacto
 -> implementar incremento pequeno
 -> executar testes e análise estática
 -> atualizar documentação e changelog
```

Diagramas devem ter fonte textual versionada, preferencialmente Mermaid. Exemplos de payload e comandos devem ser executáveis e livres de segredos. Decisões substituídas recebem novo ADR; não se reescreve o histórico.

---

## 5. Estratégia de UX para usuários com baixo conhecimento tecnológico

### 5.1 Objetivos de experiência

- permitir concluir tarefas frequentes sem treinamento formal;
- reduzir memorização, digitação e quantidade de escolhas simultâneas;
- usar linguagem cotidiana e explicar termos técnicos ou federativos;
- evitar perda de dados e oferecer recuperação clara de erros;
- priorizar celular para professores, alunos e responsáveis;
- manter operações administrativas extensas eficientes no desktop.

### 5.2 Padrões obrigatórios

1. **Início por papel:** professor vê aulas e chamadas; financeiro vê vencimentos e baixas; gestor vê pendências e indicadores.
2. **Uma ação principal por tela:** ações secundárias ficam visualmente subordinadas.
3. **Cadastro guiado:** aluno é criado em etapas curtas — pessoa, vínculo, responsável, modalidade/dojo, turma, plano e revisão.
4. **Salvamento de rascunho:** fluxos longos podem ser interrompidos sem perder dados.
5. **Preenchimento assistido:** máscaras, valores padrão seguros, busca de pessoa existente e prevenção de duplicidade.
6. **Divulgação progressiva:** campos raros e configurações avançadas aparecem somente quando necessários.
7. **Mensagens acionáveis:** informar o que ocorreu e como corrigir, preservando os dados já digitados.
8. **Confirmação proporcional ao risco:** pagamento, estorno, inativação e reabertura mostram consequência e contexto.
9. **Estados visíveis:** rascunho, pendente, vencido, pago e cancelado usam texto e ícone; cor nunca é o único sinal.
10. **Acessibilidade:** teclado, foco visível, contraste, labels, leitores de tela e alvos de toque adequados, seguindo nível AA da norma adotada pelo projeto.
11. **Ajuda contextual:** exemplos curtos ao lado do campo e central de ajuda baseada em tarefas.
12. **Consistência:** mesmos nomes, posição de ações, filtros e feedback em todos os módulos.

### 5.3 Fluxos que exigem protótipo e teste antes do backend completo

- cadastro e ativação de aluno menor;
- busca de pessoa existente e prevenção de duplicidade;
- chamada mobile, incluindo “marcar todos presentes” e exceções;
- geração e baixa de cobrança, pagamento parcial e estorno;
- solicitação e dupla aprovação de gratuidade;
- inscrição em campeonato com cobrança;
- alternância entre dependentes no portal do responsável.

### 5.4 Validação de usabilidade

Ao fim de cada marco, realizar teste moderado com usuários representativos, preferencialmente professores, administrativo/financeiro e responsáveis. Medir:

- taxa de conclusão sem ajuda;
- tempo e quantidade de erros nas tarefas críticas;
- pontos em que o usuário pede explicação;
- sucesso em celular de entrada e conexão lenta;
- compreensão de status, mensagens e consequências.

Problemas que impeçam conclusão, causem lançamento financeiro incorreto ou exponham dados bloqueiam o release. Melhorias apenas estéticas podem seguir para o backlog.

---

## 6. Plano de desenvolvimento por marcos e sprints

As sprints são incrementos recomendados de duas semanas. A duração deverá ser recalibrada após a Sprint 0, conforme equipe, hospedagem e decisões pendentes. Cada sprint termina com demonstração, testes, documentação e software potencialmente implantável no ambiente de homologação.

### Marco 0 — Descoberta e fundação técnica

#### Sprint 0 — Decisões, protótipos e baseline

**Objetivo:** remover incertezas que afetem banco, segurança, UX e deploy.

**Entregas:**

- validar versões disponíveis de PHP, banco, cron, SMTP e document root na hospedagem;
- mapa de personas, jornadas e inventário de tarefas;
- protótipos navegáveis dos fluxos críticos de aluno, chamada e pagamento;
- glossário e catálogo inicial de regras com IDs rastreáveis;
- decisões D-01 a D-04 e donos/prazos das demais;
- modelo conceitual e ERD inicial revisados;
- threat model inicial, classificação de dados e matriz RBAC preliminar;
- arquitetura C4, ADRs iniciais e estrutura documental da seção 4;
- estratégia de testes, ambientes, versionamento e releases;
- backlog priorizado com critérios de aceite.

**Saída:** arquitetura aprovada, fluxos críticos validados com usuários e nenhuma decisão estrutural sem responsável.

#### Sprint 1 — Esqueleto executável e segurança básica

**Entregas:**

- projeto, configuração por ambiente e `.env.example`;
- pipeline local/CI com testes, lint, análise estática e verificação de migrations;
- layout responsivo, design tokens e componentes base;
- autenticação, recuperação de acesso, usuário ativo e sessão segura;
- migrations de associação, pessoa, usuários, roles e permissions;
- associação atual, middleware de escopo, Policies/Gates e seed mínimo;
- auditoria básica, logs estruturados, health check e página de versão;
- documentação de instalação, arquitetura, RBAC, segurança e deploy inicial.

**Testes de saída:** login, rate limiting, isolamento de associação, negação por escopo, CSRF e ausência de stack trace em produção.

### Marco 1 — Fundação administrativa

#### Sprint 2 — Associação, dojos e pessoas

- CRUD com inativação de associação e dojo;
- cadastro único e busca de pessoas por nome, CPF, e-mail e telefone;
- validação e normalização de CPF e contatos;
- papéis institucionais com vigência;
- prevenção orientada de duplicidades;
- auditoria e matriz de visibilidade de dados sensíveis;
- filtros, paginação server-side e exportação CSV básica.

#### Sprint 3 — Modalidades, graduações e perfis esportivos

- cadastros configuráveis de modalidade e graduação;
- aluno, professor, habilitações e vínculos históricos;
- responsáveis de menores com vigência e responsabilidades distintas;
- múltiplos dojos/modalidades conforme parâmetros aprovados;
- wizard de aluno com rascunho, revisão e ativação;
- histórico de graduação sem sobrescrita.

**Marco 1 aceito quando:** gestor cadastra a estrutura e ativa um aluno/professor sem duplicar pessoa, dentro do escopo autorizado e com histórico/auditoria íntegros.

### Marco 2 — Operação esportiva do MVP

#### Sprint 4 — Turmas, horários e matrículas

- turmas, professor principal/auxiliares, capacidade e faixa etária;
- agendas recorrentes versionadas por vigência;
- matrículas com prevenção de duplicidade ativa;
- validação de dojo, modalidade, idade e capacidade conforme política;
- calendário e listas de turma responsivos.

#### Sprint 5 — Aulas e chamada mobile

- geração idempotente de aulas para janela configurável;
- cancelamento e reagendamento sem alteração retroativa;
- chamada otimizada para celular e marcação em lote;
- fechamento/reabertura conforme D-05;
- autorização por turma e dojo, transação e auditoria;
- indicadores e relatórios mínimos de frequência.

#### Sprint 6 — Documentos privados

- tipos configuráveis, metadados, upload e validade;
- validação de MIME/tamanho, nome aleatório e armazenamento privado;
- download por controller e Policy, com streaming;
- exclusão lógica, auditoria e teste de tentativa de acesso direto;
- avisos visuais de vencimento, ainda sem exigir envio automático.

**Marco 2 aceito quando:** professor autorizado realiza uma chamada completa no celular, o gestor consulta frequência e nenhum usuário acessa turma, aluno ou documento fora do seu escopo.

### Marco 3 — Financeiro do MVP

#### Sprint 7 — Planos e benefícios

- planos financeiros e vínculos com snapshot do valor base;
- descontos com vigência, aprovação quando definida e auditoria;
- gratuidade com máquina de estados canônica e dupla aprovação;
- simulador de cálculo explicável antes da geração da cobrança;
- testes de vigência, concorrência e transições inválidas.

#### Sprint 8 — Cobranças recorrentes

- motor único `Origem -> Cobrança -> Pagamento -> Baixa`;
- geração idempotente por plano, origem e competência;
- aplicação determinística de desconto/gratuidade;
- atualização de vencidas e registro de execução dos jobs;
- telas de abertas, vencidas e extrato do aluno;
- snapshots e trilha completa da composição de valor.

#### Sprint 9 — Pagamentos, estornos e relatórios

- baixa total/parcial com transação, row lock e proteção contra reenvio;
- estorno auditável sem exclusão física;
- recibo simples somente se requisitos legais e snapshot estiverem aprovados;
- contas a receber, inadimplência, recebimentos, receita por dojo/modalidade;
- exportação CSV e XLSX/PDF apenas onde houver necessidade validada;
- testes concorrentes e reconciliação dos saldos.

**Marco 3 / MVP aceito quando:** o ciclo completo de cadastro, turma, chamada, plano, cobrança, pagamento e relatório funciona em homologação; saldos são reconciliáveis; restore foi testado; segurança e usabilidade não possuem bloqueadores.

### Marco 4 — Federação e campeonatos

#### Sprint 10 — Federações e filiações

- federações, filiações por período, documentos e graduação registrada;
- histórico imutável, vencimentos e relatórios;
- integração somente por leitura no histórico esportivo consolidado.

#### Sprint 11 — Campeonatos

- campeonato, inscrições por papel, aprovação e restrições de prazo;
- cobrança pelo motor financeiro comum;
- resultados, medalhas, pontuação e histórico esportivo derivado;
- testes de duplicidade, autorização e consistência financeira.

### Marco 5 — Portal, comunicação e maturidade operacional

#### Sprint 12 — Portal do aluno e responsável

- visão própria/dependentes autorizados;
- turmas, frequência, financeiro, documentos, graduação e histórico;
- atualização apenas de campos permitidos;
- inscrição em campeonato quando liberada;
- navegação mobile simplificada e alternância clara entre dependentes.

#### Sprint 13 — Notificações e dashboards

- eventos e templates desacoplados de canal;
- caixa interna e e-mail em lotes via cron;
- retentativa, falha observável e preferência de contato;
- dashboards por papel e escopo, com queries indexadas;
- nenhuma dependência obrigatória de Redis ou processo residente.

#### Sprint 14 — Hardening e produção

Status em 30/08/2026: incremento de hardening e preparação operacional disponível em homologação. Produção permanece **NO-GO** até fechamento dos gates de segurança, operação e aceite. Evidências em `docs/RELEASE_VALIDATION.md`; pendências e responsáveis em `docs/RELEASE_CHECKLIST.md`. Não considerar os itens abaixo integralmente aceitos apenas por testes locais.

- revisão de segurança, privacidade, autorização e dependências;
- testes de carga nos fluxos críticos e `EXPLAIN` das consultas principais;
- acessibilidade e testes finais com usuários;
- ensaio de deploy, rollback, backup e restauração;
- runbooks, treinamento curto por tarefa e material de ajuda;
- correção de bloqueadores e go/no-go formal.

### Evoluções posteriores

- exames de graduação completos;
- gateway/PIX/boleto e conciliação;
- WhatsApp, PWA e aplicativo;
- integrações com federações;
- aulas experimentais/prospects;
- ranking e categorias automáticas;
- repasses Associação x Dojo;
- object storage, filas ou cache somente quando métricas justificarem.

---

## 7. Qualidade, testes e gates

### 7.1 Pirâmide de testes

- **Unitários:** cálculos, invariantes e máquinas de estado.
- **Integração:** banco, constraints, transações, storage, e-mail fake e jobs.
- **Feature:** requests, autenticação, Policy, escopo e respostas.
- **End-to-end:** jornadas críticas em desktop e mobile.
- **Não funcionais:** acessibilidade, segurança, concorrência, desempenho, backup e restore.

### 7.2 Casos críticos mínimos

Manter todos os 12 casos definidos na especificação técnica e acrescentar:

- tentativa de trocar `association_id`/`dojo_id` no request não amplia acesso;
- CPF existente leva ao vínculo com pessoa, não à duplicação silenciosa;
- chamada fechada não muda sem permissão e auditoria de reabertura;
- alteração de agenda não modifica aula realizada;
- duas requisições com a mesma chave idempotente geram uma baixa;
- soma de pagamentos válidos sempre reconcilia com saldo da cobrança;
- documento com extensão falsa é recusado;
- responsável não visualiza aluno sem vínculo vigente;
- logs e auditoria não expõem documento, senha, token ou dado excessivo;
- falha de um job é visível e a reexecução não duplica dados.

### 7.3 Definition of Done por história

Uma história só está concluída quando:

- requisito e critérios de aceite estão identificados;
- migration é reversível ou possui plano de rollback seguro;
- validação, autorização e escopo existem no backend;
- regra crítica está em serviço de domínio e usa transação quando necessário;
- auditoria e tratamento de dados pessoais foram avaliados;
- interface cobre carregamento, vazio, sucesso, erro e falta de permissão;
- responsividade e acessibilidade foram verificadas;
- testes relevantes passam em CI;
- documentação do módulo, banco/API e changelog foram atualizados;
- não há segredo ou dado pessoal em código, fixture, log ou exemplo.

### 7.4 Gates de release

1. **Gate funcional:** critérios aceitos pelo responsável do produto.
2. **Gate de dados:** migrations, constraints, reconciliação e rollback revisados.
3. **Gate de segurança:** autorização negativa, upload e exposição de dados testados.
4. **Gate de UX:** tarefas críticas concluídas por usuários representativos.
5. **Gate operacional:** deploy, cron, SMTP, saúde, backup e restore comprovados.
6. **Gate documental:** índice, módulo, ADRs, runbooks e changelog atualizados.

---

## 8. Governança e execução

### 8.1 Papéis mínimos

- responsável de produto com poder para decidir regras funcionais;
- referência de associação/dojo e referência financeira;
- UX/UI com acesso a usuários reais;
- liderança técnica/arquitetura;
- desenvolvimento backend e frontend;
- qualidade/testes;
- responsável por infraestrutura, segurança e proteção de dados, mesmo que acumulados por integrantes da equipe.

### 8.2 Ritos e artefatos

- refinamento semanal com regra de negócio e UX;
- ADR antes de decisão estrutural irreversível;
- demonstração ao fim de cada sprint em dados não pessoais;
- revisão de risco, métricas e dívida técnica por marco;
- registro de decisão, responsável e data no backlog;
- changelog e versão em cada implantação de homologação/produção.

### 8.3 Estimativa inicial

Com uma equipe pequena e multidisciplinar, o MVP até o Marco 3 representa aproximadamente 10 sprints incluindo descoberta, ou cerca de 20 semanas, acrescidas da estabilização necessária. Marcos 4 e 5 acrescentam aproximadamente 5 sprints. Essa é uma referência de planejamento, não um compromisso: deve ser recalculada após protótipos, decisões D-01 a D-10 e validação da hospedagem.

---

## 9. Riscos principais e mitigação

| Risco | Impacto | Mitigação |
|---|---|---|
| Escopo do MVP excessivo | atraso e baixa qualidade | marcos utilizáveis, prioridade explícita e bloqueio de evoluções antes do núcleo |
| Regras financeiras indefinidas | saldo incorreto e retrabalho | oficina antes da Sprint 7, exemplos calculados e testes de propriedade |
| Vazamento entre associações/dojos | incidente grave de privacidade | escopo central, policies, testes negativos e revisão de segurança |
| Duplicidade de pessoa/aluno | histórico fragmentado | busca anterior, normalização, constraints e fluxo de mesclagem posterior controlado |
| Interface complexa | abandono e erros operacionais | protótipo, linguagem simples, testes com usuários e telemetria sem dados pessoais |
| Limites da hospedagem compartilhada | jobs/deploy instáveis | prova técnica na Sprint 0, health check e runbooks de cron/SMTP/storage |
| Documentação desatualizada | manutenção insegura por equipes/IA | documentação no DoD, PR template, validação de links e donos por módulo |
| Crescimento de relatórios | lentidão | paginação/filtros, índices, EXPLAIN e exportações assíncronas via cron quando necessário |
| Backup incompleto | perda de documentos ou dados | backup coordenado de banco + storage e restore periódico |
| Uso indiscriminado de IA | mudanças amplas ou inconsistentes | AGENTS.md, tarefas pequenas, ADRs, testes e revisão humana em finanças/segurança |

---

## 10. Indicadores de sucesso

### Produto e UX

- conclusão de chamada mobile sem ajuda;
- conclusão do cadastro de aluno sem duplicidade;
- redução de dúvidas recorrentes e correções manuais;
- tempo para localizar aluno, cobrança e pendência;
- taxa de sucesso das tarefas críticas em testes de usabilidade.

### Qualidade e segurança

- zero acesso indevido conhecido entre escopos;
- zero duplicidade causada por jobs idempotentes;
- reconciliação integral entre cobrança, pagamentos válidos e saldo;
- cobertura dos casos críticos e estabilidade da suíte;
- vulnerabilidades críticas corrigidas antes do release.

### Operação e manutenção

- deploy e rollback reproduzíveis;
- execução e falhas de cron visíveis;
- restauração comprovada dentro do objetivo aprovado;
- tempo para uma nova equipe localizar arquitetura, regra e ponto de alteração;
- percentual de mudanças estruturais acompanhadas de ADR/documentação.

---

## 11. Estado após a execução do plano

As Sprints 0–21 estão concluídas tecnicamente. O trabalho seguinte não é iniciar outra sprint por inferência: primeiro deve passar pelos gates de produção, homologação humana e decisões abertas descritas em `docs/RELEASE_CHECKLIST.md` e `docs/DECISIONS.md`. Melhorias futuras devem nascer de requisito aprovado, atualizar ADR/módulo/testes e seguir o processo de `docs/MAINTENANCE_GUIDE.md`.
