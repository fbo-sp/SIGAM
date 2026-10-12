# Plano de melhorias — cadastro direto e operação por dojo

Estado: Sprints 15–21 implementadas tecnicamente. Visitas são gratuitas e permitidas somente entre dojos da mesma associação. Este documento preserva o plano e os critérios que orientaram a implementação; o estado vigente está em `IMPLEMENTATION_STATUS.md`. As pendências de produção permanecem em `RELEASE_CHECKLIST.md`.

## 1. Objetivo

Permitir que o usuário cadastre alunos e professores diretamente, trabalhe no contexto correto de associação/dojo, guarde documentos no próprio cadastro e tenha aulas disponíveis sem executar comandos técnicos. Preservar identidade única, histórico, privacidade, autorização e compatibilidade com hospedagem compartilhada.

Premissa principal: **simplificar a experiência sem duplicar o cadastro central de pessoa**. O usuário executa uma tarefa; o sistema cria os vínculos necessários internamente.

## 2. Diagnóstico do código atual

| Área | Situação encontrada | Consequência |
|---|---|---|
| Aluno/professor | Formulários exigem `person_id`; serviços esperam pessoa já vinculada à associação | Troca obrigatória de telas |
| Identidade | `people` é central; CPF opcional globalmente único; aluno único por pessoa/associação | Reutilizar identidade exige autorização, não apenas encontrar CPF/e-mail |
| Login | Cadastro de professor não cria `User`; não há convite/aceite nem UI de usuários; seeder provisiona somente administrador | Necessário implementar ciclo completo de acesso |
| Permissões | `RequirePermission` e `User::hasPermission` usam principalmente associação; `users.dojo_id` limita indicadores, não toda a operação | Reorganizar menus sem mudar backend deixaria acesso cruzado |
| Professor | Perfil único por pessoa; existem vínculos com turmas, mas não vínculo operacional explícito professor–dojo | Necessário separar habilitação institucional de atuação local |
| Documentos | Arquivos privados com pessoa, aluno e filiação; não há vínculo explícito de contexto de professor/responsável | Upload e autorização precisam acompanhar o cadastro correto |
| Turma | `class_schedules` aceita várias linhas, mas request, serviço e formulário criam apenas uma | Reaproveitar tabela e expandir fluxo para múltiplos dias |
| Aulas | Gerador por intervalo, padrão de 45 dias; criação da turma não o chama; não consta no scheduler atual | Implantar geração inicial e reposição limitada |
| Histórico | Aulas têm chave única, referência à agenda e vínculo de reagendamento; financeiro tem snapshots | Não apagar/recriar histórico para adaptar a UX |

Fontes inspecionadas: documentos dos módulos, ADRs 001–005, migrations de identidade/esportes/turmas/aulas/documentos, models, rotas, middleware de autorização, serviços e testes existentes. A autorização atual está majoritariamente em middleware/controllers/serviços; não há diretório `app/Policies` implementando todo o modelo pretendido.

## 3. Requisitos refinados

### R1 — Cadastro direto de aluno, professor e responsável

- Entradas principais: **Novo aluno**, dentro do dojo; **Novo professor**, exclusivamente dentro da associação. Decisão Q2 confirmada: dojo seleciona professores previamente cadastrados pela associação, não cadastra nem convida professores novos.
- Formulário único, com seções curtas: dados pessoais, dados esportivos, responsáveis quando aplicável e documentos. Usar expansão progressiva de campos opcionais, sem obrigar navegação para Pessoas.
- Dojo e associação vêm do contexto autorizado. Se a conta possui apenas um dojo, não pedir uma seleção redundante. Contas com vários dojos escolhem antes de cadastrar.
- Criar pessoa, vínculo institucional e perfil esportivo numa operação transacional, com chave idempotente para repetição do envio. Reutilizar os serviços de domínio existentes por um serviço de orquestração; não fazer cadastro de pessoa e perfil em duas requisições independentes.
- Ao encontrar pessoa já acessível, apresentar confirmação explícita de reutilização. Uma pessoa pode ser aluno, professor e responsável sem duplicação.
- Não mesclar automaticamente por nome ou e-mail. CPF coincidente fora do escopo deve produzir encaminhamento seguro ao gestor, sem revelar dados externos. Corrigir concorrência por constraint/transação, não somente validação prévia.
- CPF continua opcional; ausência de CPF não impede cadastrar criança. Nome/nascimento e demais requisitos para ativação devem ser validados claramente; cadastro incompleto pode ser salvo como pré-cadastro.
- Permitir adicionar ou reutilizar responsáveis na mesma tela, com funções legal, financeira, principal e comunicação. Um responsável pode atender vários alunos; isso não dá acesso automático a todos os seus documentos.
- Proposta de UX: matrícula gerada automaticamente com unicidade na associação; edição somente por permissão específica. Não obrigar o operador a inventar códigos.
- Disponibilizar “Salvar pré-cadastro” e “Concluir cadastro”, com pendências visíveis. Ativação só ocorre se os requisitos forem atendidos. Matrícula em turma pode ser uma próxima ação contextual, não uma obrigatoriedade escondida.
- “Pessoas” fica como ferramenta administrativa avançada para manutenção controlada de identidade, não como porta de entrada operacional.

