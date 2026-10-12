<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\PaymentRequest;
use App\Http\Requests\Finance\PaymentReversalRequest;
use App\Models\Association;
use App\Models\Charge;
use App\Models\Payment;
use App\Services\Finance\PaymentService;
use Illuminate\Http\RedirectResponse;

class PaymentController extends Controller
{
    public function store(PaymentRequest $request, Association $association, Charge $charge, PaymentService $service): RedirectResponse
    {
        abort_unless($charge->association_id === $association->id, 404);
        $service->post($association, $charge, $request->user(), $request->validated());

        return redirect()->route('associations.charges.show', [$association, $charge])->with('status', 'Pagamento registrado e saldo atualizado.');
    }

    public function reverse(PaymentReversalRequest $request, Association $association, Payment $payment, PaymentService $service): RedirectResponse
    {
        abort_unless($payment->association_id === $association->id, 404);
        $service->reverse($association, $payment, $request->user(), $request->validated('reversal_reason'));

        return redirect()->route('associations.charges.show', [$association, $payment->charge_id])->with('status', 'Pagamento estornado. O histórico foi preservado e o saldo recalculado.');
    }
}
