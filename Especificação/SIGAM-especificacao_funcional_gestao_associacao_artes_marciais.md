# Especificação Funcional --- Sistema de Gestão de Associação de Artes Marciais

**Versão:** 1.0\
**Status:** Especificação inicial para desenvolvimento\
**Objetivo do documento:** fornecer requisitos funcionais, regras de
negócio, entidades, fluxos, permissões e critérios de aceite com nível
de detalhamento suficiente para implementação assistida por Codex,
Claude Code ou equipe de desenvolvimento.

------------------------------------------------------------------------

## 1. Objetivo do Sistema

Desenvolver uma plataforma web responsiva para gestão administrativa,
esportiva, financeira e federativa de associações de artes marciais,
contemplando modalidades como judô, karatê, kung-fu, jiu-jitsu,
taekwondo e outras.

O sistema deve centralizar:

-   cadastro da associação;
-   cadastro e gestão de dojos/unidades;
-   cadastro central de pessoas;
-   gestão de alunos e responsáveis;
-   gestão de professores;
-   modalidades e graduações;
-   turmas e horários recorrentes;
-   geração e gestão de aulas;
-   controle de presença;
-   documentos;
-   planos, descontos e gratuidades;
-   cobranças e pagamentos;
-   federações e filiações;
-   campeonatos e inscrições;
-   histórico esportivo;
-   relatórios e dashboards;
-   usuários, perfis e permissões;
-   notificações;
-   auditoria.

------------------------------------------------------------------------

## 2. Princípios de Arquitetura Funcional

### 2.1 Pessoa como entidade central

O sistema não deve criar cadastros independentes e duplicados para
aluno, professor, responsável, árbitro ou administrador.

Deve existir uma entidade central `Pessoa`.

Uma pessoa poderá possuir um ou mais papéis simultaneamente:

-   aluno;
-   professor;
-   responsável;
-   responsável financeiro;
-   administrador;
-   gestor de dojo;
-   dirigente da associação;
-   árbitro;
-   auxiliar;
-   competidor.

Exemplo: um professor poderá também ser aluno e competir em campeonatos.

### 2.2 Separação entre cadastro atual e histórico

Informações históricas não devem ser sobrescritas.

Mudanças de:

-   dojo;
-   turma;
-   graduação;
-   federação;
-   plano;
-   desconto;
-   gratuidade;
-   professor;
-   situação do aluno;

devem manter histórico sempre que forem relevantes para rastreabilidade.

### 2.3 Fonte única de informação

Dados gerados por um módulo devem ser reutilizados por outros módulos
sem duplicação.

Exemplo: a participação em campeonato deve ser registrada no módulo de
campeonatos e exibida no histórico esportivo do aluno. Não deve existir
uma segunda cópia manual da mesma participação.

### 2.4 Motor financeiro único

Mensalidades, inscrições em campeonatos, exames de graduação e outras
cobranças futuras devem utilizar a mesma estrutura de:

`Origem -> Cobrança -> Pagamento -> Baixa`

------------------------------------------------------------------------

## 3. Estrutura Organizacional

Estrutura principal:

`Associação -> Dojos -> Modalidades -> Turmas -> Aulas -> Alunos`

Relacionamentos complementares:

`Pessoa -> Aluno -> Graduações -> Federações -> Campeonatos -> Histórico Esportivo`

`Aluno -> Plano/Benefício -> Cobranças -> Pagamentos`

------------------------------------------------------------------------

# 4. Módulo Associação

## 4.1 Objetivo

Manter os dados institucionais da associação responsável pelos dojos.

## 4.2 Dados mínimos

-   ID;
-   razão social;
-   nome fantasia;
-   CNPJ;
-   data de fundação;
-   endereço completo;
-   CEP;
-   município;
-   UF;
-   país;
-   telefone;
-   e-mail;
-   site;
-   logotipo;
-   situação;
-   observações.

## 4.3 Responsáveis

Permitir relacionar pessoas à associação com funções como:

-   presidente;
-   vice-presidente;
-   diretor técnico;
-   tesoureiro;
-   secretário;
-   responsável administrativo;
-   outros.

O relacionamento deve conter período de vigência.

## 4.4 Regras

-   CNPJ, quando informado, deve ser validado.
-   Uma associação inativa não deve aceitar novos vínculos operacionais.
-   Dados históricos não devem ser apagados quando a associação for
    inativada.

------------------------------------------------------------------------

# 5. Módulo Dojos

## 5.1 Objetivo

Gerenciar unidades, academias ou dojos vinculados à associação.

## 5.2 Dados