Aceite: cadastrar aluno e responsável, ou professor, sem abrir tela de Pessoas; erro preserva campos não sensíveis; envio repetido não duplica pessoa/perfis/vínculos; menor não é ativado sem responsável vigente quando exigido.

### R2 — Conta e convite de professor

Separar três estados: cadastro esportivo, vínculo institucional do professor e conta de acesso. Recusar um convite não deve apagar o perfil nem bloquear outros papéis já existentes.

Fluxo proposto:

1. Gestor autorizado da associação salva o professor com e-mail individual obrigatório para convite. Gestor de dojo não pode executar essa ação nem contorná-la por uma chamada direta à rota.
2. Em transação, o sistema provisiona conta pendente quando necessário e registra convite vinculado à pessoa, associação e papel Professor. Não entrega senha padrão nem concede permissões ativas nesse momento.
3. Após commit, uma rotina em lote envia e-mail com link exclusivo de ativação. Falha SMTP preserva cadastro e convite e fica visível para reenvio controlado.
4. O link abre uma página com associação, finalidade e remetente institucional. **GET não verifica e-mail, não aceita convite nem ativa acesso**, para evitar aceitação por scanners de e-mail.
5. Conta nova define a própria senha e confirma explicitamente “Aceitar convite e ativar acesso”. Em POST protegido, o sistema valida o token, registra verificação do endereço e aceite como fatos distintos, ativa o vínculo e libera somente as permissões de professor.
6. Conta existente autentica-se com suas credenciais e aceita o novo vínculo; não tem senha, associação atual ou outros papéis sobrescritos. Conflito de identidade/e-mail é resolvido por fluxo controlado, nunca por apropriação automática da conta.

Controles: token forte, prazo proposto de 72 horas, uso único, hash do segredo de validação em banco, invalidação de versões anteriores ao reenviar e revogação pelo gestor. Validar novamente convite, endereço, associação, cadastro e autoridade do emissor no aceite. Cliques/envios concorrentes não duplicam concessões. Sem tokens ou senhas em logs, auditoria ou listagens; página sem recursos externos, sem cache e com política de referência restritiva.

Estados visíveis: aguardando envio, enviado/aguardando aceite, aceito, recusado, expirado e revogado; falha de transporte é separada do estado do convite. A nova conta não autentica enquanto pendente. Revogar atuação em um dojo não desativa automaticamente a conta inteira.

O convite precisa de mensagem transacional própria: **não usar diretamente o texto genérico de `EmailDeliveryService`**, que atualmente remove detalhes/links. Reaproveitar reserva e observabilidade, mas implementar template e entrega de convite separados. Se o segredo de entrega precisar persistir na fila, mantê-lo cifrado, com acesso restrito e descarte após entrega/expiração; nunca guardar token em texto puro no evento.

Adicionar gestão mínima de convites, reenvio/revogação e recuperação segura de senha. Sem cadastro público. Em Docker, validar com capturador SMTP local opcional, sem enviar mensagens a pessoas reais; homologação de SMTP externo exige destinatário autorizado.

Aceite: link expirado/revogado/reutilizado não ativa; GET não altera estado; conta existente mantém senha e papéis; mudança de e-mail invalida convite anterior; aceite registra auditoria sem segredos. Convites não podem ser liberados antes do isolamento de turmas/aulas no backend.

### R3 — Associação governa; dojo opera

