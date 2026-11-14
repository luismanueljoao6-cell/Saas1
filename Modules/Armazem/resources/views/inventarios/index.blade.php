@extends('core::layouts.app')

@section('titulo', 'Inventários físicos')

@section('conteudo')
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-zinc-50">Inventários físicos</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">Conta produtos por armazém e reconcilia diferenças com movimentos auditáveis.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('armazem.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800">Armazéns</a>
                <a href="{{ route('armazem.transferencias.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800">Transferências</a>
            </div>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">{{ $errors->first() }}</div>
        @endif

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div>
                <h2 class="font-semibold text-slate-900 dark:text-zinc-100">Abrir novo inventário</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">Seleciona um armazém e os produtos que serão contados nesta sessão.</p>
            </div>
            <form method="POST" action="{{ route('armazem.inventarios.store') }}" class="mt-4 space-y-4">
                @csrf
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Armazém
                        <select name="armazem_id" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                            <option value="">Selecionar armazém</option>
                            @foreach ($armazens as $armazem)
                                <option value="{{ $armazem->id }}" @selected(old('armazem_id') == $armazem->id)>{{ $armazem->codigo }} — {{ $armazem->nome }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Produtos a contar
                        <select name="produto_ids[]" multiple size="5" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                            @foreach ($produtos as $produto)
                                <option value="{{ $produto->id }}" @selected(collect(old('produto_ids', []))->contains($produto->id))>{{ $produto->nome }}</option>
                            @endforeach
                        </select>
                        <span class="mt-1 block text-xs font-normal text-slate-500 dark:text-zinc-400">Usa Ctrl/Command para selecionar vários produtos num computador; no telemóvel, mantém a seleção pressionada.</span>
                    </label>
                    <label class="text-sm font-medium text-slate-700 dark:text-zinc-300 md:col-span-2">Observações (opcional)
                        <input name="observacoes" maxlength="2000" value="{{ old('observacoes') }}" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950" placeholder="Notas sobre esta contagem">
                    </label>
                </div>
                <button type="submit" @disabled($armazens->isEmpty() || $produtos->isEmpty()) class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">Abrir inventário</button>
            </form>
        </section>

        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-zinc-100">Histórico de inventários</h2>
            <span class="text-sm text-slate-500 dark:text-zinc-400">{{ $inventarios->total() }} registos</span>
        </div>

        @forelse ($inventarios as $inventario)
            <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 dark:border-zinc-800">
                    <div>
                        <p class="font-semibold text-slate-900 dark:text-zinc-100">INV-{{ str_pad((string) $inventario->id, 5, '0', STR_PAD_LEFT) }} · {{ $inventario->armazem?->nome }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-zinc-400">Aberto em {{ $inventario->aberto_em?->format('d/m/Y H:i') }} · {{ $inventario->utilizadorAbertura?->name ?? 'Sistema' }}</p>
                    </div>
                    @php
                        $estadoCor = [
                            'em_contagem' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
                            'a_finalizar' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
                            'concluido' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
                            'cancelado' => 'bg-slate-100 text-slate-600 dark:bg-zinc-800 dark:text-zinc-400',
                        ][$inventario->estado] ?? 'bg-slate-100 text-slate-600';
                    @endphp
                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $estadoCor }}">{{ str_replace('_', ' ', ucfirst($inventario->estado)) }}</span>
                </div>

                <div class="p-5">
                    @if ($inventario->observacoes)
                        <p class="mb-3 text-sm text-slate-500 dark:text-zinc-400">{{ $inventario->observacoes }}</p>
                    @endif
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100 text-sm dark:divide-zinc-800">
                            <thead class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-zinc-400">
                                <tr>
                                    <th class="py-3 pr-4">Produto</th>
                                    <th class="px-3 py-3 text-right">Sistema</th>
                                    <th class="px-3 py-3">Contagem física</th>
                                    <th class="px-3 py-3">Registada por</th>
                                    <th class="px-3 py-3 text-right">Diferença</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                                @foreach ($inventario->linhas as $linha)
                                    <tr>
                                        <td class="py-3 pr-4 font-medium text-slate-800 dark:text-zinc-200">{{ $linha->produto?->nome ?? 'Produto indisponível' }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums text-slate-600 dark:text-zinc-300">{{ number_format((float) $linha->quantidade_sistema, 3, ',', '.') }}</td>
                                        <td class="px-3 py-3">
                                            @if ($inventario->estado === 'em_contagem')
                                                <form class="flex min-w-44 items-center gap-2" method="POST" action="{{ route('armazem.inventarios.contar', [$inventario->id, $linha->id]) }}">
                                                    @csrf
                                                    <input class="w-28 rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-950" type="number" name="quantidade_contada" min="0" step="0.001" value="{{ $linha->quantidade_contada }}" required aria-label="Quantidade contada de {{ $linha->produto?->nome }}">
                                                    <button class="rounded-lg border border-indigo-300 px-2.5 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-500/40 dark:text-indigo-300 dark:hover:bg-indigo-500/10" type="submit">Guardar</button>
                                                </form>
                                            @else
                                                <span class="tabular-nums text-slate-600 dark:text-zinc-300">{{ $linha->quantidade_contada === null ? '—' : number_format((float) $linha->quantidade_contada, 3, ',', '.') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-xs text-slate-500 dark:text-zinc-400">
                                            @if ($linha->contado_em)
                                                {{ $linha->utilizadorContagem?->name ?? 'Utilizador removido' }}
                                                <span class="block">{{ $linha->contado_em->format('d/m/Y H:i') }}</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-right tabular-nums">
                                            @if ($linha->diferenca !== null)
                                                <span class="{{ (float) $linha->diferenca < 0 ? 'text-red-600 dark:text-red-400' : ((float) $linha->diferenca > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500') }}">{{ number_format((float) $linha->diferenca, 3, ',', '.') }}</span>
                                            @else
                                                <span class="text-slate-400">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($inventario->estado === 'em_contagem')
                        <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4 dark:border-zinc-800">
                            <form method="POST" action="{{ route('armazem.inventarios.concluir', $inventario->id) }}">
                                @csrf
                                <button class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-500" type="submit">Concluir e aplicar ajustes</button>
                            </form>
                            <form method="POST" action="{{ route('armazem.inventarios.cancelar', $inventario->id) }}" onsubmit="return confirm('Cancelar este inventário? Nenhum ajuste será aplicado.')">
                                @csrf
                                <button class="rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 dark:border-red-500/40 dark:text-red-300 dark:hover:bg-red-500/10" type="submit">Cancelar</button>
                            </form>
                        </div>
                    @endif
                </div>
            </section>
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 bg-white px-5 py-10 text-center text-sm text-slate-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">Ainda não existem inventários físicos.</div>
        @endforelse

        <div>{{ $inventarios->links() }}</div>
    </div>
@endsection
