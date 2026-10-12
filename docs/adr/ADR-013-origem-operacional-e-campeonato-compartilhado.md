# ADR-013 — Origem operacional e campeonato compartilhado

**Estado:** aceito e implementado na Sprint 21.

## Contexto

Um aluno pode manter vínculos regulares com vários dojos. Portanto, `students.primary_dojo_id` não prova qual unidade vendeu um plano, administra uma cobrança ou inscreveu o atleta. Copiar campeonatos por dojo também fragmentaria regras, taxa, inscrições e resultados.

## Decisão

- `student_plans.dojo_id` registra obrigatoriamente a origem dos novos vínculos financeiros;
- a atribuição exige vínculo regular do aluno com a unidade na data inicial; plano restrito precisa pertencer à mesma unidade;
- sobreposição de plano é avaliada por aluno e dojo, permitindo obrigações distintas em unidades onde ele está regularmente vinculado;
- a cobrança recorrente usa `student_plans.dojo_id` e ignora vínculos legados sem origem até revisão; mudança do dojo principal não reatribui histórico;
- gratuidade é calculada somente quando pertence ao mesmo dojo do vínculo de plano;
- o campeonato continua sendo uma única entidade da associação. `championship_dojo_participations` registra participação local, criada idempotentemente na primeira inscrição do dojo;
- o gestor local vê o evento central, mas somente as inscrições cujo `dojo_id` é a sua unidade. A taxa, modalidade, janela e regras não podem ser alteradas no contexto local;
- aluno, pessoa, graduação e dojo são derivados/revalidados no servidor na inscrição local;
- `sigam:operation:audit-origins` é simulação por padrão. `--apply` preenche apenas vínculos com uma única evidência, pelo plano restrito e/ou cobranças concordantes. Cobranças e inscrições históricas nunca são reatribuídas automaticamente.

## Consequências

O financeiro e as inscrições permanecem explicáveis em cenários multi-dojo. Registros ambíguos bloqueiam nova geração em vez de serem ligados silenciosamente ao dojo principal. O schema é incremental e compatível com rollback de código; remover a migration exige antes verificar que o código anterior não depende das novas origens.