| Dados | Responsabilidade da associação | Responsabilidade do dojo |
|---|---|---|
| Dojos | Cadastrar, definir responsáveis e governar | Consultar contexto; alterações delegadas explicitamente |
| Modalidades e graduações | Manter catálogo e regras | Usar catálogo autorizado, sem criar cópia |
| Professores | Manter cadastro institucional, habilitações e convite | Vincular professores elegíveis à equipe e às turmas |
| Federações e filiações | Manter catálogo e processo federativo | Consultar situação dos próprios alunos conforme permissão |
| Planos e benefícios | Manter regras e aprovações institucionais | Vincular plano ao aluno, solicitar/aplicar benefícios dentro das alçadas |
| Campeonatos | Criar/publicar evento, regras, taxas e decisões centrais | Aderir/participar e gerir as próprias inscrições |
| Alunos e responsáveis | Supervisão/consolidação conforme permissão | Cadastro, manutenção e vínculos dos seus alunos |
| Turmas, horários e aulas | Supervisão conforme permissão | Criar, manter, matricular e operar presença |
| Cobranças e pagamentos | Consolidação e alçadas associativas | Gerir obrigações do próprio dojo conforme permissões financeiras |
| Inscrições | Aprovação/resultados conforme alçada existente | Inscrever alunos vinculados ao dojo e acompanhar situação |

#### Navegação

Separar “Associação” e “Meu dojo”, com contexto sempre visível no topo. O gestor associativo pode entrar num dojo autorizado; o gestor local não troca para outro dojo sem vínculo. Professor vê “Minhas turmas”, “Minhas aulas” e notificações. Não transformar isso em dois sistemas ou copiar os mesmos cadastros.

#### Autorização obrigatória

- Capacidade e escopo são verificados juntos. Implementar serviço de escopo e policies/queries reutilizáveis, aplicados a listagens, buscas, seletores, detalhes, escritas, arquivos, indicadores, exportações, portal e geração manual.
- Modelar concessões por dojo com vigência, permitindo conta operar mais de um dojo quando autorizado; `users.dojo_id` sozinho não representa essas concessões.
- Rotas operacionais devem explicitar o dojo; rotas antigas também recebem a nova autorização ou deixam de aceitar operações. Esconder menus ou redirecionar páginas não elimina acesso por URL/POST antigos.
- Professor padrão não recebe permissões administrativas associativas. Autorizar consulta de elenco/chamada somente de turmas/aulas para as quais esteja designado na data: principal, auxiliar ou substituto explicitamente habilitado. Reabertura, cancelamento e reagendamento são capacidades separadas, não incluídas automaticamente.
- Sem acesso padrão do professor ao financeiro, documentos pessoais completos, cadastro geral de pessoas ou concessão de papéis. Contatos emergenciais mínimos exigem regra específica.
- Professores do dojo são vínculos ao professor previamente cadastrado na associação, com início/fim/status; encerrar vínculo preserva autoria e histórico. O dojo não cria cadastro institucional nem emite convite de acesso. Não convidar novamente para cada turma.
- Campeonato é único: a relação dojo–campeonato representa disponibilidade/participação, não clone do evento. O dojo não altera taxa/regra central nem consulta inscrições de outro dojo. Preservar alçada de aprovação/resultados até decisão em contrário.
- Plano aplicado e cobrança precisam de origem de dojo explícita. Não derivar novas obrigações apenas do dojo principal atual se houver múltiplos vínculos; não reatribuir cobranças históricas numa transferência. Benefícios e aprovações mantêm separação de funções existente.
- Nos cadastros compartilhados, separar quem mantém identidade central de quem mantém o vínculo local. Um dojo não deve editar dados esportivos institucionais do professor nem ampliar acesso a familiares por compartilhar a mesma pessoa.

Aceite: dois dojos da mesma associação não acessam recursos um do outro por ID, URL, busca, relatório, arquivo ou rota antiga. Gestor associativo autorizado mantém visão consolidada. Professor não abre chamada alheia mesmo alterando a URL.

#### Aluno em vários dojos — decisão confirmada

- Permitir vínculos regulares simultâneos com vários dojos, mantendo um principal e os períodos de cada vínculo. Não duplicar pessoa ou perfil de aluno por dojo dentro da mesma associação.
- Distinguir identidade central, vínculo regular com dojo e matrícula em turma. Cada dojo mantém apenas sua operação; estar matriculado em dois dojos não concede a um gestor acesso aos dados operacionais do outro.
- A troca do principal não encerra automaticamente os vínculos secundários nem muda cobranças, inscrições ou presenças antigas. Regra de edição da identidade central deve constar da matriz por campo, sem usar o principal como prova de acesso a tudo.
- Planos/obrigações devem guardar seu dojo de origem explicitamente. A existência de outro vínculo regular não gera plano ou cobrança por si só; preservar regras de contratação, vigência e benefícios.
- Indicadores associativos contam alunos distintos; indicadores de cada dojo contam seus vínculos regulares. A soma de alunos dos dojos pode superar o total de pessoas da associação e isso deve ser explicado na interface.

