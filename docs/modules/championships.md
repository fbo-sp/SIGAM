# Campeonatos, inscrições e resultados

## Responsabilidade e fluxo

O módulo implementa o fluxo `campeonato → inscrição → decisão → cobrança → pagamento → participação → resultado`. Ele depende do cadastro esportivo, federações, financeiro e auditoria, mas não replica dados desses módulos.

`championships` contém modalidade, responsável, tipo, organizador, local, datas, janela de inscrição, regulamento textual, taxa e papéis aceitos. Depois da primeira inscrição, modalidade, datas, taxa e papéis ficam congelados; descrição, orientações e situação podem continuar sendo administradas sem alterar snapshots existentes.

O evento é único na associação. O contexto local apenas consulta suas regras e cria inscrições de alunos regularmente vinculados ao dojo; a primeira inscrição cria ou reutiliza `championship_dojo_participations`. A listagem local filtra `championship_registrations.dojo_id` antes da paginação e nunca mostra participantes de outra unidade. Pessoa e graduação atual são derivadas no backend.

## Inscrição e decisão

`ChampionshipRegistrationService` bloqueia o campeonato e valida no backend:

- campeonato pertence à associação, está aberto e dentro da janela;
- papel é aceito pelo campeonato;
- pessoa, aluno, dojo e eventual filiação pertencem ao escopo;
- competidor possui nascimento, modalidade e graduação no histórico consolidado;
- idade é calculada pelo servidor na data inicial do evento;
- filiação opcional está ativa e válida na data do campeonato;
- pessoa não possui o mesmo papel duplicado no campeonato.

A constraint `championship_person_role_unique` reforça a idempotência por campeonato, pessoa e papel. A taxa é copiada de `championships.default_fee` para `registration_fee` no momento da inscrição e não muda depois.

Somente inscrição `pending` recebe decisão. Recusa guarda ator, instante e motivo. Aprovação com taxa maior que zero cria exatamente uma cobrança em `charges`, usando `source_type = championship_registration` e `source_id = registration.id`. Não existe tabela financeira paralela. Inscrição gratuita é aprovada sem cobrança.

## Pagamento, participação e resultado

Pagamentos e estornos usam integralmente `PaymentService`. O detalhe do campeonato aponta para a cobrança comum. `ChampionshipResultService` aceita somente inscrição aprovada e somente a partir da data inicial do evento. Quando existe taxa, participação confirmada exige cobrança totalmente paga.

Competidores podem receber colocação, medalha, pontos, WO e desclassificação. Árbitros e auxiliares registram participação, função e observações sem campos competitivos. Desclassificado não recebe colocação ou medalha. Um resultado por inscrição é reforçado por chave única e correções ficam auditadas.

## Histórico e relatórios

O histórico esportivo do aluno é uma consulta consolidada a `student_rank_history`, `federation_memberships`, `championship_registrations` e `championship_results`. Nenhuma tabela de histórico duplicada foi criada. A tela oferece filtros por ano e modalidade.

O campeonato mostra inscrições pendentes/aprovadas, papéis, distribuição por dojo, taxas pagas, participações, resultados, medalhas e pontos. A exportação CSV UTF-8 contém papel, dojo, situação, taxa, pagamento e resultado.

## Permissões

- `championship.view`: consultar campeonatos, inscrições, indicadores e CSV;
- `championship.manage`: criar e administrar campeonato;
- `championship.register`: cadastrar inscrições;
- `championship.approve`: aprovar ou recusar;
- `championship.result`: registrar participação e resultado.

Visualizar ou pagar a cobrança continua exigindo as permissões financeiras correspondentes. Todas as rotas e serviços validam `association_id`.

O portal oferece inscrição apenas como competidor. O backend deriva pessoa, aluno, dojo e papel do vínculo autorizado e reutiliza `ChampionshipRegistrationService`; campos de identidade enviados pelo navegador são ignorados.

## Mapa de código

- migrations: `database/migrations/2026_08_30_000014_create_championship_tables.php` e `2026_10_11_000023_consolidate_dojo_operation.php`;
- models: `Championship.php`, `ChampionshipDojoParticipation.php`, `ChampionshipRegistration.php`, `ChampionshipResult.php`;
- domínio: `app/Services/Championship/`;
- HTTP associativo: `app/Http/Requests/Championship/`, `ChampionshipController.php`;
- HTTP local: `app/Http/Controllers/Access/DojoChampionshipController.php`; validação local em lista fechada no controller e invariantes novamente no serviço de domínio;
- interface: `resources/views/championship/`, `resources/views/operation/championships/` e histórico em `sports/students/show.blade.php`;
- testes: `tests/Feature/ChampionshipFlowTest.php` e cenários locais em `tests/Feature/Sprint21Test.php`.

## Manutenção futura

Não grave status financeiro na inscrição além do vínculo `charge_id`; consulte a cobrança. Novos papéis exigem migration/domínio/UI/testes, não apenas uma opção no formulário. Categorias permanecem texto informado porque regras automáticas de idade/peso não foram aprovadas; uma futura tabela de categorias deve preservar o valor já congelado na inscrição. Resultados são fontes de verdade do histórico esportivo e nunca devem ser apagados em cascata.