-   ID;
-   associação;
-   código;
-   nome;
-   endereço;
-   contatos;
-   responsável administrativo;
-   responsável técnico;
-   modalidades oferecidas;
-   capacidade quando aplicável;
-   data de início;
-   situação;
-   observações.

## 5.3 Regras

-   todo dojo deve pertencer a uma associação;
-   um dojo pode possuir múltiplas modalidades;
-   um dojo pode possuir vários professores;
-   deve ser possível inativar o dojo sem apagar histórico;
-   alunos, turmas, aulas e pagamentos históricos devem continuar
    consultáveis.

------------------------------------------------------------------------

# 6. Módulo Pessoas

## 6.1 Cadastro

Campos recomendados:

-   nome completo;
-   nome social;
-   CPF;
-   RG/documento;
-   órgão emissor;
-   data de nascimento;
-   telefone;
-   e-mail;
-   endereço;
-   foto;
-   contato de emergência;
-   observações;
-   situação.

## 6.2 Papéis

A mesma pessoa poderá receber múltiplos papéis.

Os papéis não devem necessariamente conceder acesso ao sistema. O acesso
dependerá de uma conta de usuário e permissões.

------------------------------------------------------------------------

# 7. Módulo Alunos

## 7.1 Cadastro esportivo

Relacionar `Pessoa` a um registro de aluno.

Dados:

-   matrícula/código;
-   data de ingresso;
-   associação;
-   dojo principal;
-   modalidade;
-   professor de referência, quando aplicável;
-   graduação atual;
-   situação;
-   data de desligamento;
-   motivo de desligamento;
-   observações.

## 7.2 Situações

No mínimo:

-   pré-cadastro;
-   ativo;
-   suspenso;
-   inativo;
-   transferido;
-   desligado.

## 7.3 Vínculos

O modelo de dados deve ser preparado para permitir:

-   histórico de dojos;
-   múltiplas modalidades;
-   múltiplas turmas;
-   histórico de graduações;
-   histórico federativo;
-   histórico financeiro;
-   histórico esportivo.

------------------------------------------------------------------------

# 8. Responsáveis por Menores

## 8.1 Regra

Alunos menores de idade devem possuir pelo menos um responsável
cadastrado conforme regra configurável da associação.

## 8.2 Tipos de vínculo

-   pai;
-   mãe;
-   tutor;
-   responsável legal;
-   responsável financeiro;
-   contato de emergência;
-   outro.

## 8.3 Regras

-   um responsável poderá estar relacionado a vários alunos;
-   um aluno poderá possuir vários responsáveis;
-   deve ser possível indicar responsável principal;
-   responsável legal e financeiro podem ser pessoas diferentes.

------------------------------------------------------------------------

# 9. Gestão Documental

## 9.1 Tipos

Exemplos:

-   identidade;
-   CPF;
-   comprovante de endereço;
-   documento do responsável;
-   autorização para menor;
-   atestado;
-   carteira federativa;
-   certificado;
-   outros.

## 9.2 Metadados

Cada documento deve possuir:

-   pessoa/aluno relacionado;
-   tipo;
-   arquivo;
-   data de emissão;
-   validade;
-   data de inclusão;
-   usuário responsável;
-   observações;
-   status.

## 9.3 Requisitos

-   controle de acesso aos arquivos;
-   registro de upload;
-   registro de exclusão lógica;
-   suporte a vencimento;
-   possibilidade futura de notificação de vencimento.

------------------------------------------------------------------------

# 10. Modalidades

O sistema deve possuir cadastro configurável de modalidades.

Exemplos:

-   Judô;
-   Karatê;
-   Kung-Fu;
-   Jiu-Jitsu;
-   Taekwondo.

Campos:

-   nome;
-   descrição;
-   situação;
-   estrutura de graduação associada.

Não implementar graduações como valores fixos no código.

------------------------------------------------------------------------

# 11. Graduações

## 11.1 Cadastro

Cada modalidade deve possuir estrutura própria de graduações.

Campos:

-   modalidade;
-   nome;
-   ordem;
-   cor/faixa quando aplicável;
-   idade mínima opcional;
-   tempo mínimo opcional;
-   situação.

## 11.2 Histórico do aluno

Registrar:

-   aluno;
-   modalidade;
-   graduação;
-   data;
-   professor/responsável;
-   origem;
-   exame/evento relacionado;
-   observações.

Uma nova graduação não deve apagar a anterior.

------------------------------------------------------------------------

# 12. Professores

O professor deve ser uma Pessoa com papel de professor.

Informações adicionais poderão incluir:

-   modalidades habilitadas;
-   graduação;
-   registro federativo;
-   certificações;
-   validade de credenciamento;
-   dojos vinculados;
-   situação.

