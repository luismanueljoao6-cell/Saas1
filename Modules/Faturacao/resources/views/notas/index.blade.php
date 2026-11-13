@extends('core::layouts.app')

@section('titulo', 'Notas de crédito e débito')

@section('conteudo')
    <div class="w-full max-w-7xl">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900 dark:text-zinc-100">Notas de crédito e débito</h1>
            <a href="{{ route('faturacao.notas.criar', ['tipo' => 'debito']) }}"
               class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                Nova nota de débito
            </a>
        </div>
        <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">Uma nota de crédito parte sempre de uma fatura emitida: abre a fatura e escolhe «Nota de crédito».</p>

        <form method="GET" action="{{ route('faturacao.notas.index') }}" role="search" class="mt-4 flex flex-col gap-2 sm:flex-row">
            <label for="pesquisa-registos" class="sr-only">Pesquisar notas</label>
            <input id="pesquisa-registos" type="search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Número, cliente ou motivo" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 sm:max-w-md">
            <label for="filtro-tipo" class="sr-only">Tipo de nota</label>
            <select id="filtro-tipo" name="tipo" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                <option value="">Todos os tipos</option>
                <option value="credito" @selected(request('tipo') === 'credito')>Crédito</option>
                <option value="debito" @selected(request('tipo') === 'debito')>Débito</option>
            </select>
            <label for="filtro-estado" class="sr-only">Estado da nota</label>
            <select id="filtro-estado" name="estado" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                <option value="">Todos os estados</option>
                <option value="rascunho" @selected(request('estado') === 'rascunho')>Rascunho</option>
                <option value="emitida" @selected(request('estado') === 'emitida')>Emitida</option>
            </select>
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-zinc-100 dark:text-zinc-900">Pesquisar</button>
            @if (request()->filled('q'))
                <a href="{{ route('faturacao.notas.index') }}" class="rounded-md border border-slate-300 px-4 py-2 text-center text-sm font-medium text-slate-600 dark:border-zinc-700 dark:text-zinc-300">Limpar</a>
            @endif
        </form>

        @if (session('erro'))
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ session('erro') }}</div>
        @endif
        @if (session('sucesso'))
            <div class="mt-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
        @endif

        <div class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white dark:bg-zinc-900 dark:border-zinc-800 dark:divide-zinc-800">
            @forelse ($notas as $nota)
                <a href="{{ route('faturacao.notas.mostrar', $nota->id) }}" class="flex items-center justify-between px-4 py-3 hover:bg-slate-50">
                    <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-zinc-100">
                            {{ $nota->numero_documento ?? 'Rascunho #'.$nota->id }}
                            <span class="ml-1 text-xs font-normal text-slate-500 dark:text-zinc-400">{{ $nota->tipo === 'credito' ? 'Crédito' : 'Débito' }}</span>
                        </p>
                        <p class="text-xs text-slate-500 dark:text-zinc-400">{{ $nota->cliente->nome }} — {{ $nota->motivo }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-medium text-slate-900 dark:text-zinc-100">{{ number_format((float) $nota->valor_total, 2, ',', ' ') }} {{ $nota->moeda }}</p>
                        <span class="text-xs {{ $nota->estado === 'emitida' ? 'text-emerald-600' : 'text-amber-600' }}">{{ ucfirst($nota->estado) }}</span>
                        @if (config('faturacao.agt.ativo') && $nota->estado === 'emitida')
                            <span class="block text-xs text-slate-400">AGT: {{ $nota->agtSubmissao?->rotulo() ?? 'sem registo de envio' }}</span>
                        @endif
                    </div>
                </a>
            @empty
                <p class="px-4 py-6 text-center text-sm text-slate-500 dark:text-zinc-400">Ainda não tens notas de crédito ou débito.</p>
            @endforelse
        </div>

        {{ $notas->links() }}
    </div>
@endsection
