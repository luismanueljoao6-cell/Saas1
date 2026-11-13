@extends('core::layouts.app')

@section('titulo', 'Gestão de stock')

@section('conteudo')
    <div class="w-full space-y-5">
        <div>
            <h1 class="text-xl font-semibold text-slate-900 dark:text-zinc-100">Gestão de stock</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">Regista entradas, saídas e contagens físicas dos produtos que controlam stock.</p>
        </div>

        @if (session('sucesso'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                @foreach ($errors->all() as $erro)<p>{{ $erro }}</p>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('faturacao.stock.store') }}" class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4 dark:bg-zinc-900 dark:border-zinc-800">
            @csrf
            <input type="hidden" name="chave_idempotencia" value="{{ $chaveIdempotencia }}">
            <h2 class="font-semibold text-slate-900 sm:col-span-2 lg:col-span-4 dark:text-zinc-100">Registar movimento</h2>
            <label class="grid gap-1 text-sm text-slate-600 dark:text-zinc-300">
                Produto
                <select name="produto_id" required class="rounded-md border border-slate-300 px-3 py-2 dark:border-zinc-700">
                    <option value="">Seleciona um produto</option>
                    @foreach ($produtos as $produto)
                        <option value="{{ $produto->id }}" @selected(old('produto_id') == $produto->id)>{{ $produto->nome }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm text-slate-600 dark:text-zinc-300">
                Tipo
                <select name="tipo" required class="rounded-md border border-slate-300 px-3 py-2 dark:border-zinc-700">
                    <option value="entrada" @selected(old('tipo') === 'entrada')>Entrada</option>
                    <option value="saida" @selected(old('tipo') === 'saida')>Saída</option>
                    <option value="ajuste" @selected(old('tipo') === 'ajuste')>Ajuste / contagem física</option>
                </select>
            </label>
            <label class="grid gap-1 text-sm text-slate-600 dark:text-zinc-300">
                Quantidade
                <input name="quantidade" type="number" min="0" step="0.001" value="{{ old('quantidade') }}" required class="rounded-md border border-slate-300 px-3 py-2 dark:border-zinc-700">
                <span class="text-xs text-slate-400">No ajuste, indica o saldo físico final.</span>
            </label>
            <label class="grid gap-1 text-sm text-slate-600 dark:text-zinc-300">
                Motivo
                <input name="motivo" maxlength="255" minlength="2" value="{{ old('motivo') }}" required placeholder="Ex.: compra, devolução, contagem" class="rounded-md border border-slate-300 px-3 py-2 dark:border-zinc-700">
            </label>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white sm:col-span-2 lg:col-span-4">Registar movimento</button>
        </form>

        <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:bg-zinc-900 dark:border-zinc-800">
            <h2 class="px-4 py-3 font-semibold text-slate-900 dark:text-zinc-100">Saldos atuais</h2>
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-zinc-800">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-zinc-950 dark:text-zinc-400"><tr><th class="px-4 py-3">Produto</th><th class="px-4 py-3">Saldo</th><th class="px-4 py-3">Stock mínimo</th><th class="px-4 py-3">Estado</th></tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                    @forelse ($produtos as $produto)
                        @php($saldo = (float) ($produto->stockSaldo?->quantidade ?? 0))
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-900 dark:text-zinc-100">{{ $produto->nome }}</td>
                            <td class="px-4 py-3">{{ number_format($saldo, 3, ',', '.') }} {{ $produto->unidade }}</td>
                            <td class="px-4 py-3">{{ number_format((float) $produto->stock_minimo, 3, ',', '.') }}</td>
                            <td class="px-4 py-3">
                                @if ($saldo <= (float) $produto->stock_minimo)
                                    <span class="rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700">Stock baixo</span>
                                @else
                                    <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">Disponível</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500 dark:text-zinc-400">Ainda não existem produtos configurados para controlar stock.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:bg-zinc-900 dark:border-zinc-800">
            <h2 class="px-4 py-3 font-semibold text-slate-900 dark:text-zinc-100">Histórico de movimentos</h2>
            <form method="GET" action="{{ route('faturacao.stock.index') }}" role="search" class="flex flex-col gap-2 px-4 pb-3 sm:flex-row">
                <label for="pesquisa-stock" class="sr-only">Pesquisar movimentos de stock</label>
                <input id="pesquisa-stock" type="search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Produto ou motivo do movimento" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 sm:max-w-md">
                <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white dark:bg-zinc-100 dark:text-zinc-900">Pesquisar</button>
                @if (request()->filled('q'))
                    <a href="{{ route('faturacao.stock.index') }}" class="rounded-md border border-slate-300 px-4 py-2 text-center text-sm dark:border-zinc-700 dark:text-zinc-300">Limpar</a>
                @endif
            </form>
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-zinc-800">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-zinc-950 dark:text-zinc-400"><tr><th class="px-4 py-3">Data</th><th class="px-4 py-3">Produto</th><th class="px-4 py-3">Tipo</th><th class="px-4 py-3">Variação</th><th class="px-4 py-3">Saldo final</th><th class="px-4 py-3">Motivo</th></tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                    @forelse ($movimentos as $movimento)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3">{{ $movimento->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3">{{ $movimento->produto?->nome ?? 'Produto indisponível' }}</td>
                            <td class="px-4 py-3">{{ ucfirst($movimento->tipo) }}</td>
                            <td class="px-4 py-3">{{ number_format((float) $movimento->quantidade, 3, ',', '.') }}</td>
                            <td class="px-4 py-3">{{ number_format((float) $movimento->saldo_posterior, 3, ',', '.') }}</td>
                            <td class="px-4 py-3">{{ $movimento->motivo }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500 dark:text-zinc-400">Ainda não existem movimentos de stock.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="p-4">{{ $movimentos->links() }}</div>
        </section>
    </div>
@endsection