Um professor pode atuar em múltiplos dojos e turmas.

------------------------------------------------------------------------

# 13. Turmas

## 13.1 Conceito

`Turma` representa a programação recorrente.

Exemplo:

-   Judô Infantil A
-   segunda e quarta
-   18:00 às 19:00
-   crianças de 7 a 11 anos.

## 13.2 Campos

-   associação;
-   dojo;
-   modalidade;
-   nome;
-   descrição;
-   professor principal;
-   professores auxiliares;
-   capacidade;
-   classificação etária;
-   idade mínima;
-   idade máxima;
-   data inicial;
-   data final opcional;
-   situação.

## 13.3 Recorrência

Permitir um ou mais horários por turma:

-   dia da semana;
-   horário inicial;
-   horário final;
-   professor responsável;
-   local/sala/tatame opcional.

------------------------------------------------------------------------

# 14. Aulas

## 14.1 Conceito

`Aula` é uma ocorrência concreta de uma turma.

Exemplo:

`Turma Judô Infantil A -> Aula 24/08/2026 18:00`

## 14.2 Geração

O sistema deverá gerar aulas a partir da recorrência da turma.

## 14.3 Dados

-   turma;
-   data;
-   início;
-   fim;
-   professor previsto;
-   professor efetivo;
-   situação;
-   observações.

## 14.4 Situações

-   prevista;
-   realizada;
-   cancelada;
-   reagendada.

Alterações futuras na recorrência da turma não devem modificar aulas
históricas realizadas.

------------------------------------------------------------------------

# 15. Matrícula em Turmas

Deve existir relacionamento entre aluno e turma.

Campos:

-   aluno;
-   turma;
-   data inicial;
-   data final;
-   situação;
-   motivo de saída.

Um aluno poderá participar de múltiplas turmas quando permitido.

------------------------------------------------------------------------

# 16. Controle de Presença

## 16.1 Fluxo

Professor acessa:

`Minhas aulas -> Aula -> Lista de alunos -> Chamada`

## 16.2 Estados

-   presente;
-   ausente;
-   ausência justificada;
-   reposição;
-   aula experimental, quando aplicável.

## 16.3 Registro

Guardar:

-   aula;
-   aluno;
-   status;
-   horário do registro;
-   usuário;
-   observação.

## 16.4 Relatórios

Permitir consulta por:

-   período;
-   aluno;
-   turma;
-   professor;
-   dojo;
-   modalidade.

Indicadores:

-   quantidade de aulas;
-   presenças;
-   faltas;
-   faltas justificadas;
-   percentual de frequência;
-   faltas consecutivas;
-   alunos sem comparecimento há X dias.

------------------------------------------------------------------------

# 17. Planos Financeiros

## 17.1 Cadastro

Campos:

-   nome;
-   descrição;
-   associação;
-   dojo opcional;
-   modalidade opcional;
-   periodicidade;
-   valor;
-   vigência;
-   situação.

Periodicidades iniciais:

-   mensal;
-   semestral;
-   anual;
-   personalizada.

## 17.2 Regra

Plano é um modelo financeiro e não deve conter diretamente o histórico
de pagamentos.

------------------------------------------------------------------------

# 18. Plano do Aluno

Relacionamento entre aluno e plano.

Campos:

-   aluno;
-   plano;
-   início;
-   término;
-   valor base;
-   dia preferencial de vencimento;
-   situação.

O valor base deve manter referência ao plano, mas condições específicas
devem ser registradas separadamente.

------------------------------------------------------------------------

# 19. Descontos

## 19.1 Dados

-   aluno;
-   tipo: percentual ou valor;
-   valor;
-   motivo;
-   início;
-   término;
-   solicitante;
-   aprovador quando necessário;
-   situação.

## 19.2 Regras

-   descontos devem possuir vigência;
-   alteração deve ser auditada;
-   desconto encerrado não deve alterar cobranças históricas;
-   regras de aprovação poderão ser configuráveis futuramente.

------------------------------------------------------------------------

# 20. Gratuidades

## 20.1 Conceito

Gratuidade deve ser uma concessão formal, e não simplesmente um plano
com valor zero alterado manualmente.

## 20.2 Fluxo padrão

`Solicitação -> Aprovação Dojo -> Aprovação Associação -> Ativação`

## 20.3 Solicitantes

-   professor;
-   administrativo;
-   gestor autorizado.

## 20.4 Dados

