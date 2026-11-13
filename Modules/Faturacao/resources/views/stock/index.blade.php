@extends('core::layouts.app')

@section('titulo', 'Gestão de stock')

@section('conteudo')
    <div class="w-full space-y-5">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Gestão de stock</h1>
            <p class="mt-1 text-sm text-slate-500">Regista entradas, saídas e contagens físicas dos produtos que controlam stock.</p>
        </div>

        @if (session('sucesso'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                @foreach ($errors->all() as $erro)<p>{{ $erro }}</p>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('faturacao.stock.store') }}" class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
            @csrf
            <input type="hidden" name="chave_idempotencia" value="{{ $chaveIdempotencia }}">
            <h2 class="font-semibold text-slate-900 sm:col-span-2 lg:col-span-4">Registar movimento</h2>
            <label class="grid gap-1 text-sm text-slate-600">
                Produto
                <select name="produto_id" required class="rounded-md border border-slate-300 px-3 py-2">
                    <option value="">Seleciona um produto</option>
                    @foreach ($produtos as $produto)
                        <option value="{{ $produto->id }}" @selected(old('produto_id') == $produto->id)>{{ $produto->nome }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm text-slate-600">
                Tipo
                <select name="tipo" required class="rounded-md border border-slate-300 px-3 py-2">
                    <option value="entrada" @selected(old('tipo') === 'entrada')>Entrada</option>
                    <option value="saida" @selected(old('tipo') === 'saida')>Saída</option>
                    <option value="ajuste" @selected(old('tipo') === 'ajuste')>Ajuste / contagem física</option>
                </select>
            </label>
            <label class="grid gap-1 text-sm text-slate-600">
                Quantidade
                <input name="quantidade" type="number" min="0" step="0.001" value="{{ old('quantidade') }}" required class="rounded-md border border-slate-300 px-3 py-2">
                <span class="text-xs text-slate-400">No ajuste, indica o saldo físico final.</span>
            </label>
            <label class="grid gap-1 text-sm text-slate-600">
                Motivo
                <input name="motivo" maxlength="255" minlength="2" value="{{ old('motivo') }}" required placeholder="Ex.: compra, devolução, contagem" class="rounded-md border border-slate-300 px-3 py-2">
            </label>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white sm:col-span-2 lg:col-span-4">Registar movimento</button>
        </form>

        <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <h2 class="px-4 py-3 font-semibold text-slate-900">Saldos atuais</h2>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Produto</th><th class="px-4 py-3">Saldo</th><th class="px-4 py-3">Stock mínimo</th><th class="px-4 py-3">Estado</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($produtos as $produto)
                        @php($saldo = (float) ($produto->stockSaldo?->quantidade ?? 0))
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-900">{{ $produto->nome }}</td>
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
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Ainda não existem produtos configurados para controlar stock.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <h2 class="px-4 py-3 font-semibold text-slate-900">Histórico de movimentos</h2>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Data</th><th class="px-4 py-3">Produto</th><th class="px-4 py-3">Tipo</th><th class="px-4 py-3">Variação</th><th class="px-4 py-3">Saldo final</th><th class="px-4 py-3">Motivo</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
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
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Ainda não existem movimentos de stock.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="p-4">{{ $movimentos->links() }}</div>
        </section>
    </div>
@endsection
