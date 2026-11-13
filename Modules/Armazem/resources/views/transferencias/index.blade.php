@extends('core::layouts.app')

@section('titulo', 'Transferências entre armazéns')

@section('conteudo')
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-zinc-50">Transferências</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">Move produtos entre armazéns com registo de expedição, trânsito e receção.</p>
            </div>
            <a href="{{ route('armazem.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800">Gerir armazéns</a>
        </div>

        @if (session('sucesso'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('sucesso') }}</div>
        @endif
        @if (session('erro'))
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">{{ session('erro') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">{{ $errors->first() }}</div>
        @endif

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="font-semibold text-slate-900 dark:text-zinc-100">Nova transferência</h2>
            <form method="POST" action="{{ route('armazem.transferencias.store') }}" class="mt-4 space-y-4">
                @csrf
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Armazém de origem
                        <select name="armazem_origem_id" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                            <option value="">Selecionar origem</option>
                            @foreach ($armazens as $armazem)
                                <option value="{{ $armazem->id }}" @selected(old('armazem_origem_id') == $armazem->id)>{{ $armazem->nome }}{{ $armazem->principal ? ' (Principal)' : '' }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Armazém de destino
                        <select name="armazem_destino_id" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                            <option value="">Selecionar destino</option>
                            @foreach ($armazens as $armazem)
                                <option value="{{ $armazem->id }}" @selected(old('armazem_destino_id') == $armazem->id)>{{ $armazem->nome }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-medium text-slate-700 dark:text-zinc-300 md:col-span-2">Motivo
                        <input name="motivo" required maxlength="255" value="{{ old('motivo') }}" placeholder="Ex.: Reposição da loja" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                    </label>
                    <label class="text-sm font-medium text-slate-700 dark:text-zinc-300 md:col-span-2">Observações
                        <textarea name="observacoes" rows="2" maxlength="2000" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950" placeholder="Notas adicionais (opcional)">{{ old('observacoes') }}</textarea>
                    </label>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-slate-800 dark:text-zinc-200">Produtos e quantidades</h3>
                        <button type="button" id="adicionar-linha" class="rounded-lg border border-indigo-300 px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-500/40 dark:text-indigo-300 dark:hover:bg-indigo-500/10">+ Adicionar produto</button>
                    </div>
                    <div id="linhas-transferencia" class="space-y-2">
                        <div class="grid grid-cols-1 gap-2 rounded-lg bg-slate-50 p-3 sm:grid-cols-[1fr_160px_auto] dark:bg-zinc-950">
                            <select name="linhas[0][produto_id]" required class="min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900">
                                <option value="">Selecionar produto</option>
                                @foreach ($produtos as $produto)
                                    <option value="{{ $produto->id }}">{{ $produto->nome }} · #{{ $produto->id }}</option>
                                @endforeach
                            </select>
                            <input type="number" name="linhas[0][quantidade]" required min="0.001" step="0.001" placeholder="Quantidade" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900">
                            <span class="self-center text-xs text-slate-500">Até 3 casas decimais</span>
                        </div>
                    </div>
                </div>
                <button class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500">Guardar rascunho</button>
            </form>
        </section>

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="border-b border-slate-100 px-5 py-4 dark:border-zinc-800">
                <h2 class="font-semibold text-slate-900 dark:text-zinc-100">Histórico de transferências</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm dark:divide-zinc-800">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-zinc-950 dark:text-zinc-400">
                        <tr><th class="px-4 py-3">Referência</th><th class="px-4 py-3">Percurso</th><th class="px-4 py-3">Produtos</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3">Data</th><th class="px-4 py-3">Ações</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                        @forelse ($transferencias as $transferencia)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-4 font-semibold text-slate-900 dark:text-zinc-100">TR-{{ str_pad((string) $transferencia->id, 6, '0', STR_PAD_LEFT) }}<p class="mt-1 max-w-48 truncate text-xs font-normal text-slate-500">{{ $transferencia->motivo }}</p></td>
                                <td class="min-w-48 px-4 py-4 text-slate-700 dark:text-zinc-300"><span>{{ $transferencia->origem?->nome ?? '—' }}</span><span class="mx-1 text-slate-400">→</span><span>{{ $transferencia->destino?->nome ?? '—' }}</span></td>
                                <td class="px-4 py-4 text-slate-600 dark:text-zinc-300">{{ $transferencia->linhas->count() }} {{ $transferencia->linhas->count() === 1 ? 'produto' : 'produtos' }}<p class="mt-1 text-xs text-slate-400">{{ $transferencia->rececoes->count() }} {{ $transferencia->rececoes->count() === 1 ? 'receção' : 'receções' }}</p></td>
                                <td class="px-4 py-4">
                                    @php($estado = ['rascunho' => 'Rascunho', 'em_transito' => 'Em trânsito', 'parcialmente_recebida' => 'Parcialmente recebida', 'recebida' => 'Recebida', 'recebida_com_divergencia' => 'Recebida com divergência', 'cancelada' => 'Cancelada'][$transferencia->estado] ?? $transferencia->estado)
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $transferencia->estado === 'recebida' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : (in_array($transferencia->estado, ['em_transito', 'parcialmente_recebida'], true) ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : ($transferencia->estado === 'recebida_com_divergencia' ? 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300' : 'bg-slate-100 text-slate-600 dark:bg-zinc-800 dark:text-zinc-300')) }}">{{ $estado }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-4 text-slate-500 dark:text-zinc-400">{{ $transferencia->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        @if ($transferencia->estado === 'rascunho')
                                            <form method="POST" action="{{ route('armazem.transferencias.expedir', $transferencia) }}">@csrf<button class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white">Expedir</button></form>
                                            <form method="POST" action="{{ route('armazem.transferencias.cancelar', $transferencia) }}">@csrf<button class="rounded-md border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-600">Cancelar</button></form>
                                        @elseif (in_array($transferencia->estado, ['em_transito', 'parcialmente_recebida'], true))
                                            <span class="text-xs text-slate-500">Usa o formulário de receção abaixo.</span>
                                        @else
                                            <span class="text-xs text-slate-400">Sem ações disponíveis</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @if (in_array($transferencia->estado, ['em_transito', 'parcialmente_recebida'], true))
                                <tr class="bg-slate-50/70 dark:bg-zinc-950/50">
                                    <td colspan="6" class="px-4 py-4">
                                        <details class="group">
                                            <summary class="cursor-pointer text-sm font-semibold text-indigo-700 dark:text-indigo-300">Registar receção parcial ou finalizar · {{ $transferencia->linhas->count() }} linha(s)</summary>
                                            @if ($transferencia->rececoes->isNotEmpty())
                                                <div class="mt-3 rounded-lg border border-slate-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                                                    <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400">Histórico de receções</h4>
                                                    <div class="mt-2 space-y-2">
                                                        @foreach ($transferencia->rececoes->sortByDesc('created_at') as $rececao)
                                                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 text-xs last:border-0 last:pb-0 dark:border-zinc-800">
                                                                <div><strong class="text-slate-800 dark:text-zinc-200">TRC-{{ $rececao->id }}</strong><span class="ml-2 text-slate-500">{{ $rececao->created_at?->format('d/m/Y H:i') }} · {{ $rececao->utilizador?->name ?? 'Utilizador removido' }}</span></div>
                                                                <span class="rounded-full px-2 py-1 {{ $rececao->finalizada ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' }}">{{ $rececao->finalizada ? 'Finalizada' : 'Parcial' }}</span>
                                                            </div>
                                                            @if ($rececao->linhas->isNotEmpty())
                                                                <div class="ml-2 text-[11px] text-slate-500 dark:text-zinc-400">
                                                                    @foreach ($rececao->linhas as $rececaoLinha)
                                                                        <span class="mr-3">{{ $rececaoLinha->linhaTransferencia?->produto?->nome ?? 'Produto' }}: +{{ number_format((float) $rececaoLinha->quantidade_recebida, 3, ',', '.') }} recebido · {{ number_format((float) $rececaoLinha->quantidade_em_falta, 3, ',', '.') }} falta · {{ number_format((float) $rececaoLinha->quantidade_danificada, 3, ',', '.') }} danificado</span>
                                                                    @endforeach
                                                                </div>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                            <form method="POST" action="{{ route('armazem.transferencias.recepcao-parcial', $transferencia) }}" class="mt-4 space-y-3">
                                                @csrf
                                                <input type="hidden" name="chave_idempotencia" value="{{ $chavesRececao[$transferencia->id] }}">
                                                <div class="overflow-x-auto">
                                                    <table class="min-w-full text-xs">
                                                        <thead class="text-left text-slate-500 dark:text-zinc-400">
                                                            <tr><th class="px-2 py-2">Produto</th><th class="px-2 py-2">Enviado</th><th class="px-2 py-2">Já recebido</th><th class="px-2 py-2">Recebido agora</th><th class="px-2 py-2">Em falta</th><th class="px-2 py-2">Danificado</th></tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach ($transferencia->linhas as $linha)
                                                                <tr class="border-t border-slate-200 dark:border-zinc-800">
                                                                    <td class="px-2 py-2 font-medium text-slate-800 dark:text-zinc-200">
                                                                        {{ $linha->produto?->nome ?? 'Produto #'.$linha->produto_id }}
                                                                        <input type="hidden" name="linhas[{{ $linha->id }}][linha_id]" value="{{ $linha->id }}">
                                                                    </td>
                                                                    <td class="px-2 py-2">{{ number_format((float) $linha->quantidade, 3, ',', '.') }}</td>
                                                                    <td class="px-2 py-2">{{ number_format((float) $linha->quantidade_recebida, 3, ',', '.') }}<span class="block text-[11px] text-slate-400">Falta {{ number_format((float) $linha->quantidade_em_falta, 3, ',', '.') }} · Danificado {{ number_format((float) $linha->quantidade_danificada, 3, ',', '.') }}</span></td>
                                                                    <td class="px-2 py-2"><input type="number" name="linhas[{{ $linha->id }}][quantidade_recebida]" value="0.000" min="0" step="0.001" required class="w-28 rounded-md border border-slate-300 bg-white px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900"></td>
                                                                    <td class="px-2 py-2"><input type="number" name="linhas[{{ $linha->id }}][quantidade_em_falta]" value="0.000" min="0" step="0.001" required class="w-28 rounded-md border border-slate-300 bg-white px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900"></td>
                                                                    <td class="px-2 py-2"><input type="number" name="linhas[{{ $linha->id }}][quantidade_danificada]" value="0.000" min="0" step="0.001" required class="w-28 rounded-md border border-slate-300 bg-white px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900"></td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <div class="flex flex-wrap items-center justify-between gap-3">
                                                    <p class="max-w-2xl text-xs text-slate-500 dark:text-zinc-400">Só a quantidade recebida é adicionada ao stock. Falta e dano ficam registados como divergências e não entram no saldo.</p>
                                                    <div class="flex flex-wrap gap-2">
                                                        <button name="finalizar" value="0" class="rounded-lg border border-indigo-300 px-3 py-2 text-xs font-semibold text-indigo-700 dark:border-indigo-500/40 dark:text-indigo-300">Guardar receção parcial</button>
                                                        <button name="finalizar" value="1" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">Finalizar e registar divergências</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </details>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500 dark:text-zinc-400">Ainda não existem transferências.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 px-5 py-3 dark:border-zinc-800">{{ $transferencias->links() }}</div>
        </section>
    </div>

    <script>
        (() => {
            const container = document.getElementById('linhas-transferencia');
            const adicionar = document.getElementById('adicionar-linha');
            if (!container || !adicionar) return;
            let indice = 1;
            const opcoes = @json($produtos->map(fn ($produto) => ['id' => $produto->id, 'nome' => $produto->nome])->values());
            adicionar.addEventListener('click', () => {
                const linha = document.createElement('div');
                linha.className = 'grid grid-cols-1 gap-2 rounded-lg bg-slate-50 p-3 sm:grid-cols-[1fr_160px_auto] dark:bg-zinc-950';
                const select = document.createElement('select');
                select.name = `linhas[${indice}][produto_id]`;
                select.required = true;
                select.className = 'min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900';
                const vazio = document.createElement('option');
                vazio.value = '';
                vazio.textContent = 'Selecionar produto';
                select.append(vazio);
                opcoes.forEach(produto => {
                    const option = document.createElement('option');
                    option.value = produto.id;
                    option.textContent = produto.nome + ' · #' + produto.id;
                    select.append(option);
                });
                const quantidade = document.createElement('input');
                quantidade.type = 'number';
                quantidade.name = `linhas[${indice}][quantidade]`;
                quantidade.required = true;
                quantidade.min = '0.001';
                quantidade.step = '0.001';
                quantidade.placeholder = 'Quantidade';
                quantidade.className = 'rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900';
                const remover = document.createElement('button');
                remover.type = 'button';
                remover.textContent = 'Remover';
                remover.className = 'text-sm font-medium text-rose-600';
                remover.addEventListener('click', () => linha.remove());
                linha.append(select, quantidade, remover);
                container.append(linha);
                indice++;
            });
        })();
    </script>
@endsection
