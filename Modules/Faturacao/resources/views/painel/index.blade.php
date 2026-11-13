@extends('core::layouts.app')

@section('titulo', 'Visão geral da faturação')

@section('conteudo')
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-white">Visão geral</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">Resumo da atividade de faturação deste mês.</p>
            </div>
            <a href="{{ route('faturacao.faturas.criar') }}"
               class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                Nova fatura
            </a>
        </div>

        <nav aria-label="Acessos rápidos" class="flex flex-wrap gap-2">
            <a href="{{ route('faturacao.orcamentos.index') }}" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium hover:bg-slate-50 dark:border-zinc-700 dark:hover:bg-zinc-800">Orçamentos</a>
            <a href="{{ route('faturacao.clientes.index') }}" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium hover:bg-slate-50 dark:border-zinc-700 dark:hover:bg-zinc-800">Clientes</a>
            <a href="{{ route('faturacao.produtos.index') }}" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium hover:bg-slate-50 dark:border-zinc-700 dark:hover:bg-zinc-800">Produtos</a>
            @if (auth()->user()->hasRole('Administrador'))
                <a href="{{ route('faturacao.stock.index') }}" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium hover:bg-slate-50 dark:border-zinc-700 dark:hover:bg-zinc-800">Stock</a>
                <a href="{{ route('faturacao.compras.index') }}" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium hover:bg-slate-50 dark:border-zinc-700 dark:hover:bg-zinc-800">Compras</a>
                <a href="{{ route('faturacao.fornecedores.index') }}" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium hover:bg-slate-50 dark:border-zinc-700 dark:hover:bg-zinc-800">Fornecedores</a>
            @endif
        </nav>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="text-sm text-slate-500 dark:text-zinc-400">Documentos emitidos este mês</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format($resumo['documentos_emitidos_mes'], 0, ',', ' ') }}</p>
            </section>
            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="text-sm text-slate-500 dark:text-zinc-400">Total faturado este mês</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format((float) $resumo['total_faturado_mes'], 2, ',', ' ') }} AOA</p>
            </section>
            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="text-sm text-slate-500 dark:text-zinc-400">Recebido em recibos este mês</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format((float) $resumo['recebido_mes'], 2, ',', ' ') }} AOA</p>
            </section>
            <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="text-sm text-slate-500 dark:text-zinc-400">Rascunhos por concluir</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format($resumo['rascunhos'], 0, ',', ' ') }}</p>
            </section>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-zinc-800">
                    <h2 class="font-semibold text-slate-900 dark:text-white">Documentos recentes</h2>
                    <a href="{{ route('faturacao.faturas.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">Ver todos</a>
                </div>
                <div class="divide-y divide-slate-100 dark:divide-zinc-800">
                    @forelse ($faturasRecentes as $fatura)
                        <a href="{{ route('faturacao.faturas.mostrar', $fatura->id) }}" class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-slate-50 dark:hover:bg-zinc-800/60">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $fatura->numero_documento ?? 'Rascunho #'.$fatura->id }}</p>
                                <p class="truncate text-xs text-slate-500 dark:text-zinc-400">{{ $fatura->cliente?->nome ?? 'Cliente indisponível' }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ number_format((float) $fatura->valor_total, 2, ',', ' ') }} AOA</p>
                                <p class="text-xs text-slate-500 dark:text-zinc-400">{{ ucfirst($fatura->estado) }}</p>
                            </div>
                        </a>
                    @empty
                        <p class="px-5 py-8 text-center text-sm text-slate-500 dark:text-zinc-400">Ainda não existem documentos.</p>
                    @endforelse
                </div>
            </section>

            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-zinc-800">
                    <h2 class="font-semibold text-slate-900 dark:text-white">Recibos recentes</h2>
                    <a href="{{ route('faturacao.recibos.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">Ver todos</a>
                </div>
                <div class="divide-y divide-slate-100 dark:divide-zinc-800">
                    @forelse ($recibosRecentes as $recibo)
                        <a href="{{ route('faturacao.recibos.mostrar', $recibo->id) }}" class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-slate-50 dark:hover:bg-zinc-800/60">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $recibo->numero_documento ?? 'Recibo #'.$recibo->id }}</p>
                                <p class="truncate text-xs text-slate-500 dark:text-zinc-400">{{ $recibo->cliente?->nome ?? 'Cliente indisponível' }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ number_format((float) $recibo->valor, 2, ',', ' ') }} AOA</p>
                                <p class="text-xs text-slate-500 dark:text-zinc-400">{{ ucfirst($recibo->estado) }}</p>
                            </div>
                        </a>
                    @empty
                        <p class="px-5 py-8 text-center text-sm text-slate-500 dark:text-zinc-400">Ainda não existem recibos.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
@endsection