#### Participação eventual como visitante — novo requisito R3-V

O usuário confirmou que aluno pode participar de aula em dojo com o qual não tem vínculo regular. **Visita não equivale a transferência, matrícula permanente ou permissão geral de acesso.**

- Na aula, oferecer “Adicionar visitante”, como ação separada de matricular aluno na turma. Proposta de alçada: gestor do dojo ou professor designado com permissão específica, validada no backend.
- Identificar o aluno por mecanismo controlado, como código/solicitação autenticada ou identificação confirmada por operador autorizado. Não liberar busca ampla de pessoas de outros dojos nem revelar CPF, contatos ou documentos ao digitar um nome.
- Criar autorização de participação restrita à aula, com aluno, dojo anfitrião, autor, instante e situação. Sem criar vínculo regular em `student_dojo_history` ou matrícula fictícia em `class_enrollments` para contornar a validação atual.
- Na chamada, distinguir “Matriculado” de “Visitante”. A presença do visitante exige autorização válida para aquela aula e as verificações de capacidade, faixa etária, modalidade e regras de responsável aplicáveis.
- O serviço de chamada deve aceitar aluno com matrícula regular vigente **ou** participação visitante válida. Apenas enviar `student_id` não prova nenhum desses acessos.
- Garantir no máximo uma participação/presença por aluno/aula. Se houver matrícula regular e visita cadastradas para a mesma aula, reconciliar sem duplicar a lista ou a frequência. Conclusão/fechamento continua auditável e sujeito às regras de alteração existentes.
- Visitante não atendido não deve ganhar falta automática em turmas onde não está matriculado. A proposta é tratar cancelamento/não comparecimento da visita separadamente da frequência regular. Presença efetiva aparece como visita no histórico do aluno e no relatório operacional do anfitrião, sem inflar alunos regularmente vinculados ao dojo.
- Autorizar somente os dados mínimos necessários à recepção e chamada. A visita não concede ao anfitrião consulta do financeiro de origem, documentos privados, responsáveis completos ou poder de editar o cadastro central.
- Encerrada/revogada a autorização, não permitir novas operações fora daquela participação; preservar consulta mínima do histórico da própria aula conforme papel e retenção, não uma autorização permanente sobre a pessoa.
- Incluir visita no portal/histórico do aluno e, quando autorizado, de responsáveis. Avisos usam preferências e vínculos verificados; visitar não inscreve responsáveis automaticamente em comunicações gerais do dojo anfitrião.
- A visita utiliza uma aula já existente e não cria outra ocorrência nem altera a meta de oito próximas aulas da turma.
- Decisão Q4 confirmada: aluno, aula e dojo anfitrião devem pertencer à mesma associação. Validar no backend; recusar visita entre associações sem revelar dados externos. Convite ou identificação do aluno não contorna essa restrição.
- Decisão Q5 confirmada: visita sempre gratuita. Não criar taxa avulsa, mensalidade, plano financeiro, pagamento ou cobrança de valor zero por causa da visita. Não criar configuração de preço para visitantes; o registro é esportivo/operacional, sem modificar obrigações financeiras existentes do aluno.

Aceite adicional: aluno regular em A e B visita gratuitamente aula de C, todos da mesma associação, sem transferência; anfitrião registra presença autorizada e não acessa dados privados de A/B; repetição não duplica participação; indicadores distinguem alunos regulares de visitantes; ID arbitrário e associação diferente são recusados; visita não cria qualquer lançamento financeiro.

### R4 — Documentos dentro dos cadastros

