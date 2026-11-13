@extends('core::layouts.app')
@section('titulo', 'Editar orçamento '.$orcamento->numero)
@section('conteudo')
@php
    $linhasIniciais = old('linhas', $orcamento->linhas ?? []);
    $proximoIndice = empty($linhasIniciais) ? 0 : (max(array_keys($linhasIniciais)) + 1);
@endphp
<div class="mx-auto max-w-4xl space-y-6">
    <div>
        <h1 class="text-2xl font-semibold">Editar orçamento</h1>
        <p class="mt-1 text-sm text-slate-500">Só é possível editar documentos que continuam em rascunho. Esta proforma não tem valor fiscal.</p>
    </div>
    @if($errors->any())
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('faturacao.orcamentos.atualizar', $orcamento->id) }}" class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        @csrf
        @method('PUT')
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm">Cliente
                <select name="cliente_id" required class="mt-1 w-full rounded-lg border-slate-300 dark:border-zinc-700 dark:bg-zinc-800">
                    <option value="">Selecionar cliente</option>
                    @foreach($clientes as $cliente)
                        <option value="{{ $cliente->id }}" @selected(old('cliente_id', $orcamento->cliente_id)==$cliente->id)>{{ $cliente->nome }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm">Data de validade
                <input type="date" name="data_validade" min="{{ today()->toDateString() }}" value="{{ old('data_validade', $orcamento->data_validade?->toDateString()) }}" class="mt-1 w-full rounded-lg border-slate-300 dark:border-zinc-700 dark:bg-zinc-800">
            </label>
        </div>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <h2 class="font-semibold">Linhas</h2>
                <button type="button" id="adicionar-linha" class="text-sm text-indigo-600">Adicionar linha</button>
            </div>
            <div id="linhas" class="space-y-3">
                @foreach($linhasIniciais as $indice => $linha)
                    @php $taxaLinha = (float) ($linha['taxa_iva'] ?? $taxaPadrao); @endphp
                    <div class="linha-orcamento grid grid-cols-1 gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-12 dark:border-zinc-700">
                        <input name="linhas[{{ $indice }}][descricao]" required value="{{ $linha['descricao'] ?? '' }}" placeholder="Descrição" class="rounded-lg border-slate-300 dark:bg-zinc-800 sm:col-span-5">
                        <input name="linhas[{{ $indice }}][quantidade]" required type="number" min="0.001" step="0.001" value="{{ $linha['quantidade'] ?? 1 }}" placeholder="Qtd." class="rounded-lg border-slate-300 dark:bg-zinc-800 sm:col-span-2">
                        <input name="linhas[{{ $indice }}][preco_unitario]" required type="number" min="0" step="0.01" value="{{ $linha['preco_unitario'] ?? '' }}" placeholder="Preço unitário" class="rounded-lg border-slate-300 dark:bg-zinc-800 sm:col-span-3">
                        <select name="linhas[{{ $indice }}][taxa_iva]" class="campo-taxa rounded-lg border-slate-300 dark:bg-zinc-800 sm:col-span-2">
                            @foreach($taxasIva as $taxa)
                                <option value="{{ $taxa }}" @selected($taxaLinha === (float) $taxa)>{{ $taxa }}% IVA</option>
                            @endforeach
                        </select>
                        @if(!empty($linha['produto_id']))
                            <input type="hidden" name="linhas[{{ $indice }}][produto_id]" value="{{ $linha['produto_id'] }}">
                        @endif
                        <label class="text-xs sm:col-span-6">Tipo de operação AGT
                            <select name="linhas[{{ $indice }}][operation_type]" required class="campo-operacao mt-1 w-full rounded-lg border-slate-300 dark:bg-zinc-800">
                                @foreach($tiposOperacaoAgt as $tipo)
                                    <option value="{{ $tipo }}" @selected(strtoupper($linha['operation_type'] ?? 'SG') === $tipo)>{{ $tipo }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="campo-motivo text-xs sm:col-span-6" @if($taxaLinha > 0) hidden @endif>Motivo de isenção (obrigatório para IVA 0%)
                            <select name="linhas[{{ $indice }}][motivo_isencao]" class="mt-1 w-full rounded-lg border-slate-300 dark:bg-zinc-800" @if($taxaLinha <= 0) required @endif>
                                <option value="">Selecionar motivo</option>
                                @foreach($motivosIsencao as $codigo => $descricao)
                                    <option value="{{ $codigo }}" @selected(strtoupper($linha['motivo_isencao'] ?? '') === strtoupper((string) $codigo))>{{ $codigo }} — {{ $descricao }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button type="button" class="text-left text-sm text-red-600 sm:col-span-12" onclick="this.closest('.linha-orcamento').remove()">Remover linha</button>
                    </div>
                @endforeach
            </div>
        </div>

        <label class="block text-sm">Observações
            <textarea name="observacoes" rows="3" maxlength="1000" class="mt-1 w-full rounded-lg border-slate-300 dark:bg-zinc-800">{{ old('observacoes', $orcamento->observacoes) }}</textarea>
        </label>
        <div class="flex justify-end gap-3">
            <a href="{{ route('faturacao.orcamentos.mostrar', $orcamento->id) }}" class="rounded-lg border px-4 py-2 text-sm">Cancelar</a>
            <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Guardar alterações</button>
        </div>
    </form>
</div>
@push('scripts')
<script>
(() => {
    const lista = document.getElementById('linhas');
    let indice = {{ $proximoIndice }};
    const taxasIva = @json(array_values($taxasIva));
    const motivosIsencao = @json($motivosIsencao);
    const tiposOperacaoAgt = @json($tiposOperacaoAgt);

    function sincronizarMotivo(bloco) {
        const taxa = Number(bloco.querySelector('.campo-taxa').value);
        const campo = bloco.querySelector('.campo-motivo');
        const select = campo.querySelector('select');
        const isento = taxa === 0;
        campo.hidden = !isento;
        select.required = isento;
        if (!isento) select.value = '';
    }

    function criarSelect(nome, opcoes, texto, classe) {
        const label = document.createElement('label');
        label.className = 'text-xs sm:col-span-6';
        label.textContent = texto;
        const select = document.createElement('select');
        select.name = nome;
        select.required = true;
        select.className = classe + ' mt-1 w-full rounded-lg border-slate-300 dark:bg-zinc-800';
        Object.entries(opcoes).forEach(([valor, descricao]) => {
            const option = document.createElement('option');
            option.value = valor;
            option.textContent = descricao;
            select.appendChild(option);
        });
        label.appendChild(select);
        return label;
    }

    document.querySelectorAll('.linha-orcamento').forEach(bloco => {
        bloco.querySelector('.campo-taxa').addEventListener('change', () => sincronizarMotivo(bloco));
        sincronizarMotivo(bloco);
    });

    document.getElementById('adicionar-linha').addEventListener('click', () => {
        if (lista.querySelectorAll('.linha-orcamento').length >= 100) return;
        const bloco = document.createElement('div');
        bloco.className = 'linha-orcamento grid grid-cols-1 gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-12 dark:border-zinc-700';

        const campos = [
            ['descricao', 'text', 'Descrição', 'sm:col-span-5'],
            ['quantidade', 'number', 'Qtd.', 'sm:col-span-2'],
            ['preco_unitario', 'number', 'Preço unitário', 'sm:col-span-3']
        ];
        campos.forEach(([nome, tipo, placeholder, classe]) => {
            const input = document.createElement('input');
            input.name = 'linhas[' + indice + '][' + nome + ']';
            input.type = tipo;
            input.placeholder = placeholder;
            input.required = true;
            input.className = classe + ' rounded-lg border-slate-300 dark:bg-zinc-800';
            if (nome === 'quantidade') { input.min = '0.001'; input.step = '0.001'; input.value = '1'; }
            if (nome === 'preco_unitario') { input.min = '0'; input.step = '0.01'; }
            bloco.appendChild(input);
        });

        const taxa = document.createElement('select');
        taxa.name = 'linhas[' + indice + '][taxa_iva]';
        taxa.className = 'campo-taxa rounded-lg border-slate-300 dark:bg-zinc-800 sm:col-span-2';
        taxasIva.forEach(valor => {
            const option = document.createElement('option');
            option.value = valor;
            option.textContent = valor + '% IVA';
            if (Number(valor) === Number(@json($taxaPadrao))) option.selected = true;
            taxa.appendChild(option);
        });
        bloco.appendChild(taxa);

        const operacoes = {};
        tiposOperacaoAgt.forEach(tipo => { operacoes[tipo] = tipo; });
        bloco.appendChild(criarSelect('linhas[' + indice + '][operation_type]', operacoes, 'Tipo de operação AGT', 'campo-operacao'));

        const motivos = {'': 'Selecionar motivo'};
        Object.entries(motivosIsencao).forEach(([codigo, descricao]) => { motivos[codigo] = codigo + ' — ' + descricao; });
        const campoMotivo = criarSelect('linhas[' + indice + '][motivo_isencao]', motivos, 'Motivo de isenção (obrigatório para IVA 0%)', 'campo-motivo-select');
        campoMotivo.classList.add('campo-motivo');
        campoMotivo.hidden = true;
        campoMotivo.querySelector('select').required = false;
        bloco.appendChild(campoMotivo);

        const remover = document.createElement('button');
        remover.type = 'button';
        remover.textContent = 'Remover linha';
        remover.className = 'text-left text-sm text-red-600 sm:col-span-12';
        remover.addEventListener('click', () => bloco.remove());
        bloco.appendChild(remover);

        lista.appendChild(bloco);
        taxa.addEventListener('change', () => sincronizarMotivo(bloco));
        sincronizarMotivo(bloco);
        indice++;
    });
})();
</script>
@endpush
@endsection