-   aluno;
-   motivo;
-   justificativa;
-   documentos opcionais;
-   data inicial;
-   data final;
-   solicitante;
-   responsável do dojo;
-   decisão do dojo;
-   responsável da associação;
-   decisão da associação;
-   observações;
-   situação.

## 20.5 Situações

-   rascunho;
-   solicitada;
-   aguardando dojo;
-   aguardando associação;
-   aprovada;
-   recusada;
-   cancelada;
-   encerrada.

## 20.6 Regra obrigatória

Uma gratuidade somente se torna válida após aprovação do responsável do
dojo e do responsável da associação.

------------------------------------------------------------------------

# 21. Cobranças

## 21.1 Entidade

Toda obrigação financeira deve gerar uma cobrança.

Campos:

-   aluno/pessoa;
-   origem;
-   ID da origem;
-   descrição;
-   competência;
-   emissão;
-   vencimento;
-   valor original;
-   desconto;
-   valor final;
-   saldo;
-   status.

## 21.2 Origens iniciais

-   mensalidade;
-   campeonato;
-   exame de graduação;
-   federação;
-   outros.

## 21.3 Estados

-   em aberto;
-   parcialmente pago;
-   pago;
-   vencido;
-   cancelado;
-   isento.

------------------------------------------------------------------------

# 22. Pagamentos e Baixas

## 22.1 Dados

-   cobrança;
-   data do pagamento;
-   data da baixa;
-   valor;
-   método;
-   referência/comprovante;
-   observação;
-   usuário responsável.

## 22.2 Métodos iniciais

-   PIX;
-   dinheiro;
-   cartão;
-   transferência;
-   boleto;
-   outro.

## 22.3 Regras

-   permitir pagamento parcial;
-   impedir baixa superior ao saldo sem tratamento explícito;
-   toda baixa deve possuir usuário e timestamp;
-   estorno/cancelamento deve manter histórico;
-   não excluir fisicamente pagamentos auditáveis.

------------------------------------------------------------------------

# 23. Relatórios Financeiros

Implementar filtros por associação, dojo e período.

Relatórios mínimos:

-   contas a receber;
-   vencimentos;
-   inadimplência;
-   pagamentos recebidos;
-   receita prevista;
-   receita realizada;
-   descontos;
-   gratuidades;
-   receita por dojo;
-   receita por modalidade;
-   extrato financeiro do aluno;
-   inscrições de campeonato pendentes/pagas.

Exportações desejáveis:

-   CSV;
-   XLSX;
-   PDF.

------------------------------------------------------------------------

# 24. Federações

## 24.1 Cadastro

Campos:

-   nome;
-   sigla;
-   tipo;
-   estado;
-   país;
-   contatos;
-   site;
-   situação.

------------------------------------------------------------------------

# 25. Filiação Federativa

## 25.1 Relacionamento

`Aluno -> Federação -> Período/Ano`

## 25.2 Dados

-   aluno;
-   federação;
-   número federativo;
-   ano/período;
-   data de inscrição;
-   validade;
-   graduação registrada;
-   situação;
-   pontuação quando aplicável;
-   documentos;
-   observações.

## 25.3 Histórico

Nunca sobrescrever filiações de anos anteriores.

------------------------------------------------------------------------

# 26. Campeonatos

## 26.1 Cadastro

Campos:

-   nome;
-   descrição;
-   modalidade;
-   tipo;
-   organizador;
-   associação/dojo responsável;
-   local;
-   data inicial;
-   data final;
-   abertura das inscrições;
-   encerramento das inscrições;
-   regulamento;
-   custo;
-   status.

## 26.2 Tipos

-   interno;
-   amistoso;
-   federado;
-   oficial;
-   externo.

## 26.3 Formas de participação

O campeonato deverá definir quais opções aceita:

-   competidor;
-   árbitro;
-   auxiliar.

------------------------------------------------------------------------

# 27. Inscrições em Campeonatos

## 27.1 Fluxo

`Campeonato -> Inscrição -> Validação/Aprovação -> Cobrança -> Pagamento -> Participação -> Resultado`

## 27.2 Dados comuns

-   campeonato;
-   pessoa/aluno;
-   tipo de participação;
-   dojo;
-   data;
-   status;
-   observações.

## 27.3 Competidor

Campos adicionais:

-   categoria;
-   idade;
-   peso;
-   graduação;
-   equipe/dojo.

## 27.4 Árbitro

Campos opcionais:

-   nível;
-   certificação;
-   federação.

## 27.5 Auxiliar

Campo:

-   função.

## 27.6 Financeiro

Quando houver custo, a inscrição deve gerar uma cobrança no motor
financeiro geral.

------------------------------------------------------------------------

# 28. Resultados de Campeonatos

