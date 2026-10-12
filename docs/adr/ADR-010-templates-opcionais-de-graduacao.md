# ADR-010 — Templates opcionais de graduação

**Estado:** aceito.

## Contexto

O catálogo esportivo precisa continuar configurável porque federações, estilos, faixas etárias e regulamentos adotam sequências diferentes. Ao mesmo tempo, exigir que o gestor digite cada graduação torna o início da operação lento e sujeito a erros de nome e ordem.

O modelo atual possui uma sequência linear de `ranks` por modalidade. Ele não representa duas progressões ramificadas dentro da mesma modalidade.

## Decisão

O cadastro de modalidade oferece templates opcionais mantidos em `resources/data/rank-templates.json`. A opção padrão continua sendo começar em branco.

O catálogo contém metadados de apresentação, referência, alerta de revisão e a lista de graduações. `RankTemplateCatalog` valida a estrutura, nomes e ordens únicas antes do uso. A chave enviada pelo formulário é validada por lista fechada no backend.

`CatalogService` cria modalidade, graduações e auditoria em uma única transação. O evento `rank.template_applied` registra chave, fonte e quantidade. Cada graduação também mantém o evento `rank.saved`. Falha em qualquer item reverte toda a operação.

O JavaScript apenas melhora a experiência ao preencher nome, descrição e prévia. O servidor deriva novamente os padrões, portanto o fluxo funciona sem JavaScript e não confia nos dados do navegador.

## Consequências

- templates reduzem digitação, mas não se tornam regra obrigatória nem seeder;
- a associação recebe cópias editáveis, sem vínculo permanente com futuras revisões do template;
- alterações no JSON não modificam modalidades já criadas;
- Jiu-Jitsu adulto e infantil usam templates e nomes de modalidade separados enquanto o modelo permanecer linear;
- Karatê Shotokan é apresentado explicitamente como referência variável por estilo e federação;
- novas fontes devem registrar versão, URL, resumo, aviso de revisão e testes antes de entrar no catálogo.

## Manutenção

Ao adicionar ou atualizar um template:

1. confirme a fonte oficial e a data da regra;
2. preserve `name` e `sort_order` únicos dentro do template;
3. não transforme campo desconhecido em zero;
4. atualize a planilha de referência e a documentação do módulo;
5. cubra criação, autorização, auditoria e rollback nos testes SQLite/MySQL.
