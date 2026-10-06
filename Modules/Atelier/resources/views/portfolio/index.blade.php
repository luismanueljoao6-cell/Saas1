@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Portfólio')

@section('conteudo')
    <h1 class="mb-6 text-lg font-semibold text-slate-900">Portfólio Público</h1>

    <form method="POST" action="{{ route('atelier.portfolio.guardar') }}" enctype="multipart/form-data"
          class="mb-6 grid gap-3 rounded-md border border-slate-200 bg-white p-5 sm:grid-cols-2">
        @csrf
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Categoria *</label>
            <select name="categoria" required class="w-full rounded-md border-slate-300 text-sm">
                @foreach ($categorias as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Título *</label>
            <input type="text" name="titulo" required class="w-full rounded-md border-slate-300 text-sm">
        </div>
        <div class="sm:col-span-2">
            <label class="mb-1 block text-xs font-medium text-slate-600">Foto *</label>
            <input type="file" name="foto" accept="image/*" required class="w-full text-sm">
        </div>
        <div class="sm:col-span-2">
            <label class="mb-1 block text-xs font-medium text-slate-600">Descrição</label>
            <input type="text" name="descricao" class="w-full rounded-md border-slate-300 text-sm">
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="destaque" value="1" class="rounded border-slate-300"> Destacar na página
        </label>
        <div class="flex items-end justify-end">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Adicionar ao portfólio
            </button>
        </div>
    </form>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        @forelse ($itens as $item)
            <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
                <img src="{{ $item->url() }}" class="h-32 w-full object-cover" alt="{{ $item->titulo }}">
                <div class="p-2">
                    <p class="truncate text-xs font-medium text-slate-800">{{ $item->titulo }}</p>
                    <p class="text-xs text-slate-400">{{ $item->categoriaRotulo() }}</p>
                    <form method="POST" action="{{ route('atelier.portfolio.destruir', $item) }}" class="mt-1"
                          onsubmit="return confirm('Remover este item?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs text-red-600">Remover</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="col-span-full text-sm text-slate-500">Ainda não há nenhum item no portfólio.</p>
        @endforelse
    </div>
@endsection