- Seção Documentos no cadastro/detalhe de aluno, professor e responsável; escolher o titular é desnecessário quando ele já é conhecido pelo contexto.
- Upload antes do primeiro salvamento usa área temporária privada, vinculada à sessão/usuário e com expiração. Na conclusão, vincular os arquivos ao cadastro correto; validar integridade e autorização novamente.
- Não existe transação distribuída entre banco e filesystem: usar staging, confirmação e compensação. Em falha, não declarar documento salvo; oferecer tentativa novamente e limpeza idempotente de temporários órfãos. Nunca remover arquivo que já faça parte de outro cadastro.
- Manter identidade da pessoa e vínculo explícito ao contexto: aluno, professor institucional ou responsável daquele aluno. Recomenda-se tabela de vínculos documentais com FKs e invariantes por tipo, sem substituir o armazenamento privado existente.
- Documento de responsável compartilhado com vários alunos não fica automaticamente visível em todos os dojos; compartilhamento entre vínculos deve ser explícito e autorizado. Um arquivo pode ser reutilizado por referência sem multiplicar cópias nem permissões.
- Tipo, validade, status e necessidade de documento são apresentados na ficha. Tipos obrigatórios por cadastro devem ser configuráveis; não inventar obrigações para todos os alunos/professores.
- Central de documentos permanece apenas como visão de gestão/conformidade, sempre filtrada pelo mesmo escopo. Downloads também revalidam o vínculo atual, inclusive rotas antigas e portal.
- Documentos antigos com titular/contexto ambíguo permanecem privados e entram em fila de revisão. Não inferir compartilhamento pelo `person_id` isoladamente.

Aceite: upload direto na ficha com vínculo correto; falha não deixa arquivo público nem vínculo cruzado; aluno/responsável/professor não herdam visibilidade indevida uns dos outros.

### R5 — Agenda semanal com vários dias

- Na turma, selecionar dias da semana por controles simples. Padrão: aplicar o mesmo horário aos dias escolhidos. Opção “Horários diferentes por dia” revela linhas individuais.
- Exemplo: segunda e quarta, 18h–19h; sexta, 17h–18h. Permitir professor/local específicos por horário quando necessário, dentro dos vínculos autorizados.
- Persistir uma linha versionada em `class_schedules` por combinação dia/horário. Não gravar lista textual nem substituir histórico existente.
- Validar ao menos um dia, término posterior ao início, duplicações e sobreposição dentro da turma. Verificar conflitos de professor; só bloquear conflito de espaço físico se o local tiver identificador confiável, pois texto livre de sala não prova identidade.
- Alterações têm data de vigência e uma prévia das próximas aulas afetadas. Preservar aulas já realizadas, com chamada registrada ou exceção manual. Aulas futuras geradas pela versão antiga devem ser reconciliadas, não deixadas silenciosamente com horário incorreto.
- Agenda versionada deve ser avaliada pela vigência, inclusive a parte ainda válida de uma versão substituída; filtrar apenas `status=active` pode omitir ocorrências antes da mudança futura.

Aceite: três dias geram sequências cronológicas corretas; horários diferentes funcionam; alteração futura não apaga presenças nem duplica ocorrências.

### R6 — Geração automática por quantidade

Decisão Q3 confirmada: **manter 8 próximas aulas por turma como padrão**, configurável para 4. A contagem é por turma, somando todos os dias, e não oito aulas por dia da semana. Duas aulas semanais produzem aproximadamente quatro semanas de agenda quando N=8. Aplicam-se os limites de vigência e disponibilidade de agenda descritos abaixo.

