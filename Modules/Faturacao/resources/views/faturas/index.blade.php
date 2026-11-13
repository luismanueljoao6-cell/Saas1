@extends('core::layouts.app')

@section('titulo', 'Faturas')

@section('conteudo')
    <div class="w-full max-w-7xl">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900 dark:text-zinc-100">Faturas</h1>
            @if ($podeCriarFaturas)
                <a href="{{ route('faturacao.faturas.criar') }}"
                   class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    Nova fatura
                </a>
            @else
                <span class="rounded-md bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-200" role="status">
                    Emissão suspensa — regulariza a subscrição
                </span>
            @endif
        </div>

        <form method="GET" action="{{ route('faturacao.faturas.index') }}" role="search" class="mt-4 flex flex-col gap-2 sm:flex-row">
            <label for="pesquisa-registos" class="sr-only">Pesquisar faturas</label>
            <input id="pesquisa-registos" type="search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Número do documento ou nome do cliente" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 sm:max-w-md">
            <label for="filtro-estado" class="sr-only">Estado da fatura</label>
            <select id="filtro-estado" name="estado" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                <option value="">Todos os estados</option>
                <option value="rascunho" @selected(request('estado') === 'rascunho')>Rascunho</option>
                <option value="emitida" @selected(request('estado') === 'emitida')>Emitida</option>
            </select>
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-zinc-100 dark:text-zinc-900">Pesquisar</button>
            @if (request()->anyFilled(['q', 'estado']))
                <a href="{{ route('faturacao.faturas.index') }}" class="rounded-md border border-slate-300 px-4 py-2 text-center text-sm font-medium text-slate-600 dark:border-zinc-700 dark:text-zinc-300">Limpar</a>
            @endif
        </form>

        @if (session('erro'))
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ session('erro') }}</div>
        @endif

        <div class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white dark:bg-zinc-900 dark:border-zinc-800 dark:divide-zinc-800">
            @forelse ($faturas as $fatura)
                <a href="{{ route('faturacao.faturas.mostrar', $fatura) }}" class="flex items-center justify-between px-4 py-3 hover:bg-slate-50">
                    <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-zinc-100">
                            {{ $fatura->numero_documento ?? 'Rascunho #'.$fatura->id }}
                        </p>
                        <p class="text-xs text-slate-500 dark:text-zinc-400">{{ $fatura->cliente->nome }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-medium text-slate-900 dark:text-zinc-100">{{ number_format((float) $fatura->valor_total, 2, ',', ' ') }} {{ $fatura->moeda }}</p>
                        <span class="text-xs {{ $fatura->estado === 'emitida' ? 'text-emerald-600' : 'text-amber-600' }}">
                            {{ ucfirst($fatura->estado) }}
                        </span>
                        @if (config('faturacao.agt.ativo') && $fatura->estado === 'emitida')
                            <span class="block text-xs text-slate-400">AGT: {{ $fatura->agtSubmissao?->rotulo() ?? 'sem registo de envio' }}</span>
                        @endif
                    </div>
                </a>
            @empty
                <p class="px-4 py-6 text-center text-sm text-slate-500 dark:text-zinc-400">Ainda não tens faturas.</p>
            @endforelse
        </div>

        {{ $faturas->links() }}
    </div>
@endsection
