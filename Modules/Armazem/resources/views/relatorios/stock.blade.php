@extends('core::layouts.app')

@section('titulo', 'Relatórios de stock')

@section('conteudo')
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-zinc-50">Relatórios de stock</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">Consulta saldos por armazém, acompanha movimentos e identifica artigos abaixo do mínimo definido.</p>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-500/20 dark:bg-amber-500/10">
                <p class="text-xs font-medium uppercase tracking-wide text-amber-700 dark:text-amber-300">Alertas de stock mínimo</p>
                <p class="mt-1 text-2xl font-bold text-amber-800 dark:text-amber-200">{{ $totalAlertas }}</p>
            </div>
        </div>

        @if (session('sucesso'))
            <div role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('sucesso') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">{{ $errors->first() }}</div>
        @endif

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="font-semibold text-slate-900 dark:text-zinc-100">Definir stock mínimo</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">O alerta é apresentado quando o saldo fica igual ou inferior ao limite configurado. O limite é independente para cada produto e armazém.</p>
            <form method="POST" action="{{ route('armazem.stock.minimos.guardar') }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                @csrf
                <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Armazém
                    <select name="armazem_id" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                        <option value="">Selecionar armazém</option>
                        @foreach ($armazens as $armazem)
                            <option value="{{ $armazem->id }}" @selected(old('armazem_id') == $armazem->id)>{{ $armazem->nome }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Produto
                    <select name="produto_id" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                        <option value="">Selecionar produto</option>
                        @foreach ($produtos as $produto)
                            <option value="{{ $produto->id }}" @selected(old('produto_id') == $produto->id)>{{ $produto->nome }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Quantidade mínima
                    <input name="quantidade_minima" type="number" min="0" max="99999999999.999" step="0.001" required value="{{ old('quantidade_minima') }}" placeholder="Ex.: 5.000" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                </label>
                <div class="sm:col-span-3">
                    <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Guardar limite</button>
                </div>
            </form>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="font-semibold text-slate-900 dark:text-zinc-100">Filtros de consulta</h2>
            <form method="GET" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Armazém
                    <select name="armazem_id" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                        <option value="">Todos</option>
                        @foreach ($armazens as $armazem)
                            <option value="{{ $armazem->id }}" @selected(($filtros['armazem_id'] ?? '') == $armazem->id)>{{ $armazem->nome }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Produto
                    <input name="pesquisa" value="{{ $filtros['pesquisa'] ?? '' }}" maxlength="100" placeholder="Pesquisar produto" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                </label>
                <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Movimentos desde
                    <input type="date" name="data_inicio" value="{{ $inicio }}" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                </label>
                <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Até
                    <input type="date" name="data_fim" value="{{ $fim }}" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                </label>
                <label class="flex items-center gap-2 self-end pb-2 text-sm text-slate-700 dark:text-zinc-300">
                    <input type="checkbox" name="abaixo_minimo" value="1" @checked(($filtros['abaixo_minimo'] ?? false) == '1') class="rounded border-slate-300 text-indigo-600">
                    Só abaixo do mínimo
                </label>
                <div class="flex gap-2 sm:col-span-2 xl:col-span-5">
                    <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white dark:bg-zinc-700">Aplicar filtros</button>
                    <a href="{{ route('armazem.relatorios.stock') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-zinc-700 dark:text-zinc-300">Limpar</a>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="border-b border-slate-100 px-5 py-4 dark:border-zinc-800">
                <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-semibold text-slate-900 dark:text-zinc-100">Saldos por armazém</h2><p class="mt-1 text-xs text-slate-500 dark:text-zinc-400">Exporta os resultados completos dos filtros atuais para CSV, compatível com Excel.</p></div><a href="{{ route('armazem.relatorios.stock.exportar', request()->query()) }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Exportar CSV</a></div>
                <p class="mt-1 text-xs text-slate-500 dark:text-zinc-400">Os limites configurados são comparados com o saldo atual. Produtos sem limite definido aparecem sem alerta.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm dark:divide-zinc-800">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-zinc-950 dark:text-zinc-400">
                        <tr><th class="px-5 py-3">Produto</th><th class="px-5 py-3">Armazém</th><th class="px-5 py-3">Saldo</th><th class="px-5 py-3">Mínimo</th><th class="px-5 py-3">Estado</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                        @forelse ($saldos as $saldo)
                            <tr>
                                <td class="px-5 py-3 font-medium text-slate-900 dark:text-zinc-100">{{ $saldo->produto_nome }}</td>
                                <td class="px-5 py-3 text-slate-600 dark:text-zinc-300">{{ $saldo->armazem_nome }}</td>
                                <td class="px-5 py-3 tabular-nums">{{ number_format((float) $saldo->quantidade, 3, ',', '.') }}</td>
                                <td class="px-5 py-3 tabular-nums">{{ $saldo->quantidade_minima === null ? 'Não definido' : number_format((float) $saldo->quantidade_minima, 3, ',', '.') }}</td>
                                <td class="px-5 py-3">
                                    @if ($saldo->abaixo_minimo)
                                        <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">Repor stock</span>
                                    @else
                                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Normal</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-8 text-center text-slate-500 dark:text-zinc-400">Não existem saldos para os filtros selecionados.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 px-5 py-3 dark:border-zinc-800">{{ $saldos->links() }}</div>
        </section>

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="border-b border-slate-100 px-5 py-4 dark:border-zinc-800">
                <h2 class="font-semibold text-slate-900 dark:text-zinc-100">Histórico de movimentos</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-zinc-400">Período: {{ \Illuminate\Support\Carbon::parse($inicio)->format('d/m/Y') }} a {{ \Illuminate\Support\Carbon::parse($fim)->format('d/m/Y') }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm dark:divide-zinc-800">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-zinc-950 dark:text-zinc-400">
                        <tr><th class="px-5 py-3">Data</th><th class="px-5 py-3">Produto / armazém</th><th class="px-5 py-3">Tipo</th><th class="px-5 py-3">Variação</th><th class="px-5 py-3">Saldo final</th><th class="px-5 py-3">Motivo / utilizador</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                        @forelse ($movimentos as $movimento)
                            <tr>
                                <td class="whitespace-nowrap px-5 py-3 text-slate-600 dark:text-zinc-300">{{ $movimento->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-3"><p class="font-medium text-slate-900 dark:text-zinc-100">{{ $movimento->produto?->nome ?? 'Produto removido' }}</p><p class="text-xs text-slate-500 dark:text-zinc-400">{{ $movimento->armazem?->nome ?? 'Armazém removido' }}</p></td>
                                <td class="px-5 py-3">{{ ucfirst($movimento->tipo) }}</td>
                                <td class="px-5 py-3 tabular-nums {{ str_starts_with((string) $movimento->quantidade, '-') ? 'text-rose-600' : 'text-emerald-600' }}">{{ number_format((float) $movimento->quantidade, 3, ',', '.') }}</td>
                                <td class="px-5 py-3 tabular-nums">{{ number_format((float) $movimento->saldo_posterior, 3, ',', '.') }}</td>
                                <td class="px-5 py-3"><p>{{ $movimento->motivo }}</p><p class="text-xs text-slate-500 dark:text-zinc-400">{{ $movimento->utilizador?->name ?? 'Sistema' }}</p></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-8 text-center text-slate-500 dark:text-zinc-400">Não existem movimentos neste período.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 px-5 py-3 dark:border-zinc-800">{{ $movimentos->links() }}</div>
        </section>
    </div>
@endsection
