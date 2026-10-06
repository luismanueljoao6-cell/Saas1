@extends('core::layouts.app')

@section('titulo', 'Salas do Estúdio')

@section('conteudo')
<div x-data="{ aFormar: false }" class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900">Salas do Estúdio</h1>
        <button type="button" @click="aFormar = !aFormar" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
            Nova sala
        </button>
    </div>

    <form x-show="aFormar" x-cloak method="POST" action="{{ route('estudiomusica.salas.store') }}"
          class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-4">
        @csrf
        <input type="text" name="nome" placeholder="Nome (ex.: Sala A - Captação)" required
               class="rounded-md border-slate-300 text-sm sm:col-span-2">
        <input type="number" step="0.01" min="0" name="preco_hora" placeholder="Preço/hora (Kz)" required
               class="rounded-md border-slate-300 text-sm">
        <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
            Guardar
        </button>
        <textarea name="descricao" placeholder="Descrição (opcional)" rows="2"
                  class="rounded-md border-slate-300 text-sm sm:col-span-4"></textarea>
    </form>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-2">Nome</th>
                    <th class="px-4 py-2">Preço/hora</th>
                    <th class="px-4 py-2">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($salas as $sala)
                    <tr>
                        <td class="px-4 py-2 font-medium text-slate-900">{{ $sala->nome }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ number_format($sala->preco_hora, 2) }} Kz</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs {{ $sala->ativa ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                {{ $sala->ativa ? 'Ativa' : 'Inativa' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-slate-500">Ainda sem salas registadas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $salas->links() }}
</div>
@endsection
