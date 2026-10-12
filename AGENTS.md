# Instruções para agentes de código

Leia `docs/INDEX.md`, `docs/IMPLEMENTATION_STATUS.md`, `docs/MAINTENANCE_GUIDE.md`, o documento do módulo afetado e os ADRs relacionados antes de editar código. Para melhorias planejadas, leia também `docs/IMPROVEMENT_BACKLOG.md` e confirme que proposta não significa funcionalidade implementada. Inspecione migrations, models, rotas, middleware/escopo, serviços e testes existentes antes de propor mudanças.

Regras obrigatórias:

- preserve o monólito modular Laravel e a compatibilidade com hospedagem compartilhada;
- não introduza Redis, worker permanente, microserviço ou Docker como requisito;
- valide autorização e escopo no backend; IDs do request nunca provam acesso;
- use serviço de domínio e transação para regras críticas;
- não use `float` para dinheiro nem exclusão física para histórico financeiro/auditoria;
- documentos permanecem privados;
- migrations são incrementais; nunca recrie uma tabela sem analisar o histórico;
- jobs e endpoints de escrita crítica devem ser idempotentes;
- interface e mensagens ficam em português simples e devem funcionar em celular;
- execute testes e Pint antes de concluir;
- atualize documentação e changelog na mesma alteração.

Comandos locais: `scripts/php.ps1` e `scripts/composer.ps1`. Nunca leia, imprima ou versione segredos do `.env`.

