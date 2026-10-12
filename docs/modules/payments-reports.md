# Pagamentos, estornos e relatórios

## Responsabilidade e fluxo

O módulo conclui o ciclo `origem → cobrança → pagamento → baixa`. `PaymentService` é a única entrada para registrar ou estornar pagamentos. A interface, comandos futuros e integrações devem reutilizar esse serviço, sem atualizar diretamente os saldos de `charges`.

Inscrições aprovadas em campeonatos usam esse mesmo ciclo com `source_type = championship_registration`; a taxa congelada na inscrição gera `charges` e nunca uma tabela financeira paralela.

Pagamentos podem ser parciais. O serviço bloqueia a cobrança com `lockForUpdate`, busca uma chave idempotente única por associação, valida o saldo e grava pagamento, usuário e instante em uma única transação. A constraint `payment_scope_idempotency_unique` protege também contra reenvios concorrentes. Repetir a mesma chave e os mesmos dados devolve o lançamento existente; reutilizá-la com dados diferentes é rejeitado.

`paid_amount` nunca é incrementado a partir do valor recebido. Ele é reconciliado pela soma de todos os pagamentos `valid`; `balance_amount = final_amount - paid_amount`. Isso permite reparar e conferir saldos sem depender de uma sequência de eventos. Excedente é bloqueado e valores usam `DECIMAL(12,2)`/BCMath.

## Estados e estorno

- `valid`: participa da soma e do saldo;
- `reversed`: permanece no histórico, mas não participa da soma.

O estorno exige motivo, usuário com permissão própria e transação. O registro original recebe ator, instante e motivo; não há exclusão física. Depois do estorno, a cobrança volta a `open`, `overdue` ou `partially_paid`, conforme vencimento e demais pagamentos válidos.

Formas atuais: PIX, dinheiro, cartão, transferência, boleto e outro. `reference` guarda apenas identificador operacional; não deve receber segredo, número integral de cartão ou credencial.

## Relatórios

`FinancialReportService` aplica associação, período civil e dojo no backend. O painel mostra previsto, recebido, a receber, vencido, descontos, gratuidades, consolidação por dojo/modalidade e lista de recebimentos.

A exportação CSV usa UTF-8 com BOM e separador ponto e vírgula para abertura simples no Excel. Ela contém linhas de cobrança e pagamento. O extrato do aluno exibe cobranças, pagamentos, estornos e saldo. XLSX/PDF não foram adicionados porque o CSV cobre a necessidade atual sem nova dependência.

O botão de impressão gera apenas um registro operacional interno. Recibo fiscal/formal permanece fora do escopo até aprovação dos requisitos jurídicos e fiscais; a tela informa essa limitação explicitamente.

## Permissões e escopo

- `charge.view`: central, detalhe e extrato;
- `payment.manage`: registrar pagamento;
- `payment.reverse`: estornar pagamento;
- `finance.report`: consultar e exportar relatórios.

Controllers e serviços confirmam `association_id`. O dojo do filtro também é validado contra a associação atual. IDs enviados pelo navegador nunca provam acesso.

No contexto operacional, `payment.manage` permite baixa apenas em cobrança cuja origem persistida é o dojo concedido. `payment.reverse` e `finance.report` continuam associativos; o gestor local não estorna nem exporta o consolidado.

## Mapa de código

- migration: `database/migrations/2026_08_30_000012_create_payments_table.php`;
- models: `Payment.php`, relação `Charge::payments()`;
- domínio: `PaymentService.php`, `FinancialReportService.php`;
- HTTP: `PaymentController.php`, `FinancialReportController.php`, `ChargeController::show()` e `app/Http/Controllers/Access/DojoFinanceController.php`;
- interface: `resources/views/finance/charges/`, `resources/views/finance/reports/` e `resources/views/operation/finance/`;
- testes: `tests/Feature/PaymentsAndReportsTest.php` e escopo local em `tests/Feature/Sprint21Test.php`.

## Manutenção futura

Integrações devem gerar UUID por tentativa lógica e conservar a mesma chave em retentativas. Nunca aceite valor monetário em `float`. Qualquer nova forma de pagamento deve entrar na validação, tradução da interface e documentação. Para reconciliação operacional, compare `charges.paid_amount` com `SUM(payments.amount WHERE status = valid)`; divergência deve ser tratada como incidente e corrigida pelo serviço, com auditoria.
