@extends('core::layouts.app')

@section('titulo', 'Painel')

@section('conteudo')
    <div class="w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">
            Olá, {{ auth()->user()->name }} 👋
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ auth()->user()->empresa->nome_comercial }} —
            @if (auth()->user()->empresa->estado_subscricao === 'trial')
                em período experimental.
            @else
                subscrição {{ auth()->user()->empresa->estado_subscricao }}.
            @endif
        </p>

        <div class="mt-6 rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
            Este é o Núcleo Multi-tenant. Os módulos de Faturação, Subscrições,
            Atelier de Costura e Estúdio de Música vão acrescentar aqui os
            seus próprios cartões de acesso rápido assim que forem instalados.
        </div>

        <a href="{{ route('core.empresa.editar', auth()->user()->empresa_id) }}"
           class="mt-6 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500">
            Configurar dados da empresa &rarr;
        </a>
    </div>
@endsection
