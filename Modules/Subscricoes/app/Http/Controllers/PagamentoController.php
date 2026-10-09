<?php

namespace Modules\Subscricoes\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Subscricoes\Models\Pagamento;

class PagamentoController extends Controller
{
    /**
     * $pagamento chega como id: o route model binding implícito corre antes
     * do middleware 'tenant' e a TenantScope daria sempre 404.
     */
    public function mostrar(int $pagamento): View
    {
        $modelo = Pagamento::with('subscricao.plano')->findOrFail($pagamento);

        return view('subscricoes::subscricao.pendente', ['pagamento' => $modelo]);
    }
}
