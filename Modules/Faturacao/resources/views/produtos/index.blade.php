@extends('core::layouts.app')

@section('titulo', 'Produtos e serviços')

@section('conteudo')
    <div class="w-full max-w-2xl">
        <h1 class="text-xl font-semibold text-slate-900">Produtos e serviços</h1>

        @if (session('sucesso'))
            <div class="mt-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
                {{ session('sucesso') }}
            </div>
        @endif

        <div x-data="{ aFormulario: false }" class="mt-4">
            <button type="button" @click="aFormulario = !aFormulario"
                    class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                <span x-text="aFormulario ? '− Fechar' : '+ Novo produto/serviço'"></span>
            </button>

            <form x-show="aFormulario" x-cloak method="POST" action="{{ route('faturacao.produtos.store') }}"
                  class="mt-3 grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2">
                @csrf
                <input name="nome" placeholder="Nome" required class="rounded-md border border-slate-300 px-3 py-2 text-sm sm:col-span-2">
                <input name="codigo" placeholder="Código (opcional)" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input name="unidade" placeholder="Unidade (un, hora, kg...)" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input name="preco_unitario" type="number" step="0.01" min="0" placeholder="Preço unitário" required class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input name="taxa_iva" type="number" step="0.01" min="0" max="100" placeholder="Taxa IVA % (vazio = geral)" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <button type="submit" class="sm:col-span-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    Guardar produto
                </button>
            </form>
        </div>

        <div class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
            @forelse ($produtos as $produto)
                <div class="flex items-center justify-between px-4 py-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900">{{ $produto->nome }}</p>
                        <p class="text-xs text-slate-500">{{ $produto->codigo }} — {{ $produto->unidade }}</p>
                    </div>
                    <p class="text-sm font-medium text-slate-900">{{ number_format((float) $produto->preco_unitario, 2, ',', ' ') }} AOA</p>
                </div>
            @empty
                <p class="px-4 py-6 text-center text-sm text-slate-500">Ainda não tens produtos ou serviços.</p>
            @endforelse
        </div>

        {{ $produtos->links() }}
    </div>
@endsection
