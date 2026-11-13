@extends('core::layouts.app')

@section('titulo', 'Recibos')

@section('conteudo')
    <div class="w-full max-w-7xl">
        <h1 class="text-xl font-semibold text-slate-900 dark:text-zinc-100">Recibos</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">Um recibo regista um pagamento de uma fatura emitida: abre a fatura e escolhe «Registar recibo».</p>

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
