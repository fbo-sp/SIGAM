# ADR-007 — Cadastro direto e documentos contextuais

**Estado:** aceito.

## Contexto

O cadastro esportivo exigia criar uma pessoa em outra tela e informar manualmente `person_id` e matrícula. Isso expunha detalhes internos do modelo e favorecia duplicidade. Documentos identificavam pessoa/aluno, mas não distinguiam perfil de professor ou vínculo de responsável.

## Decisão

- a pessoa continua sendo a identidade central definida no ADR-002;
- aluno é cadastrado no contexto de um dojo; professor, somente no contexto da associação;
- `DirectRegistrationService` coordena pessoa, perfil, modalidade, vínculo e ativação em transação;
- `direct_registration_requests` registra chave UUID por associação/operação e torna o reenvio idempotente;
- `registration_counters`, bloqueado em transação, gera matrícula sem entrada manual;
- CPF só reutiliza pessoa ativa já visível na associação após confirmação. CPF de outro escopo produz orientação genérica sem revelar identidade;
- vínculo em novo dojo reutiliza o mesmo aluno e não encerra vínculos regulares existentes;
- documentos continuam privados e sempre materializam `person_id`; os novos FKs `teacher_profile_id` e `student_guardian_id` registram o contexto quando o titular é professor ou responsável;
- a rota associativa antiga permanece temporariamente para compatibilidade, mas a interface principal usa o fluxo composto.

## Consequências

O usuário não navega por Pessoas nem inventa matrícula. Replays não duplicam o núcleo do cadastro. Upload é feito somente após a transação principal concluída; em falha de arquivo, o cadastro válido permanece e o mesmo pedido pode reenviar o documento sem duplicar aluno/professor. Convite de acesso do professor não faz parte desta decisão; sua implementação está no ADR-008.
