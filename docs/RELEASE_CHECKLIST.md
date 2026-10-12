# Go/no-go de produção — JUDOMA

**Decisão atual: NO-GO para produção; implementação técnica até a Sprint 21 e homologação local disponíveis.** Conclusão do roadmap não representa autorização de uso com dados reais.

| Gate | Estado / evidência requerida | Responsável a designar |
|---|---|---|
| Testes e estilo | Ver `RELEASE_VALIDATION.md` | Desenvolvimento |
| Dependências | Auditoria sem avisos na execução registrada; repetir no release | Desenvolvimento |
| Hospedagem e preflight | Pendente host/domínio, TLS real, cookies e preflight aprovado | Infraestrutura |
| Permissões e dados por campo | Pendente decisão D02 e testes de cada papel em cada fluxo | Produto + Segurança |
| MFA administrativo | Pendente definição, implementação ou controle aprovado equivalente | Segurança |
| Privacidade e retenção | Pendentes decisões documentadas, avisos, acesso e descarte | Responsável de privacidade |
| SMTP e cron reais | Pendentes execução e recebimento autorizados no provedor | Operação |
| Backup/restore/rollback | Ensaio sintético local não substitui teste do provedor com volume representativo | Operação |
| Carga concorrente | Ensaio sequencial de dashboard não é teste completo de capacidade | Desenvolvimento + Infraestrutura |
| Acessibilidade e aceite | Login mobile conferido; falta teclado/leitor de tela e aceite de usuários nos fluxos autenticados | Produto + Usuários |
| Suporte e treinamento | Guias disponíveis; falta execução do treinamento e definição de contatos | Operação |

## Escopo que precisa de decisão

As permissões administrativas continuam principalmente associativas. O contexto operacional possui rotas locais explícitas para cadastro/matrícula, aulas/chamada, importação, vínculos/cobranças/pagamentos e inscrições em campeonatos; isso não transforma as rotas associativas equivalentes em acesso local. `dashboard.dojo` e `dashboard.teacher` limitam apenas indicadores. Não atribuir permissões amplas presumindo isolamento. Aprovar a matriz definitiva por papel/campo antes de provisionar usuários reais. O portal possui isolamento próprio por vínculo vigente.

## Registro obrigatório da liberação

Registrar release/hashes, ambiente, data, aprovadores técnicos e de negócio, evidências de cada gate, riscos aceitos por quem tem autoridade, janela, plano de retorno e contatos. Qualquer gate obrigatório pendente mantém NO-GO. Não preencher assinaturas nem aceite de usuários por inferência de testes automatizados.
