<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;

class PaginasController extends Controller
{
    public function painel(): View
    {
        return view('core::painel');
    }

    public function subscricaoExpirada(): View
    {
        return view('core::subscricao.expirada');
    }
}