- Ao concluir cadastro de turma ativa com agenda válida, gerar o primeiro conjunto pelo serviço de domínio. Quantidade pequena permite geração inicial na mesma transação de turma/agenda; falha reverte a conclusão ou retorna estado explicitamente pendente, nunca sucesso fictício.
- Repor somente o necessário para completar N ocorrências futuras válidas. Não gerar novo lote inteiro a cada execução. Exemplo: restam seis futuras e N=8; criar apenas duas.
- Reposição pelo scheduler em lotes curtos, proposta horária, além de conclusão/alteração da turma e finalização/cancelamento/reagendamento de aula após commit. Leitura de uma página não deve ser a condição para manter a agenda.
- Considerar data/hora e timezone configurado; aula passada com chamada aberta continua como pendência, mas não bloqueia as próximas aulas. Não exigir finalização de chamadas antigas para repor agenda.
- Respeitar início/término da turma, vigência dos horários, pausas e exceções de calendário quando cadastradas. Proposta conservadora: não supor que feriado financeiro cancela aula; calendário de aulas é regra própria.
- Não gerar para associação/dojo/turma inativos. Reposição ordena todas as ocorrências candidatas, usa lock por turma e constraints; duas execuções não duplicam nem ultrapassam a meta por corrida.
- Aulas canceladas/reagendadas e exceções manuais são preservadas; não recriar automaticamente a ocorrência original. A reposição considera a aula substituta válida, sem contá-la duas vezes.
- N é meta de agenda futura, **não limite de histórico**: aulas realizadas, cancelamentos e presenças permanecem. Aulas futuras já existentes acima da nova meta não são apagadas; aguardar consumo ou oferecer revisão explicitamente autorizada.
- Reconciliar mudanças de agenda mediante revisão de aulas futuras intocadas; preservar origem da geração e exceções. A constraint atual de data/hora continua sendo analisada na definição do algoritmo para não bloquear ou ressuscitar ocorrências indevidas.
- Busca de candidatos tem limite temporal/de trabalho para evitar loop infinito quando não houver agenda válida. Se faltarem ocorrências antes do fim da turma, gerar menos que N e explicar, sem inventar datas.
- Comando manual “Atualizar próximas aulas” usa exatamente o mesmo serviço e permissões. Ajustar o comando antigo por intervalo para não contornar limite/agenda nem recriar ocorrências obsoletas inadvertidamente.
- Geração grava resultado operacional e eventos idempotentes após commit. Criar oito aulas não deve disparar oito e-mails de imediato: a proposta é preparar os eventos e emitir avisos acionáveis de cancelamento/alteração; lembretes de proximidade serão uma política explicitamente configurada.

Aceite: N=4/8, múltiplos dias, reexecução, concorrência, virada de dia, aula em atraso, encerramento da turma, cancelamento e mudança de agenda cobertos por testes.

### R7 — Importação assistida de alunos e presenças

A importação terá dois fluxos independentes. Importar alunos não registra presença; importar presença não cria pessoa, aluno, matrícula, turma ou aula implicitamente. Ambos usam arquivo privado, pré-visualização, confirmação explícita e relatório final em português simples.

- disponibilizar modelos oficiais em CSV UTF-8 e XLSX, com instruções, exemplos fictícios, campos obrigatórios e listas de valores aceitos;
- no fluxo de alunos, exigir associação e dojo de destino no contexto autorizado; permitir modalidade, graduação, turma, responsável e matrícula somente quando os recursos pertencerem ao mesmo escopo;
- normalizar CPF, e-mail, telefone, datas e números de matrícula. CPF existente deve entrar em revisão para reutilização autorizada, nunca gerar duplicação silenciosa ou substituir identidade central;
- separar linhas em `pronta`, `requer revisão`, `inválida` e `já processada`, mostrando número da linha, motivo e correção sugerida sem expor dados de outras associações/dojos;
- no fluxo de presenças, identificar aluno por matrícula e aula por identificador inequívoco ou combinação validada de turma/data/horário. O aluno precisa ter matrícula vigente ou visita já autorizada naquela aula;
- aceitar apenas estados canônicos de presença. Chamada fechada, aula cancelada, aluno externo ao escopo ou correspondência ambígua deve ser recusada; reabertura continua no fluxo próprio, com permissão e auditoria;
- persistir hash do arquivo, usuário, escopo, versão do template e chave por linha. Reenvio ou retomada não duplica cadastro, matrícula nem presença;
- confirmação cria registros por lotes curtos usando serviços de domínio e transações. Não exigir Redis, worker residente ou Docker; oferecer retomada segura por tela/comando compatível com cron e hospedagem compartilhada;
- guardar arquivos em área privada com prazo de retenção definido e descarte controlado. Auditoria registra operação, contagens e IDs técnicos, sem copiar CPF, contatos ou conteúdo integral da planilha;
- relatório final deve permitir baixar somente os erros e avisos, protegendo células contra fórmulas. Nenhuma linha rejeitada pode ser apresentada como importada.

Aceite: arquivo repetido não duplica dados; CPF e matrícula ambíguos exigem revisão; usuário de dojo não importa para outro escopo; chamada fechada não muda; presença não cria cadastro implícito; falha intermediária pode ser retomada; arquivos permanecem privados; testes SQLite/MySQL, Pint e homologação móvel são aprovados.

## 4. Sequência de implementação proposta

São sete incrementos aprovados após a Sprint 14. Não representam sete entregas já realizadas.

