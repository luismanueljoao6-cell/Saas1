@extends('core::layouts.app')

@section('titulo', 'Painel')

@section('conteudo')
    @php($empresa = auth()->user()->empresa)
    @php($servicos = $empresa->servicosAderidos())

    <div class="w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">
            Olá, {{ auth()->user()->name }} 👋
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ $empresa->nome_comercial }} —
            @if ($empresa->estado_subscricao === 'trial')
                em período experimental.
            @else
                subscrição {{ $empresa->estado_subscricao }}.
            @endif
        </p>

        @if ($servicos->isEmpty())
            <div class="mt-6 rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                Esta empresa ainda não tem serviços ativos.
            </div>
        @else
            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                @foreach ($servicos as $servico)
                    @if (Route::has($servico->rotaInicial()))
                        <a href="{{ route($servico->rotaInicial()) }}"
                           class="block rounded-lg border border-slate-200 p-4 hover:border-indigo-300 hover:shadow-sm">
                            <span class="block text-sm font-semibold text-slate-900">{{ $servico->rotulo() }}</span>
                            <span class="mt-1 block text-xs text-slate-500">{{ $servico->descricao() }}</span>
                        </a>
                    @endif
                @endforeach
            </div>
        @endif

        <a href="{{ route('core.empresa.editar', auth()->user()->empresa_id) }}"
           class="mt-6 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500">
            Configurar dados da empresa &rarr;
        </a>
    </div>
@endsection
