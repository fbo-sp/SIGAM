# Backlog de melhorias

**Atualizado em:** 11/10/2026  
**Estado:** propostas priorizadas para refinamento; nenhuma entrega deste documento deve ser presumida como implementada  
**Foco inicial:** experiência móvel de professores, alunos e responsáveis

## 1. Finalidade e governança

Este é o backlog canônico de melhorias posteriores ao roadmap técnico das Sprints 1–21. Ele existe para que produto, desenvolvimento, UX e agentes de IA encontrem as propostas, seus limites e critérios sem depender de conversas anteriores.

`IMPLEMENTATION_STATUS.md` continua sendo a fonte do que existe hoje. Um item deste backlog só muda para **Implementado** depois de código, testes, documentação do módulo, guia do usuário e changelog serem atualizados. Prioridade não equivale a autorização para alterar regra de negócio, permissão, retenção ou infraestrutura.

Estados permitidos:

- **Proposta:** problema e direção registrados, ainda requer validação/refinamento;
- **Pronta:** critérios, protótipo, dependências e aceite foram aprovados;
- **Em desenvolvimento:** implementação iniciada em incremento identificado;
- **Em homologação:** código concluído, aguardando testes humanos previstos;
- **Implementada:** publicada no estado documentado e comprovada;
- **Descartada:** decisão e justificativa registradas sem apagar o histórico.

Toda mudança de estado deve registrar data, responsável e evidência no changelog ou documento de validação do incremento.

## 2. Contexto observado

A interface atual já possui viewport responsivo, campos e botões grandes, foco visível, link para conteúdo, cartões adaptáveis e ações fixas em formulários/chamada. Os principais pontos de evolução são:

- navegação superior ainda apresenta a estrutura geral do sistema, mesmo para perfis com poucas tarefas;
- professor precisa localizar dojo/aula antes de chegar à chamada;
- chamada usa um `select` por aluno e não mostra progresso de conferência;
- portal concentra sete áreas em uma página longa;
- alguns textos operacionais exibem conceitos técnicos ou estados internos;
- feedback de envio não diferencia claramente processando, salvo e falha de conexão;
- notificações informam, mas não levam diretamente ao recurso relacionado;
- formulários ainda não aplicam de modo uniforme teclado, `autocomplete` e prevenção de entrada redundante.

O público considerado possui, em geral, baixa familiaridade tecnológica e acessa principalmente por celular. A solução deve privilegiar tarefas frequentes, texto direto, operação com uma mão e recuperação segura de erro.

## 3. Princípios de experiência

1. **A tarefa vem antes do módulo:** “Abrir chamada” é mais compreensível que navegar por cadastros.
2. **Cada perfil vê primeiro o que pode fazer:** não apresentar atalhos sem utilidade para professor, aluno ou responsável.
3. **Uma ação principal por tela:** ações destrutivas ou excepcionais permanecem separadas.
4. **Estado sempre explícito:** processando, salvo, pendente, falhou e concluído usam texto, não apenas cor.
5. **Progressive enhancement:** o fluxo essencial funciona com Blade/HTML; JavaScript melhora velocidade sem carregar regra de autorização.
6. **Backend continua soberano:** navegação simplificada nunca substitui permissão, escopo ou revalidação no serviço.
7. **Privacidade por padrão:** não persistir documentos, listas de alunos ou finanças em cache offline sem decisão específica.
8. **Celular simples é o cenário-base:** validar 320 CSS pixels, Android de entrada, rede lenta e uso com uma mão.

## 4. Backlog priorizado