| Sprint proposta | Entrega | Dependência / aceite principal |
|---|---|---|
| 15 — Contexto e autorização | Matriz por perfil, contextos associação/dojo, concessões de escopo, vínculos institucionais/local de professor e separação de matrícula regular/visita | **Implementada**; acesso cruzado negado e visita não tratada como acesso geral |
| 16 — Cadastro direto e documentos | Formulários integrados de aluno/professor/responsável, vínculo regular em vários dojos, deduplicação autorizada, pré-cadastro/ativação, matrícula automática e upload contextual | **Implementada**; cadastro composto idempotente, documentos com vínculo explícito e escopo negativo testado |
| 17 — Convites e acesso do professor | Conta pendente, e-mail, aceite/verificação, senha, recuperação, reenvio/revogação e perfil mínimo de professor | **Implementada**; nenhum acesso ampliado antes do aceite e conta existente preservada |
| 18 — Turmas em vários dias e visitantes | Editor semanal, múltiplos horários, vigência, conflitos/exceções e participação visitante gratuita nas chamadas | **Implementada**; mesma associação, sem cobrança, histórico e chamadas preservados |
| 19 — Importação assistida | Modelos CSV/XLSX, prévia e revisão; importações separadas de alunos e presenças, idempotência, auditoria, relatório de erros e arquivos privados | **Implementada**; presença não cria cadastro, chamada fechada não muda e escopo é revalidado por associação/dojo |
| 20 — Próximas aulas automáticas | Geração inicial, padrão 8 ajustável a 4, reposição e reconciliação idempotentes, scheduler e observabilidade | **Implementada**; turma concluída já oferece 4/8 aulas para chamada |
| 21 — Consolidação da operação | Visões finais associação/dojo, professores e campeonatos compartilhados, inscrições, origem financeira local, migração assistida e homologação transversal | **Implementada tecnicamente**; aceite final de usuários e gates de produção permanecem externos |

As mudanças financeiras/de campeonatos da Sprint 21 não adiam a segurança: os endpoints existentes já devem estar isolados na Sprint 15. Até a tela final de um fluxo existir, o sistema deve negar operações incompatíveis, não conceder permissão associativa como contorno.

## 5. Impacto técnico e migração

- Preservar Laravel modular, Blade responsivo e cron; Docker/capturador de e-mail apenas opcionais em homologação. Não introduzir Redis ou worker residente.
- Módulos afetados: IdentityAccess, Organization/People, SportsRegistry, Classes/Attendance, Documents, Finance, Championship, Portal, Notifications/Reporting, Import e AuditOperations.
- Migrations incrementais propostas: concessões por dojo; vínculo professor–associação e professor–dojo; convites e controle de entrega; participação/disponibilidade dojo–campeonato; vínculos documentais; configuração de meta de aulas e metadados de geração/exceção; origem do dojo no vínculo financeiro quando necessário. Nomes e constraints finais devem ser fechados com o desenho técnico, não por criação indiscriminada de tabelas.
- Reutilizar `people`, `students`, `student_dojo_history`, `teacher_profiles`, `class_group_teachers`, `class_schedules`, `class_sessions`, `charges` e auditoria. Verificar sobreposição com estruturas existentes antes de criar cada tabela.
- Para visitantes, modelar participação/autorização por aluno e aula, com FK e unicidade, separada das matrículas regulares. Reaproveitar a presença em `attendance`, mantendo proveniência da participação e validando a mesma associação. Não criar novo perfil de aluno como atalho de escopo nem origem financeira de visitante: visita é gratuita por decisão confirmada.
- Inventariar dados antes do backfill. Inferir vínculos somente onde houver evidência inequívoca; professor em turma prova atuação naquela turma, não autorização administrativa no dojo inteiro. Não conceder papéis amplos por inferência.
- Não convidar em massa professores existentes ao migrar; apresentar seleção e confirmação de envio ao gestor. Aceites anteriores inexistentes não podem ser fabricados.
- Preservar IDs, senhas, arquivos, pagamentos, inscrições e referências históricas. Conflitos de pessoa/e-mail/dojo e documentos ambíguos vão para revisão. Nenhuma migração deve recriar o banco ou recalcular cobranças antigas.
- Comando de diagnóstico/migração com simulação, contagens e relatório sem dados pessoais; backfill em lotes idempotentes. Backup e restauração ensaiados antes de aplicar em dados de homologação relevantes.
- Implantação gradual com compatibilidade temporária; retirar telas/rotas antigas somente após conferir consumidores. Rollback de código precisa ser compatível com schema e dados; jamais apagar aceites/pagamentos para voltar uma release.
- Criar ADRs complementares para escopo operacional, convites, documentos contextualizados e janela de aulas. Atualizar mapa de modelos/rotas/permissões, contratos de serviços, exemplos de payload, estados, eventos, operação/recuperação, testes e changelog em cada incremento, para manutenção por outras equipes/ferramentas.