Para competidores permitir:

-   participação confirmada;
-   colocação;
-   medalha;
-   pontuação;
-   WO;
-   desclassificação;
-   observações.

Para árbitros e auxiliares:

-   participação confirmada;
-   função;
-   observações.

Os resultados devem alimentar automaticamente o histórico esportivo.

------------------------------------------------------------------------

# 29. Histórico Esportivo

A tela do aluno deve consolidar, sem duplicar dados:

-   graduações;
-   filiações;
-   campeonatos;
-   resultados;
-   medalhas;
-   pontuações;
-   arbitragens;
-   participações como auxiliar.

Filtros por período e modalidade.

------------------------------------------------------------------------

# 30. Exames de Graduação

O modelo deve prever este módulo, mesmo que sua implementação possa ser
fase posterior.

Entidades previstas:

-   exame;
-   modalidade;
-   graduação pretendida;
-   candidatos;
-   avaliadores;
-   inscrição;
-   cobrança;
-   resultado;
-   nova graduação.

Fluxo:

`Exame -> Inscrição -> Pagamento opcional -> Avaliação -> Resultado -> Graduação`

------------------------------------------------------------------------

# 31. Usuários, Perfis e Permissões

## 31.1 Perfis iniciais

-   Administrador do Sistema;
-   Gestor da Associação;
-   Financeiro da Associação;
-   Responsável do Dojo;
-   Administrativo do Dojo;
-   Professor;
-   Aluno;
-   Responsável.

## 31.2 Princípio

Implementar RBAC com permissões granulares.

Exemplos:

-   `aluno.visualizar`;
-   `aluno.editar`;
-   `presenca.registrar`;
-   `financeiro.visualizar`;
-   `financeiro.baixar`;
-   `gratuidade.solicitar`;
-   `gratuidade.aprovar_dojo`;
-   `gratuidade.aprovar_associacao`;
-   `campeonato.criar`;
-   `campeonato.inscrever`;
-   `documento.visualizar`.

## 31.3 Escopo

Permissões devem considerar escopo:

-   sistema;
-   associação;
-   dojo;
-   próprio usuário/dependentes;
-   turmas do professor.

------------------------------------------------------------------------

# 32. Portal do Aluno e Responsável

Prever interface para:

-   atualizar dados permitidos;
-   visualizar turmas;
-   visualizar frequência;
-   visualizar financeiro;
-   consultar pagamentos;
-   consultar documentos;
-   visualizar graduação;
-   consultar filiação;
-   visualizar histórico esportivo;
-   consultar campeonatos;
-   realizar inscrição quando permitido.

Responsável deve visualizar somente dependentes autorizados.

------------------------------------------------------------------------

# 33. Dashboard

## 33.1 Associação

Indicadores:

-   alunos ativos;
-   novos alunos;
-   desligamentos;
-   alunos por dojo;
-   alunos por modalidade;
-   frequência média;
-   inadimplência;
-   receita prevista;
-   receita recebida;
-   descontos;
-   gratuidades;
-   filiações;
-   inscrições em campeonatos.

## 33.2 Dojo

Mesmos indicadores limitados ao dojo.

## 33.3 Professor

-   aulas de hoje;
-   próximas aulas;
-   chamadas pendentes;
-   alunos por turma;
-   faltas recorrentes;
-   graduações;
-   campeonatos disponíveis.

------------------------------------------------------------------------

# 34. Notificações

Criar arquitetura de notificações desacoplada do canal.

Eventos iniciais:

-   cobrança próxima do vencimento;
-   cobrança vencida;
-   documento vencendo;
-   filiação vencendo;
-   campeonato aberto;
-   prazo de inscrição próximo;
-   gratuidade aguardando aprovação;
-   aula cancelada;
-   aluno com faltas consecutivas.

Canais iniciais:

-   notificação interna;
-   e-mail.

Preparar arquitetura para futura integração com WhatsApp.

------------------------------------------------------------------------

# 35. Auditoria

## 35.1 Eventos obrigatórios

Auditar:

-   login e eventos relevantes de segurança;
-   alteração cadastral sensível;
-   upload/exclusão de documento;
-   alteração de plano;
-   desconto;
-   gratuidade;
-   cobrança;
-   baixa;
-   estorno;
-   graduação;
-   filiação;
-   inscrição em campeonato;
-   resultado;
-   alteração de permissões.

## 35.2 Estrutura

Registrar:

-   timestamp;
-   usuário;
-   ação;
-   entidade;
-   ID;
-   valores anteriores quando aplicável;
-   valores posteriores;
-   IP quando disponível;
-   contexto.