| ID | Melhoria | Prioridade | Esforço inicial | Estado |
|---|---|---:|---:|---|
| UXM-01 | Início e navegação móvel por perfil | P0 | Médio | Proposta |
| UXM-02 | Chamada móvel rápida e segura | P0 | Médio/alto | Proposta |
| UXM-03 | Portal dividido por tarefas | P0 | Médio | Proposta |
| UXM-04 | Feedback de envio e resiliência à rede | P0 | Médio | Proposta |
| UXM-05 | Linguagem simples e catálogo de estados | P0 | Baixo/médio | Proposta |
| UXM-06 | Notificações priorizadas e acionáveis | P1 | Médio | Proposta |
| UXM-07 | Formulários adaptados ao celular | P1 | Médio | Proposta |
| UXM-08 | Baseline de acessibilidade móvel | P0 transversal | Médio | Proposta |
| UXM-09 | Homologação de usabilidade e métricas | P0 transversal | Médio | Proposta |
| UXM-10 | Desempenho percebido e orçamento de página | P1 | Médio | Proposta |
| UXM-11 | PWA instalável sem dados privados offline | P2 | Médio | Proposta |
| UXM-12 | Avaliar chamada offline com sincronização | P3 | Alto | Proposta condicionada |

P0 representa maior impacto para o uso móvel, não execução automática. UXM-08 e UXM-09 acompanham todos os ciclos e não devem ser adiadas para o final.

## 5. Detalhamento dos itens

### UXM-01 — Início e navegação móvel por perfil

**Problema:** a navegação geral exige que usuários de rotina entendam a estrutura administrativa antes de chegar à tarefa.

**Resultado esperado:** professor chega à próxima chamada em até dois toques; aluno ou responsável encontra frequência, cobrança ou documento sem entrar em módulos administrativos.

**Proposta funcional:** 

- professor: início com próxima aula, chamada pendente, próximas aulas, avisos e troca de dojo;
- aluno/responsável: próxima aula, frequência recente, pendências financeiras/documentais, campeonatos e avisos;
- barra inferior móvel com no máximo cinco destinos e item atual identificado;
- sugestão para professor: `Hoje`, `Aulas`, `Dojos`, `Avisos`, `Conta`;
- sugestão para portal: `Início`, `Frequência`, `Financeiro`, `Documentos`, `Avisos`;
- administração e funções menos frequentes ficam em `Mais` ou continuam na navegação desktop;
- desktop mantém navegação superior, sem duplicar links para leitor de tela.

**Critérios de aceite:** 

- destinos são derivados das permissões e relações vigentes no servidor;
- conta com mais de um perfil recebe uma ordem previsível e consegue trocar de contexto;
- página atual usa texto/estado e `aria-current`, não somente cor;
- barra não cobre conteúdo nem ações fixas e respeita área segura do dispositivo;
- funciona a 320 CSS pixels, zoom de 200% e teclado;
- URLs existentes continuam válidas para favoritos e links recebidos.

### UXM-02 — Chamada móvel rápida e segura

**Problema:** um `select` por aluno reduz a velocidade e não deixa claro quantos registros foram efetivamente conferidos. Como “Presente” é a primeira opção, finalizar sem interação pode parecer revisão concluída.

**Resultado esperado:** chamada de uma turma comum concluída com uma mão, com poucos toques, resumo antes do fechamento e sem perda em erro de rede.

**Proposta funcional:** 

1. mostrar aula, horário, dojo, quantidade regular e visitantes no topo;
2. iniciar com ação explícita **Considerar todos presentes** ou exigir marcação individual;
3. oferecer alvos grandes `Presente`, `Ausente` e `Justificada` em cada aluno;
4. manter `Reposição` e `Experimental` em opção secundária;
5. mostrar progresso `18 de 22 conferidos` e contadores por estado;
6. permitir busca por nome/matrícula em turmas grandes;
7. manter visitante identificado por texto, sem depender de cor;
8. exibir **Salvar e continuar** e **Finalizar chamada** no rodapé;
9. antes de finalizar, confirmar totais e visitantes;
10. depois de salvar, informar horário do último salvamento e oferecer **Desfazer** quando seguro.

**Critérios de aceite:** 

