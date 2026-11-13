@extends('core::layouts.app')
@section('titulo', 'Editar orçamento '.$orcamento->numero)
@section('conteudo')
@php
    $linhasIniciais = old('linhas', $orcamento->linhas ?? []);
@endphp
<div class="mx-auto max-w-4xl space-y-6">
    <div><h1 class="text-2xl font-semibold">Editar orçamento</h1><p class="mt-1 text-sm text-slate-500">Só é possível editar documentos que continuam em rascunho. Esta proforma não tem valor fiscal.</p></div>
    @if($errors->any())<div class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('faturacao.orcamentos.atualizar', $orcamento->id) }}" class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        @csrf
        @method('PUT')
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm">Cliente<select name="cliente_id" required class="mt-1 w-full rounded-lg border-slate-300 dark:border-zinc-700 dark:bg-zinc-800"><option value="">Selecionar cliente</option>@foreach($clientes as $cliente)<option value="{{ $cliente->id }}" @selected(old('cliente_id', $orcamento->cliente_id)==$cliente->id)>{{ $cliente->nome }}</option>@endforeach</select></label>
            <label class="text-sm">Data de validade<input type="date" name="data_validade" min="{{ today()->toDateString() }}" value="{{ old('data_validade', $orcamento->data_validade?->toDateString()) }}" class="mt-1 w-full rounded-lg border-slate-300 dark:border-zinc-700 dark:bg-zinc-800"></label>
        </div>
        <div><div class="mb-2 flex items-center justify-between"><h2 class="font-semibold">Linhas</h2><button type="button" id="adicionar-linha" class="text-sm text-indigo-600">Adicionar linha</button></div>
            <div id="linhas" class="space-y-3">
                @foreach($linhasIniciais as $indice => $linha)
                <div class="grid grid-cols-1 gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-12 dark:border-zinc-700">
                    <input name="linhas[{{ $indice }}][descricao]" required value="{{ $linha['descricao'] ?? '' }}" placeholder="Descrição" class="sm:col-span-5 rounded-lg border-slate-300 dark:bg-zinc-800">
                    <input name="linhas[{{ $indice }}][quantidade]" required type="number" min="0.001" step="0.001" value="{{ $linha['quantidade'] ?? 1 }}" placeholder="Qtd." class="sm:col-span-2 rounded-lg border-slate-300 dark:bg-zinc-800">
                    <input name="linhas[{{ $indice }}][preco_unitario]" required type="number" min="0" step="0.01" value="{{ $linha['preco_unitario'] ?? '' }}" placeholder="Preço unitário" class="sm:col-span-3 rounded-lg border-slate-300 dark:bg-zinc-800">
                    <select name="linhas[{{ $indice }}][taxa_iva]" class="sm:col-span-2 rounded-lg border-slate-300 dark:bg-zinc-800">@foreach($taxasIva as $taxa)<option value="{{ $taxa }}" @selected((float)($linha['taxa_iva'] ?? $taxaPadrao)===(float)$taxa)>{{ $taxa }}% IVA</option>@endforeach</select>
                    @if(!empty($linha['produto_id']))<input type="hidden" name="linhas[{{ $indice }}][produto_id]" value="{{ $linha['produto_id'] }}">@endif
                    <input type="hidden" name="linhas[{{ $indice }}][operation_type]" value="{{ $linha['operation_type'] ?? 'SG' }}">
                    @if(!empty($linha['motivo_isencao']))<input type="hidden" name="linhas[{{ $indice }}][motivo_isencao]" value="{{ $linha['motivo_isencao'] }}">@endif
                    <button type="button" class="text-left text-sm text-red-600 sm:col-span-12" onclick="this.parentElement.remove()">Remover linha</button>
                </div>
                @endforeach
            </div>
        </div>
        <label class="block text-sm">Observações<textarea name="observacoes" rows="3" maxlength="1000" class="mt-1 w-full rounded-lg border-slate-300 dark:bg-zinc-800">{{ old('observacoes', $orcamento->observacoes) }}</textarea></label>
        <div class="flex justify-end gap-3"><a href="{{ route('faturacao.orcamentos.mostrar', $orcamento->id) }}" class="rounded-lg border px-4 py-2 text-sm">Cancelar</a><button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Guardar alterações</button></div>
    </form>
</div>
@push('scripts')
<script>
(() => {
    const lista = document.getElementById('linhas');
    let indice = {{ count($linhasIniciais) }};
    document.getElementById('adicionar-linha').addEventListener('click', () => {
        if (indice >= 100) return;
        const bloco = document.createElement('div');
        bloco.className = 'grid grid-cols-1 gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-12 dark:border-zinc-700';
        const campos = [
            ['descricao','text','Descrição','sm:col-span-5'],
            ['quantidade','number','Qtd.','sm:col-span-2'],
            ['preco_unitario','number','Preço unitário','sm:col-span-3']
        ];
        campos.forEach(([nome,tipo,placeholder,classe]) => {
            const input = document.createElement('input');
            input.name='linhas['+indice+']['+nome+']'; input.type=tipo; input.placeholder=placeholder;
            input.className=classe+' rounded-lg border-slate-300 dark:bg-zinc-800'; input.required=true;
            if(nome==='quantidade'){input.min='0.001';input.step='0.001';input.value='1';}
            if(nome==='preco_unitario'){input.min='0';input.step='0.01';}
            bloco.appendChild(input);
        });
        const taxa=document.createElement('select'); taxa.name='linhas['+indice+'][taxa_iva]';
        taxa.className='sm:col-span-2 rounded-lg border-slate-300 dark:bg-zinc-800';
        @foreach($taxasIva as $taxa)
        {const option=document.createElement('option');option.value='{{ $taxa }}';option.textContent='{{ $taxa }}% IVA';if(Number(option.value)===Number('{{ $taxaPadrao }}'))option.selected=true;taxa.appendChild(option);}
        @endforeach
        bloco.appendChild(taxa);
        const tipo=document.createElement('input');tipo.type='hidden';tipo.name='linhas['+indice+'][operation_type]';tipo.value='SG';bloco.appendChild(tipo);
        const remover=document.createElement('button');remover.type='button';remover.textContent='Remover linha';remover.className='text-left text-sm text-red-600 sm:col-span-12';remover.addEventListener('click',()=>bloco.remove());bloco.appendChild(remover);
        lista.appendChild(bloco);indice++;
    });
})();
</script>
@endpush
@endsection
