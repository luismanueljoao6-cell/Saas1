@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Novo Pedido')

@section('conteudo')
    <h1 class="mb-6 text-lg font-semibold text-slate-900">Novo Pedido de Costura</h1>

    <form method="POST" action="{{ route('atelier.pedidos.guardar') }}" enctype="multipart/form-data"
          class="space-y-6 rounded-md border border-slate-200 bg-white p-6" x-data="pedidoForm()">
        @csrf

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Cliente *</label>
                <select name="cliente_id" required class="w-full rounded-md border-slate-300 text-sm">
                    <option value="">Seleciona...</option>
                    @foreach ($clientes as $cliente)
                        <option value="{{ $cliente->id }}" @selected(old('cliente_id') == $cliente->id)>{{ $cliente->nome }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Tipo de serviço *</label>
                <select name="tipo_servico" required class="w-full rounded-md border-slate-300 text-sm">
                    @foreach ($tiposServico as $valor => $rotulo)
                        <option value="{{ $valor }}" @selected(old('tipo_servico') == $valor)>{{ $rotulo }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Descrição da peça *</label>
            <textarea name="descricao" required rows="3" class="w-full rounded-md border-slate-300 text-sm"
                      placeholder="Ex.: Vestido de noiva em cetim, decote coração, cauda média...">{{ old('descricao') }}</textarea>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Tecido / cor</label>
                <input type="text" name="tecido_cor" value="{{ old('tecido_cor') }}" class="w-full rounded-md border-slate-300 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Aviamentos necessários</label>
                <input type="text" name="aviamentos_necessarios" value="{{ old('aviamentos_necessarios') }}" class="w-full rounded-md border-slate-300 text-sm">
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Responsável</label>
                <select name="responsavel_id" class="w-full rounded-md border-slate-300 text-sm">
                    <option value="">Não atribuído</option>
                    @foreach ($costureiras as $utilizador)
                        <option value="{{ $utilizador->id }}" @selected(old('responsavel_id') == $utilizador->id)>{{ $utilizador->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Prazo interno</label>
                <input type="date" name="prazo_interno" value="{{ old('prazo_interno') }}" class="w-full rounded-md border-slate-300 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Entrega prevista ao cliente</label>
                <input type="date" name="data_prevista_entrega" value="{{ old('data_prevista_entrega') }}" class="w-full rounded-md border-slate-300 text-sm">
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Valor acordado (sem IVA), AOA *</label>
                <input type="number" step="0.01" name="valor_orcamento" required value="{{ old('valor_orcamento') }}" class="w-full rounded-md border-slate-300 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Sinal a pedir (%)</label>
                <input type="number" step="0.01" max="100" name="percentual_sinal" value="{{ old('percentual_sinal') }}" class="w-full rounded-md border-slate-300 text-sm">
            </div>
        </div>

        {{-- Materiais extra, adicionados/removidos dinamicamente sem recarregar a página --}}
        <div>
            <div class="mb-2 flex items-center justify-between">
                <label class="block text-xs font-medium text-slate-600">Materiais/insumos cobrados adicionalmente</label>
                <button type="button" @click="materiais.push({descricao: '', quantidade: 1, valor_unitario: ''})"
                        class="text-xs font-medium text-indigo-600">+ Adicionar material</button>
            </div>
            <template x-for="(material, indice) in materiais" :key="indice">
                <div class="mb-2 grid grid-cols-12 gap-2">
                    <input type="text" :name="`materiais[${indice}][descricao]`" x-model="material.descricao"
                           placeholder="Descrição" class="col-span-6 rounded-md border-slate-300 text-sm">
                    <input type="number" step="0.01" :name="`materiais[${indice}][quantidade]`" x-model="material.quantidade"
                           placeholder="Qtd" class="col-span-2 rounded-md border-slate-300 text-sm">
                    <input type="number" step="0.01" :name="`materiais[${indice}][valor_unitario]`" x-model="material.valor_unitario"
                           placeholder="Valor unit." class="col-span-3 rounded-md border-slate-300 text-sm">
                    <button type="button" @click="materiais.splice(indice, 1)" class="col-span-1 text-red-500">✕</button>
                </div>
            </template>
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Fotos de referência</label>
            <input type="file" name="fotos[]" multiple accept="image/*" class="w-full text-sm">
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('atelier.pedidos.index') }}" class="text-sm text-slate-500">Cancelar</a>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Criar pedido
            </button>
        </div>
    </form>

    <script>
        function pedidoForm() {
            return { materiais: [] };
        }
    </script>
@endsection
