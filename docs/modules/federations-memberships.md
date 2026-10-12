# Federações e filiações

## Responsabilidade

O módulo registra entidades federativas e filiações anuais de alunos. Ele lê o cadastro esportivo consolidado, reutiliza documentos privados e não altera graduação, modalidade ou histórico do aluno.

`federations` é um catálogo por associação. Nome e sigla são únicos no escopo; a entidade pode ser inativada sem apagar filiações. `federation_memberships` representa um fato histórico e não possui rota de edição ou exclusão. Renovação ou novo período cria outra linha.

## Regras de filiação

Uma filiação contém associação, aluno, federação, modalidade, número federativo, ano, datas de inscrição/validade, graduação registrada, pontos, situação, observações e criador.

O `FederationMembershipService` executa a criação em transação e valida:

- aluno e federação pertencem à associação da rota;
- federação, modalidade e graduação estão ativas;
- aluno pratica a modalidade;
- graduação pertence à modalidade e já existe em `student_rank_history` para o aluno;
- ano de referência corresponde ao ano da inscrição;
- a combinação aluno, federação, modalidade e ano ainda não existe.

A constraint `federation_membership_period_unique` reforça a última regra. Alterações futuras na graduação atual não modificam `rank_id` da filiação. A integração com o cadastro esportivo é somente leitura.

## Validade, relatórios e documentos

Validade é derivada de `expiration_date`: sem validade, válida, vence em até 30 dias ou vencida. O painel mostra totais, filiações válidas, vencendo e vencidas. Filtros por federação, ano e validade atendem aos relatórios federativos; o CSV UTF-8 inclui aluno, federação, modalidade, graduação, número, período, pontos e situação.

Documentos federativos usam a tabela e o `DocumentService` existentes. `documents.federation_membership_id` conecta o arquivo à filiação, preservando pessoa e aluno materializados. Arquivos seguem privados, UUID, validação de assinatura/MIME, download autorizado e exclusão lógica.

## Permissões e segurança

- `federation.view`: painel, detalhe, histórico e CSV;
- `federation.manage`: cadastro/inativação de federação, nova filiação e upload de documento federativo;
- `document.download`: necessário também para baixar o conteúdo privado.

Middleware, controller e serviço validam associação. IDs enviados pelo navegador não ampliam acesso. Auditoria registra `federation.created`, `federation.updated` e `federation_membership.created`; conteúdo e observações sensíveis não são copiados para a auditoria.

## Mapa de código

- migration: `database/migrations/2026_08_30_000013_create_federations_and_memberships_tables.php`;
- models: `Federation.php`, `FederationMembership.php` e relação em `Document.php`;
- serviço: `app/Services/Federation/FederationMembershipService.php`;
- requests/controller: `app/Http/Requests/Federation/`, `FederationController.php`;
- interface: `resources/views/federation/`;
- testes: `tests/Feature/FederationMembershipsTest.php`.

## Manutenção futura

Nunca adicione update/delete para filiação sem uma decisão de produto que defina correção histórica auditável. Novos relatórios devem consultar o snapshot `rank_id`, não a graduação atual. Campeonatos consultam a filiação válida, mas não gravam estado dentro desta tabela. O gerador idempotente de notificações usa o vencimento da filiação sem reescrever o fato histórico.
