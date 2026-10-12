# Documentos privados

## Responsabilidade e limites

O módulo cadastra tipos, metadados, validade e arquivos relacionados a uma pessoa e, opcionalmente, ao seu perfil de aluno, professor, vínculo de responsável ou filiação federativa. Ele depende de Organização, Pessoas, Cadastro Esportivo, Identidade/Acesso e Auditoria. Arquivos nunca são servidos por URL pública.

## Fluxos

1. Gestor cria um tipo próprio da associação e indica se a validade é obrigatória.
2. Usuário com `document.manage` seleciona a pessoa ou aluno, informa metadados e envia PDF, JPG ou PNG de até 10 MB.
3. O request valida tamanho, MIME, extensão e assinatura binária. O serviço confirma associação, coerência pessoa/aluno e tipo disponível.
4. O arquivo recebe UUID sem extensão e vai para `storage/app/private/documents/{association_id}/{person_id}`. Só depois os metadados são gravados; erro no banco remove o arquivo recém-criado.
5. `document.download` localiza o registro no escopo, registra auditoria e transmite o conteúdo com `nosniff`. O caminho físico não aparece na resposta.
6. Remoção é lógica. O arquivo permanece privado para retenção/restore e deixa de ser resolvido pelas rotas usuais.

## Estados de validade

- **Sem validade:** `expiration_date` ausente e o tipo não a exige.
- **Válido:** vencimento posterior a 30 dias.
- **Vence em breve:** entre hoje e 30 dias.
- **Vencido:** data anterior a hoje.

Esses estados são derivados da data; `documents.status` controla apenas disponibilidade cadastral (`active`/`inactive`). O gerador idempotente de notificações usa essas datas para avisos aplicáveis sem alterar o documento.

## Integridade e segurança

- `person_id` é sempre preenchido pelo serviço; no cadastro direto ele precisa ser coerente com `student_id`, `teacher_profile_id` ou `student_guardian_id`.
- Se ambos forem enviados, precisam representar a mesma pessoa.
- pessoa, aluno, tipo e documento precisam pertencer à associação da rota; tipo global com `association_id = null` também é aceito.
- quando `federation_membership_id` é informado, filiação e aluno precisam pertencer à mesma associação e representar a mesma pessoa;
- o disco `local` usa `storage/app/private` com `serve=false`; `public/storage` aponta somente para `storage/app/public`.
- auditoria armazena IDs e tipo, nunca conteúdo, nome físico ou observações.
- download lê por streaming do filesystem, sem carregar o arquivo inteiro em memória.

## Mapa de código

- migrations: `2026_08_30_000008_create_private_documents_tables.php` e incremento `2026_10_07_000017_create_direct_registration_tables.php`
- models: `app/Models/Document.php`, `DocumentType.php`
- validação: `app/Http/Requests/Documents/`
- regra transacional: `app/Services/Documents/DocumentService.php`
- HTTP: `app/Http/Controllers/Documents/DocumentController.php`, `resources/views/documents/`, `routes/web.php`
- regressão: `tests/Feature/PrivateDocumentsTest.php` e `DirectRegistrationTest.php`

## Operação e manutenção

O banco e `storage/app/private` devem entrar no mesmo ciclo de backup. Arquivos de registros excluídos só podem ser purgados após definição formal de retenção LGPD, com rotina auditável. Para ampliar formatos ou tamanho, altere simultaneamente o request, a detecção por assinatura, a interface, os testes e este documento.
