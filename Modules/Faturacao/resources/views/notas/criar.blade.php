@extends('core::layouts.app')

@section('titulo', $tipo === 'credito' ? 'Nova nota de crédito' : 'Nova nota de débito')

@section('conteudo')
    <div class="w-full max-w-3xl" x-data="novaNota(@js($linhasIniciais), @js($tipo === 'credito'), @js($taxaNaInterface))">
        <h1 class="text-xl font-semibold text-slate-900">{{ $tipo === 'credito' ? 'Nova nota de crédito' : 'Nova nota de débito' }}</h1>
        <p class="mt-1 text-sm text-slate-500">
            @if ($tipo === 'credito')
                Parte das linhas da fatura {{ $fatura->numero_documento }}: reduz a quantidade (ou remove linhas) para creditar só uma parte.
            @else
                Uma nota de débito não liquida IVA. Fica como rascunho — só recebe número e assinatura fiscal quando a emitires.
            @endif
        </p>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('faturacao.notas.guardar') }}" class="mt-6 space-y-6">
            @csrf
            <input type="hidden" name="tipo" value="{{ $tipo }}">

            @if ($fatura)
                <input type="hidden" name="fatura_id" value="{{ $fatura->id }}">
                <p class="rounded-md bg-slate-50 border border-slate-200 px-4 py-3 text-sm text-slate-600">
                    Fatura de origem: <strong>{{ $fatura->numero_documento }}</strong> — {{ $fatura->cliente_nome ?? $fatura->cliente->nome }}
                </p>
            @else
                <div>
                    <label for="cliente_id" class="block text-sm font-medium text-slate-700">Cliente</label>
                    <select name="cliente_id" id="cliente_id" required class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        <option value="">Selecionar...</option>
                        @foreach ($clientes as $cliente)
                            <option value="{{ $cliente->id }}" @selected((int) old('cliente_id') === (int) $cliente->id)>{{ $cliente->nome }} @if($cliente->nif) ({{ $cliente->nif }}) @endif</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div>
                <label for="motivo" class="block text-sm font-medium text-slate-700">Motivo</label>
                <input type="text" name="motivo" id="motivo" required minlength="3" maxlength="60" value="{{ old('motivo') }}"
                       placeholder="{{ $tipo === 'credito' ? 'Ex.: devolução de mercadoria' : 'Ex.: repasse de despesas' }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <label class="block text-sm font-medium text-slate-700">Linhas</label>
                    <button type="button" x-show="!credito" @click="adicionarLinha()" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">+ Linha</button>
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
                            <template x-if="comTaxa">
                                {{-- Na nota de crédito a taxa é a da fatura: só leitura (segue no pedido). --}}
                                <input type="number" step="0.01" min="0" max="100" :name="`linhas[${indice}][taxa_iva]`" x-model.number="linha.taxa_iva" placeholder="IVA %"
                                       :readonly="credito" :class="credito ? 'bg-slate-100 text-slate-500' : ''"
                                       class="col-span-3 sm:col-span-2 rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                            </template>
                            <input type="hidden" :name="`linhas[${indice}][produto_id]`" :value="linha.produto_id ?? ''">
                            <input type="hidden" :name="`linhas[${indice}][motivo_isencao]`" :value="linha.motivo_isencao ?? ''">
                            <button type="button" @click="removerLinha(indice)" class="col-span-1 text-red-500 hover:text-red-700" title="Remover">✕</button>
                        </div>
                    </template>
                </div>
            </div>

            <div class="rounded-md bg-slate-50 border border-slate-200 p-4 text-sm">
                <div class="flex justify-between"><span>Subtotal</span><span x-text="formatar(totalSemIva())"></span></div>
                <div class="flex justify-between" x-show="comTaxa"><span>IVA</span><span x-text="formatar(totalIva())"></span></div>
                <div class="mt-1 flex justify-between border-t border-slate-200 pt-1 font-semibold"><span>Total</span><span x-text="formatar(totalGeral())"></span></div>
                <p class="mt-2 text-xs text-slate-500">Valores só para conferência: quem calcula e grava é sempre o servidor.</p>
            </div>

            <button type="submit" :disabled="linhas.length === 0"
                    class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50">
                Guardar rascunho
            </button>
        </form>
    </div>

    <script>
        function novaNota(linhasIniciais = [], credito = false, comTaxa = false) {
            const vazia = () => ({ descricao: '', quantidade: 1, preco_unitario: 0, taxa_iva: 0, motivo_isencao: '' });

            return {
                credito,
                comTaxa,
                linhas: linhasIniciais.length > 0 ? linhasIniciais : [vazia()],
                adicionarLinha() {
                    this.linhas.push(vazia());
                },
                removerLinha(indice) {
                    this.linhas.splice(indice, 1);
                },
                totalSemIva() {
                    return this.linhas.reduce((soma, l) => soma + (Number(l.quantidade) || 0) * (Number(l.preco_unitario) || 0), 0);
                },
                totalIva() {
                    if (!this.comTaxa) {
                        return 0;
                    }
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
            };
        }
    </script>
@endsection