Logs de auditoria não devem ser editáveis por usuários comuns.

------------------------------------------------------------------------

# 36. LGPD e Segurança

Requisitos mínimos:

-   autenticação segura;
-   senhas armazenadas com hash seguro;
-   autorização server-side;
-   RBAC;
-   isolamento por associação/dojo;
-   proteção contra acesso direto indevido a arquivos;
-   logs;
-   trilha de auditoria;
-   criptografia em trânsito;
-   backup;
-   política de retenção;
-   exclusão lógica quando histórico legal/financeiro precisar ser
    preservado;
-   MFA preparado para perfis administrativos;
-   proteção de dados de menores.

Nunca confiar apenas em validação da interface.

Toda autorização deve ser verificada no backend.

------------------------------------------------------------------------

# 37. Requisitos de Interface

A aplicação deve ser web responsiva.

Priorizar uso em celular para:

-   chamada;
-   consulta de turmas;
-   inscrições;
-   portal do aluno/responsável.

Listagens administrativas devem possuir:

-   busca;
-   filtros;
-   paginação;
-   ordenação;
-   exportação quando aplicável.

Evitar exclusão física como ação padrão.

------------------------------------------------------------------------

# 38. Regras Transversais

## RN-001

Toda entidade principal deve possuir ID único.

## RN-002

Registros históricos relevantes devem usar inativação ou exclusão
lógica.

## RN-003

Valores monetários devem usar tipo decimal apropriado. Não utilizar
`float`.

## RN-004

Datas e horários devem ser armazenados de forma consistente e exibidos
no fuso configurado.

## RN-005

Toda alteração financeira relevante deve ser auditada.

## RN-006

Documentos privados nunca devem ser expostos por URL pública permanente.

## RN-007

Uma pessoa pode possuir vários papéis.

## RN-008

Uma conta de usuário deve estar relacionada a uma Pessoa quando
representar pessoa física.

## RN-009

Permissão deve ser validada no backend.

## RN-010

Relatórios devem respeitar o escopo de acesso do usuário.

------------------------------------------------------------------------

# 39. Entidades Principais

Modelo conceitual mínimo:

``` text
Association
Person
AssociationPersonRole
Dojo
DojoPersonRole
User
Role
Permission
UserRole
MartialArt
Rank
Student
StudentGuardian
StudentDojoHistory
StudentMartialArt
TeacherProfile
TeacherMartialArt
ClassGroup
ClassSchedule
ClassEnrollment
ClassSession
Attendance
Document
FinancialPlan
StudentPlan
Discount
GratuityRequest
Charge
Payment
Federation
FederationMembership
Championship
ChampionshipRegistration
ChampionshipResult
GraduationExam
GraduationExamRegistration
StudentRankHistory
Notification
AuditLog
```

------------------------------------------------------------------------

# 40. Relacionamentos Conceituais

``` text
Association 1:N Dojo
Association N:N Person

Person 1:0..1 Student
Person 1:0..1 TeacherProfile
Person 1:N User (preferencialmente 1:1 na primeira versão)

Student N:N Dojo (histórico/vínculo)
Student N:N MartialArt

Dojo 1:N ClassGroup
ClassGroup 1:N ClassSchedule
ClassGroup 1:N ClassSession
Student N:N ClassGroup via ClassEnrollment
ClassSession N:N Student via Attendance

Student 1:N StudentPlan
Student 1:N Discount
Student 1:N GratuityRequest
Student 1:N Charge
Charge 1:N Payment

Student N:N Federation via FederationMembership

Championship 1:N ChampionshipRegistration
Person 1:N ChampionshipRegistration
ChampionshipRegistration 0..N Charge
ChampionshipRegistration 0..1 ChampionshipResult

MartialArt 1:N Rank
Student 1:N StudentRankHistory
```

------------------------------------------------------------------------

# 41. Fluxos Prioritários para Implementação

## Fluxo A --- Cadastro de aluno

``` text
Criar/Localizar Pessoa
        |
Definir papel Aluno
        |
Cadastrar dados esportivos
        |
Menor?
 |            |
Sim          Não
 |
Cadastrar/Vincular Responsável
        |
Anexar documentos
        |
Selecionar Dojo
        |
Selecionar Modalidade
        |
Matricular em Turma
        |
Selecionar Plano
        |
Configurar desconto/vencimento
        |
Ativar aluno
```

## Fluxo B --- Presença

``` text
Turma
  |
Agenda recorrente
  |
Aula
  |
Professor abre chamada
  |
Registra presença
  |
Finaliza chamada
  |
Indicadores/Relatórios
```

## Fluxo C --- Gratuidade