## 6. Homologação e critérios de conclusão

- Regressão SQLite e MySQL e Pint em cada incremento; casos de disputa/constraints no MySQL, incluindo duas gerações simultâneas e duas aceitações do mesmo convite.
- Matriz negativa com duas associações, dois dojos na mesma associação, gestor local, professor principal/auxiliar/substituto, aluno e responsável. Testar URL e escrita diretas, busca, CSV, dashboard, arquivos e rotas legadas.
- Adicionar terceiro dojo para casos de visita: múltiplos vínculos regulares, principal, acesso mínimo por aula, revogação, capacidade, dupla inclusão regular/visitante, fechamento de chamada e histórico. Visitante não vira matrícula nem infla o indicador de alunos regulares. Verificar ausência de qualquer criação financeira, inclusive em repetição/retentativa, e preservação de cobranças já existentes. Tentativa entre associações deve falhar sem persistir participação/presença.
- Fluxo completo: gestor entra no dojo → cadastra aluno/responsável/documento → cria turma em segunda/quarta → vincula professor aceito → visualiza oito aulas → registra chamada → verifica reposição, sem terminal.
- Convite em SMTP capturado: falha, retentativa, reenvio, expiração, recusa e aceite de conta nova/existente; não usar pessoas reais em automação.
- Verificação mobile e teclado: rótulos, ordem de foco, campos preservados, mensagens claras, nenhum seletor enorme de pessoas/graduações, dias identificáveis sem depender somente de cor.
- Ensaio de migração com cópia autorizada/mascarada ou dados sintéticos representativos; relatório antes/depois de IDs, vínculos, saldos, agendas e hashes de documentos.
- Teste de desempenho de cadastro composto e reposição por lote, não somente dashboard. Definir volume/concorrência e orçamento de tempo por lote antes do aceite operacional.
- Importação: arquivos com linhas válidas, inválidas, repetidas e ambíguas; dois dojos/associações; CPF já existente; matrícula ausente; aula cancelada; chamada aberta/fechada; retomada após falha; proteção contra fórmula e arquivo malformado. Homologar a prévia e o relatório com usuário de baixo conhecimento tecnológico.
- Aceite final com usuários de associação, dojo e professor, registrando tarefas concluídas e dificuldades. Essas melhorias não substituem MFA, governança de privacidade e demais gates de produção ainda abertos.

## 7. Decisões confirmadas e pendências

**Q1 — Confirmada:** aluno pode ser registrado em vários dojos simultaneamente, mantendo um principal, e participar eventualmente como visitante de aula em dojo sem vínculo regular. Distinguir cadastro, vínculo regular, matrícula em turma e participação visitante; um dojo não herda dados privados/financeiros de outro.

**Q2 — Confirmada:** o dojo somente seleciona professores previamente cadastrados na associação. Cadastro institucional e convite de acesso ficam na associação. Não adicionar um fluxo local de criação/convite nem aprovação extra obrigatória sem nova solicitação.

**Q3 — Confirmada:** oito próximas aulas por turma como padrão, configurável para quatro, com reposição automática conforme datas/eventos, não somente pelo fechamento da chamada.

**Q4 — Confirmada:** visitas somente entre dojos da mesma associação. Acesso e participação entre associações diferentes são bloqueados no backend.

**Q5 — Confirmada:** visitas sempre gratuitas. Não haverá taxa avulsa definida pelo dojo nem geração de plano/cobrança em decorrência da visita.

Outras hipóteses propostas para revisão: convite de 72 horas, vínculo a vários dojos do mesmo professor permitido, associação mantém aprovação central de inscrições/resultados e regras financeiras; apenas consulta necessária pelo dojo. Não inclui abrir novo cadastro de associação pelo público nem resolver operação da mesma conta em várias associações sem decisão específica. Conta já vinculada a outra associação não terá contexto sobrescrito automaticamente.

Q1–Q5 estão confirmadas. As Sprints 15–21 implementaram contexto operacional, cadastro composto, múltiplos vínculos regulares, documentos contextuais, convites seguros, agenda semanal, visitantes, importação assistida, reposição automática e consolidação local de financeiro/campeonatos. Os gates de produção continuam identificados.