- nenhum aluno recebe presença por acidente apenas por abrir a página;
- fechamento continua exigindo lista completa e usa `AttendanceService` sob lock;
- clique repetido não duplica nem corrompe registros;
- falha conserva as escolhas no formulário e explica se é seguro tentar novamente;
- chamada fechada continua imutável sem permissão/motivo de reabertura;
- visitantes, cancelamentos, reagendamentos e escopo do professor mantêm as invariantes atuais;
- experiência equivalente existe nas rotas associativa e operacional sem duplicar regra de domínio;
- testes cobrem outra associação/dojo, aula alheia, replay, lista incompleta e finalização.

### UXM-03 — Portal dividido por tarefas

**Problema:** resumo, contatos, turmas, frequência, financeiro, documentos, histórico e campeonatos formam uma página longa no celular.

**Resultado esperado:** o usuário encontra a informação mais importante acima da dobra e abre detalhes apenas quando necessário.

**Proposta funcional:** 

- início do portal com próxima aula e cartões de atenção: falta recente, cobrança vencida, documento vencendo e inscrição pendente;
- separar frequência, financeiro, documentos, histórico e campeonatos em rotas/páginas próprias;
- seletor de dependente persistente e claramente visível;
- conservar aluno selecionado ao navegar entre áreas;
- listas exibem itens recentes/pendentes e ação **Ver histórico completo**;
- formulário de contatos permanece separado de informações somente leitura;
- voltar preserva contexto e posição lógica, sem depender do histórico acidental do navegador.

**Critérios de aceite:** 

- `PortalAccessService` resolve o aluno em toda rota; ID do request não prova titularidade;
- responsável não legal continua sem alterar contatos;
- documentos continuam disponíveis apenas por rota privada e auditada;
- financeiro permanece somente leitura;
- dependente sem vínculo vigente retorna 404 e deixa de aparecer no seletor;
- links diretos funcionam após autenticação e levam ao dependente autorizado correto.

### UXM-04 — Feedback de envio e resiliência à rede

**Problema:** em rede móvel lenta, ausência de estado intermediário incentiva toque repetido ou abandono.

**Proposta funcional:** 

- ao enviar, desabilitar o botão e trocar o rótulo para `Salvando...`;
- anunciar conclusão com `role="status"` e horário quando útil;
- diferenciar validação, indisponibilidade temporária e perda de autorização;
- preservar valores e posição do erro;
- alertar ao sair de formulário alterado, sem bloquear páginas sem mudança;
- confirmar se uma retentativa é segura em operações idempotentes;
- avaliar rascunho no servidor para chamada longa, sem confiar em armazenamento local como fonte de verdade.

**Critérios de aceite:** toque duplo não gera efeito adicional; falha não limpa campos; leitor de tela recebe o resultado; fluxo essencial continua funcionando sem JavaScript, ainda que sem indicador dinâmico.

### UXM-05 — Linguagem simples e catálogo de estados

**Problema:** termos como `idempotente`, `escopo`, `open`, `pending` e `awaiting_dojo` não pertencem à interface do usuário.

**Proposta funcional:** criar apresentação centralizada de estados e mensagens em português, mantendo valores técnicos somente no domínio/banco.

Exemplos:

| Técnico | Interface |
|---|---|
| `open` | Em aberto |
| `overdue` | Vencida |
| `pending` | Aguardando análise |
| `awaiting_dojo` | Aguardando aprovação do dojo |
| `awaiting_association` | Aguardando aprovação da associação |
| `idempotente` | Você pode tentar novamente com segurança |
| `escopo` | Unidade ou associação permitida |

**Critérios de aceite:** nenhuma view imprime enum interno como fallback silencioso; mensagens dizem o que ocorreu e a próxima ação; termos são consistentes em portal, operação, e-mail e guia; mudança de rótulo não altera estado persistido.

### UXM-06 — Notificações priorizadas e acionáveis

**Proposta funcional:** 

- agrupar em `Precisa de atenção`, `Hoje` e `Anteriores`;
- incluir ação contextual, por exemplo **Abrir chamada**, **Ver cobrança**, **Atualizar documento** ou **Concluir inscrição**;
- mostrar contador em badge com alternativa textual;
- marcar como lida ao abrir o recurso somente após decisão de produto;
- manter e-mail genérico e exigir autenticação no destino.

