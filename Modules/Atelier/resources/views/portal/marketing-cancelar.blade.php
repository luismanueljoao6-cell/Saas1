@extends('atelier::layouts.publico')

@section('titulo', 'Mensagens de marketing')

@section('conteudo')
    <div class="mx-auto max-w-md rounded-md border border-slate-200 bg-white p-6 text-center">
        @if ($concluido)
            <h1 class="mb-2 text-lg font-semibold text-slate-900">Pedido registado</h1>
            <p class="text-sm text-slate-600">
                Deixaste de receber mensagens de marketing de {{ $empresa?->nome_comercial ?? 'este atelier' }}.
                Continuarás a receber avisos sobre os teus pedidos e provas.
            </p>
        @else
            <h1 class="mb-2 text-lg font-semibold text-slate-900">Deixar de receber mensagens?</h1>
            <p class="mb-4 text-sm text-slate-600">
                Olá, {{ $cliente->nome }}. Confirma para deixares de receber campanhas e mensagens de aniversário de
                {{ $empresa?->nome_comercial ?? 'este atelier' }}.
            </p>
            <form method="POST" action="{{ request()->fullUrl() }}">
                @csrf
                <button type="submit" class="rounded-md bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    Confirmar
                </button>
            </form>
        @endif
    </div>
@endsection
