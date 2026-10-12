# ADR-008 — Convite e ativação de professor

**Estado:** aceito e implementado na Sprint 17.

## Contexto

O perfil esportivo, o vínculo institucional e a conta de acesso têm ciclos de vida diferentes. Criar senha padrão no cadastro do professor, ativar a conta antes do aceite ou reutilizar uma conta apenas pelo e-mail permitiria acesso indevido e apagaria decisões do titular.

## Decisão

- somente gestor associativo com `teacher.manage` emite, reenvia ou revoga convite;
- o cadastro pode preparar uma conta `pending`, com segredo aleatório não entregue, mas não concede papel ativo;
- cada convite possui token aleatório de 64 caracteres, prazo de 72 horas e uso único. O banco conserva o hash SHA-256; a cópia necessária à entrega fica cifrada e é descartada depois do envio;
- o envio usa `TeacherInvitationMail` em lote pelo Scheduler, sem worker residente. Reserva condicional e recuperação de reserva antiga tornam o processamento repetível;
- GET apenas apresenta associação, professor, prazo e estado. Verificação do e-mail, aceite, ativação e concessão ocorrem somente no POST;
- conta nova define a própria senha. Conta ativa comprova a senha atual e conserva hash, associação e papéis existentes;
- no aceite, o serviço revalida associação, professor, pessoa, e-mail, vínculo institucional, conta e autoridade atual do emissor;
- o papel global técnico `teacher_account` concede apenas `dashboard.teacher` no escopo da associação do convite;
- recusa ou revogação não apaga professor, vínculo institucional, conta ou outros papéis;
- reenvio revoga o convite anterior e cria outro segredo. Nenhuma migração convida professores em massa;
- recuperação de senha usa o broker do Laravel, resposta não enumerável, token de uso único e mensagem própria.

## Consequências

Perfil cadastrado não significa acesso. Falha de transporte é separada do estado de negócio e fica visível para o gestor. O cron precisa de SMTP configurado; `log` não consome convites fora de testes. Operadores não devem alterar estados por SQL: reenvio e revogação passam pelos serviços para preservar auditoria e invalidação do segredo.
