# Registro de decisões de produto

| ID | Estado | Decisão provisória | Prazo |
|---|---|---|---|
| D-01 | Aberta | Banco multiassociação; usuário comum com associação atual fixa | antes da UI multiassociação |
| D-02 | Aberta | menor privilégio e mascaramento de dados sensíveis; a implementação atual adota exposição conservadora | antes do provisionamento de usuários reais |
| D-03 | Implementada | menor fica em pré-cadastro; ativação exige nascimento e responsável legal quando configurado na associação | revisar após teste de uso |
| D-04 | Implementada | modelo permite múltiplos dojos/modalidades/responsáveis; mantém um dojo principal por período | revisar após teste de uso |
| D-05 | Implementada | fechamento exige lista completa; reabertura exige permissão, motivo e auditoria | revisar após teste mobile |
| D-06 | Implementada | dia inexistente usa último dia; fim de semana/feriado segue política configurável e cada cobrança guarda o snapshot | revisar após homologação financeira |
| D-07 | Implementada | excedente é bloqueado; baixa exige chave idempotente única por associação e repetição idêntica devolve o resultado anterior | revisar após homologação financeira |
| D-08 | Implementada | envio aguarda dojo, depois associação; aprovação dupla exige usuários distintos; transições somente pelo serviço | revisar após teste de uso |
| D-09 | Aberta | retenção por classe de dado e aprovação jurídica | antes de produção |
| D-10 | Aberta | backup diário banco + arquivos e restore ensaiado | antes de homologação final |
| D-11 | Implementada | aluno pode manter vários vínculos regulares de dojo, um principal e participar como visitante em aula sem vínculo regular | revisar após homologação operacional |
| D-12 | Implementada | associação cadastra/convida professores; dojo somente seleciona professores previamente cadastrados na associação | revisar após homologação de convite |
| D-13 | Implementada | manter oito próximas aulas por turma, configurável para quatro, com reposição automática | revisar após homologação da Sprint 20 |
| D-14 | Implementada | visitas somente entre dojos da mesma associação e sempre gratuitas; sem taxa avulsa ou geração de cobrança pela visita | revisar após homologação operacional |
| D-15 | Implementada | plano, cobrança, pagamento e etapa local de gratuidade usam `dojo_id` persistido; dojo não altera catálogo, estorna ou aprova a etapa associativa | revisar após homologação financeira local |
| D-16 | Implementada | campeonato é catálogo associativo; dojo adere sem copiar evento e inscreve somente aluno com vínculo regular local | revisar após homologação de campeonatos locais |

Decisões abertas não autorizam atalhos. D-01, D-02, D-09 e D-10 são gates de produto/operação; o estado técnico pode estar concluído e ainda permanecer **NO-GO** para dados reais.
