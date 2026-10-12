# ADR-006 — Contexto operacional por dojo

**Estado:** aceito na Sprint 15; ampliado pelas rotas operacionais explícitas das Sprints 16–21.

## Contexto

Papéis associativos atendem à administração consolidada, mas não representam uma pessoa que opera somente em um ou mais dojos. `users.dojo_id`, parâmetros de URL e vínculo da pessoa não são provas de autorização. Reutilizar as rotas associativas para operadores locais permitiria listagens, exportações ou arquivos além da unidade autorizada.

## Decisão

- manter papéis `system` e `association` em `user_roles` para rotas administrativas legadas;
- registrar papéis `dojo` e `teacher` em `dojo_role_assignments`, sempre com associação, dojo, vigência, revogação e autor;
- papéis locais nunca satisfazem `User::hasPermission`; as rotas `/operacao/dojos/{dojo}` usam `OperationalScope` e consultas já filtradas antes de paginação ou contagem;
- separar `teacher_profiles`, vínculo institucional em `teacher_association_memberships`, atuação local em `dojo_teacher_assignments` e conta/permissão;
- professor precisa simultaneamente de conta ativa, concessão vigente, vínculo institucional ativo, vínculo local vigente e designação na aula como principal, auxiliar, substituto, previsto ou efetivo;
- `users.dojo_id` permanece compatível com dashboards antigos, mas não concede acesso operacional;
- revogação é lógica e imediata. Períodos não são sobrescritos ou apagados;
- acesso associativo explícito continua podendo operar de forma consolidada. Não há elevação automática de papéis antigos ou inferidos;
- uma capacidade local exige rota operacional explícita e escopo revalidado. Upload contextual no cadastro, financeiro de origem local e inscrição local em campeonato foram adicionados nas Sprints 16 e 21; centrais associativas, download documental amplo, configuração, decisões, estornos, relatórios e exportações permanecem negados ao papel local.

## Migração

`sigam:access:backfill-teachers` não inventa vínculo local. Com `--apply`, cria vínculo institucional onde há simultaneamente perfil ativo, associação ativa e vínculo vigente da pessoa. Não cria conta, senha, convite, papel, vínculo com dojo ou permissão. A equipe revisa as contagens antes de aplicar.

## Consequências

Há dois caminhos deliberados: administração associativa e operação local. Controllers não podem transformar uma concessão local em permissão associativa. Novos módulos devem receber um `Dojo` resolvido, consultar `OperationalScope` no backend e testar IDOR entre dois dojos da mesma associação e entre associações.
