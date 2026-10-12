# Segurança

## Baseline

Estado de liberação e pendências obrigatórias: `RELEASE_CHECKLIST.md`. Esta documentação não constitui certificação de segurança.

- sessão regenerada após login e invalidada no logout;
- mensagem de autenticação genérica;
- usuário inativo não autentica;
- CSRF em formulários e rate limiting no login;
- autorização e escopo no backend;
- cookies `HttpOnly`, `SameSite` e `Secure` em produção;
- logs sem senhas, tokens ou documentos;
- auditoria de login e mudanças sensíveis;
- erros de produção sem SQL, paths ou stack trace.

## Portal pessoal

O portal combina RBAC com autorização por relacionamento. Associação e `person_id` vêm da sessão, nunca do formulário. A consulta de dependentes considera início e fim do vínculo; IDs fora do conjunto autorizado retornam 404. Apenas responsáveis legais alteram contatos, com lista fechada de campos e auditoria sem valores pessoais.

Documentos continuam no disco privado. A rota exige aluno autorizado, associação da conta e vínculo direto `document.student_id`; cada download é auditado. Informações financeiras são somente leitura e não incluem anotações internas.

## Notificações e indicadores

Destinatários são resolvidos no backend por associação, conta ativa, titularidade/responsabilidade ou permissão de aprovação. Preferências nunca podem habilitar outro usuário. Templates são exibidos como texto escapado; erros SMTP são resumidos e não registram credenciais. Dashboards ignoram filtros de escopo do navegador e derivam associação, dojo ou professor da conta autenticada.

Documentos usam o disco privado sem serviço por URL, nomes UUID e download autorizado com auditoria. MIME, extensão, assinatura binária e limite de tamanho são validados. MFA, matriz por campo, retenção LGPD e revisão de ameaças serão concluídos antes do go-live.

Sprint 14: respostas autenticadas e login usam `no-store`; HSTS só é emitido em produção quando a requisição é HTTPS. CSV financeiro/federativo/esportivo neutraliza prefixos de fórmula. E-mails têm conteúdo genérico e erro de transporte sem detalhes; a caixa revalida conteúdo pessoal pelo vínculo vigente. Avisos antigos sem identidade verificável são redigidos na apresentação, sem exclusão do histórico. Ver limites de autorização administrativa em `RBAC.md` e `RELEASE_CHECKLIST.md`.

Sprint 15: concessões por dojo têm vigência e revogação; papéis locais são excluídos do verificador das rotas associativas. Listas locais são filtradas na consulta, antes de paginação. Professor precisa de vínculo institucional, local e designação na aula; `AttendanceService` revalida a escrita. Uma capacidade local só existe quando há rota operacional explícita; a rota associativa permanece negada.

Sprint 16: cadastro composto deriva associação/dojo no backend e usa UUID idempotente sem guardar payload pessoal. CPF acessível só é reutilizado após confirmação; coincidência fora da associação retorna mensagem genérica e não cria vínculo. Matrículas são geradas sob lock. Documento de aluno, professor ou responsável registra FK contextual, pessoa coerente e associação antes de entrar no armazenamento privado. Permissão local de upload não concede listagem ou download geral.

Sprint 17: professor não recebe senha padrão nem autentica enquanto a conta está pendente. Convite usa token aleatório de 64 caracteres, hash único, cópia cifrada apenas até a entrega, prazo de 72 horas e uso único. GET não verifica e-mail nem aceita. POST transacional revalida identidade, e-mail, associação, vínculo e emissor; conta existente comprova a senha e conserva seus papéis. Páginas de convite e recuperação não usam cache nem enviam referência. Auditoria, falhas e listagens não contêm token, senha ou endereço. Recuperação de senha responde de forma genérica e usa token descartável.

Sprint 18: agenda semanal e reconciliação ocorrem em serviço transacional e não apagam aulas com histórico. Visitante é localizado somente por matrícula exata, com resposta genérica fora da associação. Backend revalida aula, dojo anfitrião, modalidade, idade, responsável, capacidade, permissão e designação do professor. A participação não libera cadastro, documentos, responsáveis ou financeiro e não cria cobrança. Auditoria usa IDs, estado e motivo, sem copiar dados pessoais.

Sprint 19: planilhas ficam no disco privado, têm UUID interno e nunca possuem download do original. O leitor limita extensão, tamanho e linhas, seleciona aba esperada e recusa fórmulas. Escopo, CPF, modalidade, graduação, turma, aula e matrícula são resolvidos no backend. Chamada encerrada não é alterada. Auditoria guarda contagens/IDs, não payload ou CPF; CSV de relatório neutraliza fórmulas. O arquivo é eliminado após 30 dias pelo cron, preservando o histórico técnico.

Sprint 20: geração automática mantém somente a meta futura de 4 ou 8 aulas, sob lock e chave única. Reduzir a meta não apaga ocorrências, chamada ou presença; cancelamentos não são recriados.

Sprint 21: finanças e campeonatos locais exigem concessão vigente e origem persistida no dojo. Consultas filtram `dojo_id` antes de carregar/paginar; o backend deriva associação, aluno, pessoa, graduação e origem. Papéis locais não configuram catálogo, não emitem relatório central, não estornam, não aprovam a etapa associativa de gratuidade e não decidem inscrições.
