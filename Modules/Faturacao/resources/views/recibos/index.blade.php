@extends('core::layouts.app')

@section('titulo', 'Recibos')

@section('conteudo')
    <div class="w-full max-w-7xl">
        <h1 class="text-xl font-semibold text-slate-900 dark:text-zinc-100">Recibos</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">Um recibo regista um pagamento de uma fatura emitida: abre a fatura e escolhe «Registar recibo».</p>

        <form method="GET" action="{{ route('faturacao.recibos.index') }}" role="search" class="mt-4 flex flex-col gap-2 sm:flex-row">
            <label for="pesquisa-registos" class="sr-only">Pesquisar recibos</label>
            <input id="pesquisa-registos" type="search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Número do recibo, cliente, fatura ou meio de pagamento" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 sm:max-w-md">
            <label for="filtro-estado" class="sr-only">Estado do recibo</label>
            <select id="filtro-estado" name="estado" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                <option value="">Todos os estados</option>
                <option value="emitido" @selected(request('estado') === 'emitido')>Emitido</option>
            </select>
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-zinc-100 dark:text-zinc-900">Pesquisar</button>
            @if (request()->anyFilled(['q', 'estado']))
                <a href="{{ route('faturacao.recibos.index') }}" class="rounded-md border border-slate-300 px-4 py-2 text-center text-sm font-medium text-slate-600 dark:border-zinc-700 dark:text-zinc-300">Limpar</a>
            @endif
        </form>

        @if (session('erro'))
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ session('erro') }}</div>
        @endif

        <div class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white dark:bg-zinc-900 dark:border-zinc-800 dark:divide-zinc-800">
            @forelse ($recibos as $recibo)
                <a href="{{ route('faturacao.recibos.mostrar', $recibo->id) }}" class="flex items-center justify-between px-4 py-3 hover:bg-slate-50">
                    <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-zinc-100">{{ $recibo->numero_documento ?? 'Recibo #'.$recibo->id }}</p>
                        <p class="text-xs text-slate-500 dark:text-zinc-400">
                            {{ $recibo->cliente->nome }}
                            @if ($recibo->fatura) — fatura {{ $recibo->fatura->numero_documento }} @endif
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-medium text-slate-900 dark:text-zinc-100">{{ number_format((float) $recibo->valor, 2, ',', ' ') }} {{ $recibo->moeda }}</p>
                        <span class="text-xs text-slate-500 dark:text-zinc-400">{{ config('faturacao.meios_pagamento.'.$recibo->meio_pagamento, $recibo->meio_pagamento) }}</span>
                        @if (config('faturacao.agt.ativo') && $recibo->estaEmitido())
                            <span class="block text-xs text-slate-400">AGT: {{ $recibo->agtSubmissao?->rotulo() ?? 'sem registo de envio' }}</span>
                        @endif
                    </div>
                </a>
            @empty
                <p class="px-4 py-6 text-center text-sm text-slate-500 dark:text-zinc-400">Ainda não tens recibos.</p>
            @endforelse
        </div>

        {{ $recibos->links() }}
    </div>
@endsection
