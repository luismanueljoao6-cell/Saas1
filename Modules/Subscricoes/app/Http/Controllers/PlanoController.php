<?php

namespace Modules\Subscricoes\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Subscricoes\Models\Plano;

class PlanoController extends Controller
{
    public function index(): View
    {
        return view('subscricoes::planos.index', [
            'planos' => Plano::ativos()->get(),
        ]);
    }
}
