# Organização e Pessoas

## Objetivo e limites

O módulo implementa associação, dojo e cadastro central de pessoa. Perfis de aluno, responsável e professor são vínculos sobre a mesma pessoa e não criam cópias de dados pessoais. Modalidades, graduações e responsáveis são mantidos pelos módulos esportivos e consumidos pelos cadastros diretos de aluno/professor.

## Atores, permissões e escopo

| Ação | Permissão | Escopo aplicado |
|---|---|---|
| listar/ver associação | `association.view` | sistema ou associação da rota |
| criar associação | `association.manage` global | somente papel de sistema |
| editar/inativar associação | `association.manage` | associação da rota |
| listar/ver dojo | `dojo.view` | associação pai da rota |
| criar/editar/inativar dojo | `dojo.manage` | associação pai da rota |
| listar/ver/exportar pessoa | `person.view` | vínculo com a associação pai |
| criar/editar/inativar pessoa | `person.manage` | vínculo com a associação pai |

`RequirePermission` resolve o ID da associação pelo model binding. Controllers de dojo e pessoa confirmam também o vínculo com a associação aninhada e respondem 404 em tentativa de IDOR. Um ID enviado no corpo nunca define escopo.

## Entidades e invariantes

- `Association`: cadastro institucional; CNPJ opcional, válido, normalizado e globalmente único.
- `Dojo`: sempre pertence a uma associação; `code` é único dentro dela; a inativação preserva a linha e o histórico.
- `Person`: cadastro global; CPF opcional, válido, normalizado e globalmente único.
- `association_person_roles`: mantém o vínculo por período. A criação inicial usa `registered_person`, sem conceder login ou papel esportivo.
- associação inativa não aceita criação de dojo nem novo vínculo de pessoa;
- edição usa mudança de `status`; não há ação de exclusão física na interface;
- as listas mostram um ícone de edição só para quem tem `person.manage`; o parâmetro `voltar` aceita apenas caminho interno e devolve o usuário à lista de origem;
- no dojo, o gestor edita parte dos dados dos alunos da unidade (`DojoStudentPersonController`, `DojoStudentPersonRequest::FIELDS`), sem CPF, documento, situação ou observações;
- e-mails são salvos em minúsculas e CPF/CNPJ/telefones/CEP somente com dígitos.

## Fluxos

### Associação

`lista -> criar/ver -> editar -> ativa|inativa`

Somente administrador global cria associações. Usuários associados visualizam apenas a própria associação.

### Dojo

`associação -> lista com busca/status -> criar/ver -> editar -> ativo|inativo`

O serviço `DojoService` valida associação ativa, abre transação, persiste e audita.

### Pessoa

`associação -> busca prévia -> criar/ver -> editar -> ativa|inativa`

A busca cobre nome, nome social, CPF, e-mail e telefone. CPF repetido falha antes da persistência. A lista e o CSV só consultam pessoas vinculadas à associação. CSV protege células que começam com caracteres de fórmula.

### Foto da pessoa

A foto serve para identificação e é uma só por pessoa (vale para aluno, professor e responsável). Ela é enviada nos cadastros de pessoa, de aluno e de professor e na edição de dados do aluno pelo gestor do dojo. Quem altera é quem já edita dados pessoais: `person.manage` na associação; no dojo, o gestor com `student.manage` para alunos do próprio dojo que não são professores; no cadastro direto, quem cadastra.

`PersonPhotoService::prepare` converte o arquivo antes de gravar qualquer dado: aceita JPG, PNG ou WEBP com até 5 MB e 30 megapixels, aplica a rotação EXIF, recorta em quadrado (levemente acima do centro, onde costuma estar o rosto) e regrava em JPEG nos tamanhos 96 px (listas) e 320 px (ficha), descartando metadados como localização. `apply` grava os arquivos em `storage/app/private/photos/{person}/{uuid}-{thumb|medium}.jpg`, troca `people.photo_path` com lock e auditoria (`person.photo_updated|removed`, só "tinha foto/tem foto") e apaga os arquivos antigos depois do commit. Remover a foto também apaga os arquivos: a foto não é registro histórico.

A imagem nunca tem link público. As telas que já autorizaram ver a pessoa geram um link assinado e relativo (`/fotos/{person}/{size}?v=&expires=&signature=`) válido por 6 a 12 horas e estável dentro da mesma janela de 6 horas, para o navegador reaproveitar o cache. A rota exige login, conta ativa e, para usuário vinculado a uma associação, que a pessoa pertença a ela; trocar a foto muda `v` e invalida os links antigos. Aparece como miniatura redonda (`<x-person-photo>`, com iniciais quando não há foto) nas listas de pessoas, alunos e professores da associação e do dojo, na chamada da aula e nas fichas da pessoa e do aluno.

O ajuste exige a extensão GD do PHP. Sem ela o sistema continua funcionando, mas o envio de foto é recusado com mensagem; `sigam:production:check` informa a disponibilidade da GD sem mudar o resultado.

Limitação conhecida: um CPF já existente apenas em outra associação não pode ser vinculado automaticamente por um gestor, pois isso revelaria ou apropriaria identidade sem verificação. O fluxo de vinculação/mesclagem controlada será definido antes de operação multiassociação ampla.

## Mapa do código

- rotas: `routes/web.php`, prefixo `/associacoes`;
- controllers: `app/Http/Controllers/Organization` e `app/Http/Controllers/People`;
- validação/normalização: `app/Http/Requests`, `app/Rules` e `app/Support/Normalization`;
- invariantes e transações: `app/Services/Organization` e `app/Services/People`;
- escopo: `app/Http/Middleware/RequirePermission.php`;
- persistência: models `Association`, `Dojo`, `Person` e migrations `000000`/`000004`;
- interface: `resources/views/associations`, `dojos`, `people` e `public/css/sigam.css`.

Controllers coordenam HTTP; regras de escrita ficam nos serviços. Novos relacionamentos com dojo devem carregar `association_id` e validar que todos os recursos pertencem ao mesmo escopo.

## Auditoria e dados pessoais

Eventos: `association.created|updated`, `dojo.created|updated`, `person.created|updated` e `person.photo_updated|removed`. Auditoria de pessoa guarda nome/status na criação; na edição registra status e nomes dos campos alterados, sem copiar CPF, contatos, endereço ou observações. CPF aparece mascarado na página de leitura e não é exportado no CSV inicial.

A matriz definitiva de visibilidade por campo permanece vinculada à decisão D-02. Até sua aprovação, evitar incluir CPF, documento, nascimento, endereço e emergência em novas listagens, logs ou exports.

## UX e estados

- uma ação principal visível por página;
- busca antes do cadastro e aviso explícito de duplicidade;
- somente nome da pessoa é obrigatório; formulários podem ser completados depois;
- seções curtas, labels permanentes, feedback em português e dados preservados após erro;
- status usa texto além de cor;
- layout vira uma coluna no celular e mantém ações de salvar acessíveis.

## Testes e critérios de aceite

`OrganizationManagementTest` cobre criação/normalização/auditoria, escopo entre associações, vínculo de dojo e bloqueio em associação inativa. `PersonManagementTest` cobre vínculo central, normalização, CPF duplicado, ausência de permissão e IDOR.

Aceite: gestor autorizado mantém dojos e pessoas da sua associação; usuário externo recebe 403/404; cadastro duplicado de CPF não é criado; inativação não apaga registros; migrations e testes passam em SQLite e MySQL.