``` text
Solicitação
  |
Responsável do Dojo
  |
Aprova?
 |     |
Não   Sim
 |     |
Fim   Associação
         |
       Aprova?
       |    |
      Não  Sim
       |    |
      Fim  Ativar benefício
```

## Fluxo D --- Mensalidade

``` text
Plano
  |
Plano do aluno
  |
Desconto/Gratuidade
  |
Gerar cobrança
  |
Vencimento
  |
Pagamento
  |
Baixa
  |
Relatórios
```

## Fluxo E --- Campeonato

``` text
Criar campeonato
       |
Abrir inscrições
       |
Aluno/Pessoa se inscreve
       |
Validar participação
       |
Há custo?
 |          |
Não        Sim
 |          |
 |       Gerar cobrança
 |          |
 |       Pagamento
 |__________|
       |
Confirmar participação
       |
Registrar resultado
       |
Atualizar histórico esportivo
```

------------------------------------------------------------------------

# 42. Relatórios Mínimos da Primeira Versão

### Cadastros

-   alunos ativos/inativos;
-   alunos por dojo;
-   alunos por modalidade;
-   alunos por turma;
-   aniversariantes;
-   responsáveis;
-   professores.

### Presença

-   frequência individual;
-   frequência por turma;
-   frequência por dojo;
-   faltas consecutivas;
-   alunos sem presença há X dias.

### Financeiro

-   cobranças abertas;
-   inadimplência;
-   recebimentos;
-   extrato do aluno;
-   descontos;
-   gratuidades;
-   receita por dojo;
-   receita por modalidade.

### Federação

-   filiados por federação;
-   filiações por ano;
-   filiações vencendo;
-   graduação federativa.

### Campeonato

-   inscritos;
-   inscritos por dojo;
-   inscritos por função;
-   pagamentos;
-   resultados;
-   medalhas/pontuação.

------------------------------------------------------------------------

# 43. Critérios Gerais de Aceite

Uma funcionalidade somente deve ser considerada concluída quando:

1.  persistir corretamente os dados;
2.  validar campos obrigatórios no backend;
3.  validar autorização no backend;
4.  respeitar escopo do usuário;
5.  possuir tratamento de erros;
6.  registrar auditoria quando aplicável;
7.  possuir interface responsiva;
8.  não destruir histórico relevante;
9.  possuir testes para regras críticas;
10. possuir migrations/versionamento de banco;
11. possuir documentação mínima da API;
12. não expor documentos ou dados pessoais indevidamente.

------------------------------------------------------------------------

# 44. Estratégia Recomendada de Desenvolvimento

## Fase 1 --- Fundação

-   autenticação;
-   usuários;
-   RBAC;
-   associação;
-   dojos;
-   pessoas;
-   modalidades;
-   graduações;
-   auditoria básica.

## Fase 2 --- Operação esportiva

-   alunos;
-   responsáveis;
-   professores;
-   documentos;
-   turmas;
-   horários;
-   aulas;
-   matrícula;
-   presença;
-   relatórios de frequência.

## Fase 3 --- Financeiro

-   planos;
-   plano do aluno;
-   descontos;
-   gratuidades;
-   cobranças;
-   pagamentos;
-   baixa;
-   relatórios.

## Fase 4 --- Federação

-   federações;
-   filiações;
-   histórico federativo;
-   documentos federativos.

## Fase 5 --- Campeonatos

-   cadastro;
-   inscrições;
-   participantes;
-   cobrança;
-   resultados;
-   integração com histórico esportivo.

## Fase 6 --- Portal e automações

-   portal do aluno;
-   portal do responsável;
-   dashboards;
-   notificações;
-   documentos gerados;
-   integrações.

## Fase 7 --- Evoluções

-   exames de graduação completos;
-   PIX/gateway;
-   WhatsApp;
-   PWA;
-   APIs externas;
-   integração com federações.

------------------------------------------------------------------------

# 45. Decisões que Devem Permanecer Configuráveis

Não codificar como regras fixas:

-   modalidades;
-   graduações;
-   faixas etárias;
-   periodicidades adicionais;
-   dias preferenciais de vencimento;
-   tipos de documentos;
-   funções administrativas;
-   categorias de campeonato;
-   formas de participação;
-   formas de pagamento;
-   regras futuras de aprovação;
-   textos de notificações.

------------------------------------------------------------------------

# 46. Pendências Funcionais a Confirmar

A implementação deve deixar os pontos abaixo parametrizáveis ou aguardar
decisão de produto:

