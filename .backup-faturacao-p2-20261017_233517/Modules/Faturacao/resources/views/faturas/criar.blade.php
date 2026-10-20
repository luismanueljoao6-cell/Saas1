@extends('core::layouts.app')

@section('titulo', 'Nova fatura')

@section('conteudo')
    <div class="w-full max-w-3xl"
         x-data="novaFatura({{ $taxaIvaGeral }})">
        <h1 class="text-xl font-semibold text-slate-900">Nova fatura</h1>
        <p class="mt-1 text-sm text-slate-500">Fica como rascunho — só recebe número e assinatura fiscal quando a emitires.</p>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('faturacao.faturas.guardar') }}" @submit="antesDeSubmeter" class="mt-6 space-y-6">
            @csrf

            <div>
                <label for="cliente_id" class="block text-sm font-medium text-slate-700">Cliente</label>
                <select name="cliente_id" id="cliente_id" required
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Selecionar...</option>
                    @foreach ($clientes as $cliente)
                        <option value="{{ $cliente->id }}">{{ $cliente->nome }} @if($cliente->nif) ({{ $cliente->nif }}) @endif</option>
                    @endforeach
                </select>
                @if ($clientes->isEmpty())
                    <p class="mt-1 text-xs text-amber-600">Ainda não tens clientes — <a href="{{ route('faturacao.clientes.index') }}" class="underline">cria um primeiro</a>.</p>
                @endif
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <label class="block text-sm font-medium text-slate-700">Linhas</label>
                    <button type="button" @click="adicionarLinha()" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">+ Linha</button>
                </div>

                <div class="mt-2 space-y-2">
                    <template x-for="(linha, indice) in linhas" :key="indice">
                        <div class="grid grid-cols-12 gap-2 rounded-md border border-slate-200 p-2">
                            <input type="text" :name="`linhas[${indice}][descricao]`" x-model="linha.descricao" placeholder="Descrição" required
                                   class="col-span-12 sm:col-span-5 rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                            <input type="number" step="0.001" min="0.001" :name="`linhas[${indice}][quantidade]`" x-model.number="linha.quantidade" placeholder="Qtd"
                                   class="col-span-4 sm:col-span-2 rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                            <input type="number" step="0.01" min="0" :name="`linhas[${indice}][preco_unitario]`" x-model.number="linha.preco_unitario" placeholder="Preço"
                                   class="col-span-4 sm:col-span-2 rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                            <input type="number" step="0.01" min="0" max="100" :name="`linhas[${indice}][taxa_iva]`" x-model.number="linha.taxa_iva" placeholder="IVA %"
                                   class="col-span-3 sm:col-span-2 rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                            <button type="button" @click="removerLinha(indice)" class="col-span-1 text-red-500 hover:text-red-700" title="Remover">✕</button>
                        </div>
                    </template>
                </div>
            </div>

            <div class="rounded-md bg-slate-50 border border-slate-200 p-4 text-sm">
                <div class="flex justify-between"><span>Subtotal</span><span x-text="formatar(totalSemIva())"></span></div>
                <div class="flex justify-between"><span>IVA</span><span x-text="formatar(totalIva())"></span></div>
                <div class="mt-1 flex justify-between border-t border-slate-200 pt-1 font-semibold"><span>Total</span><span x-text="formatar(totalGeral())"></span></div>
            </div>

            <div>
                <label for="observacoes" class="block text-sm font-medium text-slate-700">Observações (opcional)</label>
                <textarea name="observacoes" id="observacoes" rows="2" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>

            <button type="submit" :disabled="linhas.length === 0"
                    class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50">
                Guardar rascunho
            </button>
        </form>
    </div>

    <script>
        function novaFatura(taxaIvaGeral) {
            return {
                linhas: [{ descricao: '', quantidade: 1, preco_unitario: 0, taxa_iva: taxaIvaGeral }],
                adicionarLinha() {
                    this.linhas.push({ descricao: '', quantidade: 1, preco_unitario: 0, taxa_iva: taxaIvaGeral });
                },
                removerLinha(indice) {
                    this.linhas.splice(indice, 1);
                },
                totalSemIva() {
                    return this.linhas.reduce((soma, l) => soma + (Number(l.quantidade) || 0) * (Number(l.preco_unitario) || 0), 0);
                },
                totalIva() {
                    return this.linhas.reduce((soma, l) => {
                        const base = (Number(l.quantidade) || 0) * (Number(l.preco_unitario) || 0);
                        return soma + base * ((Number(l.taxa_iva) || 0) / 100);
                    }, 0);
                },
                totalGeral() {
                    return this.totalSemIva() + this.totalIva();
                },
                formatar(valor) {
                    return valor.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' AOA';
                },
                antesDeSubmeter() {
                    // Os totais mostrados aqui são só para conferência visual —
                    // quem calcula e grava os valores reais é sempre o
                    // FaturaLinha::calcularValores() no servidor, nunca o JS.
                },
            };
        }
    </script>
@endsection
