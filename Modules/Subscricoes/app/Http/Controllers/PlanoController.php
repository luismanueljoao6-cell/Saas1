<?php

namespace Modules\Subscricoes\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Models\Subscricao;

class PlanoController extends Controller
{
    public function index(): View
    {
        return view('subscricoes::planos.index', [
            'planos' => Plano::ativos()->get(),
            'subscricaoAtual' => Subscricao::with('plano')->where('estado', 'ativa')->latest('id')->first(),
        ]);
    }
}