1.  suporte a uma ou múltiplas associações na mesma instalação;
2.  aluno simultaneamente em múltiplos dojos;
3.  aluno em múltiplas modalidades;
4.  fluxo completo de exames de graduação na V1;
5.  cobrança e repasse financeiro Associação x Dojo;
6.  integração PIX/boleto/gateway;
7.  login de aluno/responsável na V1;
8.  aprovação do professor para competição;
9.  cálculo automático de categoria de campeonato;
10. ranking esportivo;
11. descontos familiares;
12. geração de recibos e declarações;
13. fluxo de matrícula/aprovação;
14. gestão de aulas experimentais/prospects;
15. PWA desde a primeira versão.

O código não deve assumir respostas irreversíveis para essas questões.

------------------------------------------------------------------------

# 47. Diretrizes para Codex / Claude Code

Ao implementar:

1.  não criar todo o sistema em uma única entrega;
2.  implementar por módulos e migrations incrementais;
3.  apresentar o plano técnico antes de alterações estruturais grandes;
4.  manter domínio, regras de negócio e persistência desacoplados quando
    a stack permitir;
5.  centralizar autorização;
6.  centralizar auditoria;
7.  centralizar armazenamento de documentos;
8.  centralizar motor de cobranças;
9.  evitar regras de modalidades hardcoded;
10. usar enums apenas para estados realmente controlados pelo sistema;
11. utilizar tabelas configuráveis para classificações que possam
    evoluir;
12. implementar soft delete quando apropriado;
13. usar transações nas operações financeiras e aprovações críticas;
14. prevenir dupla baixa e processamento duplicado;
15. garantir idempotência em geração recorrente de aulas e cobranças;
16. criar índices para FKs, datas e campos usados em filtros;
17. implementar paginação server-side;
18. proteger endpoints contra IDOR;
19. não retornar dados pessoais desnecessários nas APIs;
20. criar testes automatizados das regras financeiras, presença e
    aprovações.

------------------------------------------------------------------------

# 48. Requisitos de Idempotência

## Geração de aulas

Executar duas vezes a rotina de geração não pode gerar duas ocorrências
para:

`turma + agenda + data + horário`

## Geração de mensalidades

Executar novamente a rotina não pode duplicar:

`aluno + origem + competência`

## Inscrição em campeonato

A mesma pessoa não deve possuir inscrição duplicada para a mesma
combinação de campeonato e papel, salvo regra explícita.

## Pagamento

Endpoints de baixa devem possuir proteção contra reenvio acidental.

------------------------------------------------------------------------

# 49. Requisitos de Integridade Financeira

-   pagamentos não devem ser apagados fisicamente;
-   estorno deve gerar evento auditável;
-   cobrança paga não deve voltar para aberto por edição direta;
-   valor original da cobrança deve permanecer registrado;
-   desconto aplicado deve permanecer identificável;
-   gratuidade deve indicar a autorização que originou a isenção;
-   alteração posterior de plano não pode modificar cobranças fechadas;
-   total pago deve ser derivável dos pagamentos válidos;
-   saldo deve ser consistente com cobrança e pagamentos.

------------------------------------------------------------------------

# 50. Definição de MVP Recomendada

O MVP operacional deverá permitir o ciclo:

``` text
Cadastrar Associação
      ↓
Cadastrar Dojo
      ↓
Cadastrar Pessoas
      ↓
Cadastrar Alunos/Responsáveis
      ↓
Cadastrar Professores
      ↓
Criar Modalidades/Graduações
      ↓
Criar Turmas/Horários
      ↓
Matricular Alunos
      ↓
Gerar Aulas
      ↓
Registrar Presença
      ↓
Cadastrar Plano
      ↓
Vincular Plano ao Aluno
      ↓
Gerar Cobranças
      ↓
Registrar Pagamentos
      ↓
Consultar Relatórios
```

Federações e campeonatos podem ser desenvolvidos imediatamente após a
estabilização desse núcleo.

------------------------------------------------------------------------

# 51. Resultado Esperado

Ao final, a plataforma deve oferecer uma visão integrada da vida
administrativa e esportiva do praticante:

``` text
PESSOA
  |
  +-- Dados cadastrais
  +-- Responsáveis
  +-- Documentos
  |
ALUNO
  |
  +-- Associação
  +-- Dojo
  +-- Modalidade
  +-- Turmas
  +-- Frequência
  +-- Graduações
  +-- Financeiro
  +-- Federações
  +-- Campeonatos
  +-- Resultados
  +-- Histórico esportivo
```

O sistema deve ser construído de maneira que a expansão para novas
modalidades, novos dojos, novas associações, integrações financeiras e
integrações federativas não exija remodelagem completa do domínio.