**Critérios de aceite:** o destino é reconstruído/revalidado no servidor; aviso antigo sem acesso atual permanece redigido; outra associação/usuário não consegue usar a URL; ação continua compreensível sem ícone.

### UXM-07 — Formulários adaptados ao celular

**Proposta funcional:** 

- aplicar `type`, `inputmode` e `autocomplete` adequados a e-mail, telefone, nome, nascimento e endereço;
- usar `type="tel"` para telefone e não impedir colar/editar por máscara;
- marcar campos opcionais no rótulo;
- exibir exemplo curto e regra antes do erro;
- resumo de erros oferece links para os campos;
- evitar pedir novamente dados já conhecidos;
- dividir cadastros longos em etapas curtas com resumo final;
- pesquisa/autocomplete acessível para listas extensas de aluno, professor ou plano.

**Critérios de aceite:** teclado móvel correto; preenchimento automático não mistura aluno e responsável; valores sobrevivem à validação; sem JavaScript o formulário permanece completo; testes cobrem normalização e manipulação de IDs.

### UXM-08 — Baseline de acessibilidade móvel

Aplicar transversalmente:

- alvos práticos de 44–48 CSS pixels, preservando ao menos o mínimo WCAG 2.2;
- reflow sem perda a 320 CSS pixels e zoom de 400%;
- contraste, foco visível, ordem de foco e skip link;
- nome, função e estado acessíveis de menu, abas, acordeões e controles de chamada;
- status anunciado sem deslocar foco desnecessariamente;
- ações destrutivas afastadas da ação principal;
- texto e ícone juntos quando o significado não for universal;
- validação manual com teclado, TalkBack e leitor de tela equivalente disponível.

Referências oficiais:

