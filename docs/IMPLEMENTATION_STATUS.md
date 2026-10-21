# Estado atual da implementação

**Atualizado em:** 11/10/2026  
**Marca apresentada:** JUDOMA  
**Identificador técnico legado:** SIGAM (`sigam:*`, `SIGAM_*`, nomes de banco e infraestrutura)

## Resumo executivo

O roadmap funcional das Sprints 1–21 está implementado e disponível em homologação. A aplicação é um monólito modular Laravel 12/PHP 8.2+, com Blade responsivo, MySQL como banco-alvo, arquivos privados, SMTP e scheduler por cron. Docker é opcional e serve apenas para desenvolvimento/homologação.

O código está tecnicamente concluído para homologação, mas a produção continua **NO-GO** enquanto houver gates humanos, jurídicos e de infraestrutura pendentes em `RELEASE_CHECKLIST.md`.

Melhorias posteriores não fazem parte do estado implementado apenas por estarem planejadas. O backlog canônico está em `IMPROVEMENT_BACKLOG.md`; seus itens de experiência móvel permanecem como propostas até que cada um cumpra os critérios de implementação e homologação registrados ali.

## Capacidades implementadas

| Área | Estado implementado |
|---|---|
| Identidade | login, logout, conta ativa/inativa, recuperação de senha, administrador inicial por seed e convite seguro de professor |
| RBAC | papéis globais/associativos, perfis fixos da associação (gestor, financeiro, portal) com tela de usuários e convite por e-mail, e concessões locais por dojo; escopo revalidado no backend |
| Organização | associações, dojos, pessoas centrais, normalização, busca, inativação e CSV seguro |
| Cadastro esportivo | modalidades, graduações, templates opcionais, alunos, responsáveis, professores, vínculos multi-dojo e históricos |
| Cadastro direto | aluno/responsável/documento no contexto do dojo e professor/convite no contexto da associação |
| Turmas | vários dias/horários, versões de agenda, professor, capacidade, faixa etária e matrículas; página da turma no dojo, edição, encerramento de matrícula e professores auxiliares |
| Aulas | meta automática de 4/8 ocorrências, reposição horária, cancelamento, reagendamento e chamada mobile |
| Visitantes | participação gratuita por aula, somente dentro da mesma associação, sem matrícula ou cobrança |
| Importação | CSV/XLSX de alunos e presenças, prévia, revisão, lotes retomáveis, idempotência e retenção privada |
| Documentos | upload privado e contextual para pessoa/aluno/professor/responsável/filiação; download autorizado e exclusão lógica |
| Financeiro | planos, origem por dojo, descontos, gratuidades, cálculo explicável, cobranças recorrentes e vencimento |
| Pagamentos | baixa parcial/integral idempotente, estorno auditável, extrato, relatórios e CSV |
| Federações | catálogo, filiações históricas, validade, documentos e relatórios |
| Campeonatos | evento central, participação por dojo, inscrições, decisão, cobrança, resultado, histórico e CSV |
| Portal | visão própria/dependentes, contatos limitados, documentos, finanças e inscrição em campeonato |
| Notificações | caixa interna, preferências, e-mail em lote, retentativa e detectores recorrentes |
| Dashboards | associação, dojo, professor e visão pessoal, sempre com escopo derivado no servidor |
| Operação | preflight, auditoria, registros de jobs, backup/restore documentado e ambiente Docker opcional |

## Sprints

| Sprint | Entrega | Estado |
|---:|---|---|
| 0–1 | decisões, baseline, identidade e segurança básica | Implementada |
| 2–6 | organização, cadastro esportivo, turmas, presença e documentos | Implementada |
| 7–9 | planos, cobranças, pagamentos e relatórios | Implementada |
| 10–13 | federações, campeonatos, portal, notificações e dashboards | Implementada |
| 14 | hardening e preparação operacional | Implementada tecnicamente; produção NO-GO |
| 15 | contexto e autorização por dojo | Implementada |
| 16 | cadastro direto e documentos contextuais | Implementada |
| 17 | convites e acesso do professor | Implementada |
| 18 | agenda semanal e visitantes | Implementada |
| 19 | importação assistida | Implementada |
| 20 | próximas aulas automáticas | Implementada |
| 21 | consolidação associação/dojo, financeiro e campeonatos | Implementada |

## Não implementado ou fora do escopo atual

- publicação definitiva em produção e aceite com dados reais;
- MFA administrativo ou controle equivalente aprovado;
- matriz jurídica definitiva de visibilidade por campo e política completa de retenção/LGPD;
- troca de associação pela interface para usuário comum ou operação SaaS pública;
- gateway de pagamento/PIX/boleto, emissão fiscal ou recibo formal;
- WhatsApp, aplicativo/PWA offline e integrações automáticas com federações;
- fluxo completo de exames de graduação e ranking/categoria automática;
- painel automático para reconciliar entrega SMTP em estado ambíguo;
- Redis, worker residente, microserviços e filas externas — deliberadamente não são requisitos.

## Melhorias planejadas

O backlog pós-roadmap prioriza início e navegação por perfil, chamada móvel, portal por tarefas, feedback em rede instável, linguagem simples, notificações acionáveis, formulários móveis e acessibilidade. PWA instalável é posterior; chamada offline depende de discovery de segurança e sincronização. Consulte `IMPROVEMENT_BACKLOG.md` para IDs, prioridades e critérios. Nenhum desses itens deve ser apresentado como entregue no estado atual.

## Evidência técnica vigente

- SQLite: 136 testes, 722 asserções, 1 teste condicionado ao ambiente ignorado (12/10/2026, após correções financeiras);
- MySQL 8.4: executado pelo CI do GitHub Actions a cada push (`.github/workflows/ci.yml`); última contagem manual: 133 testes, 715 asserções;
- Pint aprovado;
- migrations 1–23 aplicadas em homologação Docker;
- detalhes da última consolidação em `SPRINT21_VALIDATION.md`.

Esses números são uma fotografia. Ao alterar a suíte, atualize este documento e `TESTING.md` com a nova execução comprovada.
