@extends('core::layouts.app')

@section('titulo', 'Produtos e serviços')

@section('conteudo')
    <div class="w-full max-w-4xl space-y-5">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Produtos e serviços</h1>
            <p class="mt-1 text-sm text-slate-500">Organiza o catálogo por categoria e identifica artigos através do código de barras.</p>
        </div>

        @if (session('sucesso'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ session('sucesso') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                @foreach ($errors->all() as $erro)
                    <p>{{ $erro }}</p>
                @endforeach
            </div>
        @endif

        <div class="grid gap-4 lg:grid-cols-2">
            <form method="POST" action="{{ route('faturacao.produtos.store') }}" class="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2">
                @csrf
                <h2 class="font-semibold text-slate-900 sm:col-span-2">Novo produto ou serviço</h2>
                <input name="nome" value="{{ old('nome') }}" placeholder="Nome" required maxlength="255" class="rounded-md border border-slate-300 px-3 py-2 text-sm sm:col-span-2">
                <input name="codigo" value="{{ old('codigo') }}" placeholder="Código interno" maxlength="50" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input name="codigo_barras" value="{{ old('codigo_barras') }}" placeholder="Código de barras (GTIN)" inputmode="numeric" pattern="[0-9]{8}|[0-9]{12}|[0-9]{13}|[0-9]{14}" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <select name="categoria_id" class="rounded-md border border-slate-300 px-3 py-2 text-sm sm:col-span-2">
                    <option value="">Sem categoria</option>
                    @foreach ($categorias as $categoria)
                        <option value="{{ $categoria->id }}" @selected(old('categoria_id') == $categoria->id)>{{ $categoria->nome }}</option>
                    @endforeach
                </select>
                <input name="unidade" value="{{ old('unidade', 'un') }}" placeholder="Unidade (un, hora, kg...)" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input name="preco_unitario" value="{{ old('preco_unitario') }}" type="number" step="0.01" min="0" placeholder="Preço unitário" required class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input name="taxa_iva" value="{{ old('taxa_iva') }}" type="number" step="0.01" min="0" max="100" placeholder="IVA % (vazio = geral)" class="rounded-md border border-slate-300 px-3 py-2 text-sm sm:col-span-2">
                <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2"><input type="checkbox" name="controla_stock" value="1" @checked(old('controla_stock'))> Este produto controla stock</label>
                <input name="stock_minimo" value="{{ old('stock_minimo', '0.000') }}" type="number" step="0.001" min="0" placeholder="Stock mínimo (opcional)" class="rounded-md border border-slate-300 px-3 py-2 text-sm sm:col-span-2">
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white sm:col-span-2">Guardar produto</button>
            </form>

            <form method="POST" action="{{ route('faturacao.produtos.categorias.store') }}" class="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-4">
                @csrf
                <h2 class="font-semibold text-slate-900">Nova categoria</h2>
                <input name="nome" placeholder="Nome da categoria" required maxlength="120" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <textarea name="descricao" placeholder="Descrição (opcional)" maxlength="500" rows="3" class="rounded-md border border-slate-300 px-3 py-2 text-sm"></textarea>
                <button type="submit" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Criar categoria</button>
                <p class="text-xs text-slate-500">As categorias pertencem apenas à empresa atual.</p>
            </form>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Produto/serviço</th>
                        <th class="px-4 py-3">Código</th>
                        <th class="px-4 py-3">Categoria</th>
                        <th class="px-4 py-3">Stock</th>
                        <th class="px-4 py-3 text-right">Preço</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($produtos as $produto)
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-900">{{ $produto->nome }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $produto->codigo_barras ?: $produto->codigo ?: '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $produto->categoria?->nome ?: 'Sem categoria' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $produto->controla_stock ? number_format((float) ($produto->stockSaldo?->quantidade ?? 0), 3, ',', '.') : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ number_format((float) $produto->preco_unitario, 2, ',', '.') }} Kz</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Ainda não tens produtos ou serviços.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $produtos->links() }}
    </div>
@endsection