- [WCAG 2.2 — Target Size (Minimum)](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum)
- [WCAG — Reflow](https://www.w3.org/WAI/WCAG21/Understanding/reflow)
- [WCAG 2.2 — Identify Input Purpose](https://www.w3.org/WAI/WCAG22/Understanding/identify-input-purpose)
- [WCAG 2.2 — Status Messages](https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html)

### UXM-09 — Homologação de usabilidade e métricas

**Participantes mínimos:** professores, alunos adultos, responsáveis e pelo menos um usuário com baixa familiaridade tecnológica. Incluir celulares Android simples e, quando possível, pessoas idosas ou com necessidade de acessibilidade.

**Tarefas de referência:** 

- professor abre a aula de hoje e conclui chamada de 20 alunos;
- professor registra uma exceção e adiciona visitante;
- aluno encontra próxima aula e frequência;
- responsável troca de dependente e encontra cobrança/documento;
- usuário corrige erro de formulário e recupera senha.

**Métricas propostas, a validar antes de virarem meta contratual:** 

- chegar à chamada em até dois toques após login do professor;
- concluir chamada de 20 alunos em até dois minutos, sem ajuda;
- localizar cobrança ou documento em até 20 segundos;
- pelo menos 90% das tarefas críticas concluídas sem intervenção;
- zero acesso fora do vínculo e zero perda silenciosa de dados;
- registrar hesitações, retornos, erros e linguagem que gerou dúvida.

Não incluir CPF, documento, conteúdo financeiro ou nome real em telemetria. Preferir testes moderados com dados fictícios e métricas agregadas.

### UXM-10 — Desempenho percebido e orçamento de página

- priorizar HTML útil no primeiro carregamento e evitar biblioteca pesada para navegação básica;
- reduzir consultas repetidas da barra superior, especialmente contagem de notificações;
- definir orçamento de CSS/JS e quantidade de consultas para início, chamada e portal;
- medir em rede móvel simulada e aparelho simples;
- usar paginação/lista incremental em turmas ou históricos grandes;
- manter feedback visual imediato sem afirmar sucesso antes da resposta do servidor.

Qualquer cache deve respeitar associação, usuário, vínculo atual e privacidade. Não adicionar Redis como requisito.

### UXM-11 — PWA instalável sem dados privados offline

Adicionar, somente após os P0/P1:

- manifesto, ícones, nome JUDOMA e abertura em modo standalone;
- cache apenas de shell e ativos públicos versionados;
- página offline genérica, sem conteúdo pessoal;
- atualização controlada do service worker;
- instalação opcional, nunca requisito de uso ou produção.

O service worker não deve armazenar respostas autenticadas, documentos, chamada, portal ou finanças.

### UXM-12 — Avaliar chamada offline com sincronização

Item condicionado a discovery específico. Antes de implementar, definir:

- criptografia/expiração no dispositivo e logout remoto;
- conflitos entre dois professores/dispositivos;
- versão da aula e lista autorizada;
- revogação de acesso enquanto offline;
- confirmação de sincronização e tratamento de parcialidade;
- auditoria, idempotência e suporte operacional.

Sem essas decisões, chamada offline permanece fora do escopo. Uma PWA instalável não autoriza cache de dados pessoais.

## 6. Sequência sugerida

| Ciclo | Escopo | Saída esperada |
|---|---|---|
| UX-A | UXM-05, baseline UXM-08 e protótipos UXM-01/02 | linguagem limpa, inventário de componentes e protótipos testáveis |
| UX-B | UXM-01, UXM-04 e parte de UXM-10 | início por perfil, navegação móvel e feedback consistente |
| UX-C | UXM-02 | chamada móvel homologada por professores |
| UX-D | UXM-03, UXM-06 e UXM-07 | portal por tarefas, avisos acionáveis e formulários melhores |
| UX-E | UXM-11; discovery UXM-12 separado | instalação opcional, sem ampliar cache privado |

UXM-09 acompanha cada ciclo. A sequência pode mudar após testes, mas não se recomenda iniciar PWA/offline antes de corrigir os fluxos online.

## 7. Definition of Ready

Um item pode passar para **Pronta** quando possuir:

- problema e público confirmados;
- fluxo atual medido ou observado;
- wireframe/protótipo para celular e desktop;
- estados vazio, carregando, sucesso, erro e sem permissão;
- impacto em rotas, serviços, autorização, privacidade e banco analisado;
- critérios de aceite funcionais, acessíveis e negativos;
- plano de teste com usuários e dados fictícios;
- decisão registrada para qualquer mudança estrutural.

## 8. Definition of Done

- funciona em 320 CSS pixels, celular representativo, teclado e zoom;
- texto em português simples e nenhum enum técnico exposto;
- autorização/escopo continuam no backend e possuem testes negativos;
- escrita crítica permanece transacional e idempotente;
- estados de rede/erro não apagam entrada;
- testes SQLite/MySQL e Pint aprovados;
- módulo, `USER_GUIDE.md`, `IMPLEMENTATION_STATUS.md`, este backlog e `CHANGELOG.md` atualizados;
- teste de usabilidade executado quando exigido pelo item;
- evidência e limitações registradas antes de marcar **Implementada**.

## 9. Pontos de código inicialmente afetados

| Área | Pontos prováveis |
|---|---|
| navegação | `resources/views/layouts/app.blade.php`, `dashboard.blade.php`, `public/css/sigam.css` |
| chamada | views `attendance/` e `operation/session.blade.php`, `attendance.js`, `AttendanceService` |
| portal | `PortalController`, `PortalAccessService`, views `portal/`, rotas `portal.*` |
| feedback/formulários | layout, Form Requests, `common.js`, componentes CSS e views de cadastro |
| notificações | `NotificationContentService`, `NotificationController`, views e catálogo de eventos |
| acessibilidade/desempenho | layouts, CSS/JS globais e testes feature/browser a definir |

Esses caminhos orientam a investigação; não autorizam concentrar regra de negócio em view/JavaScript nem duplicar serviços existentes.
