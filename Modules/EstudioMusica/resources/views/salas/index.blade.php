@extends('core::layouts.app')

@section('titulo', 'Salas do Estúdio')

@section('conteudo')
@include('estudiomusica::partials.erros')
<div x-data="{ aFormar: {{ $errors->any() ? 'true' : 'false' }} }" class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900 dark:text-zinc-100">Salas do Estúdio</h1>
        <button type="button" @click="aFormar = !aFormar" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
            Nova sala
        </button>
    </div>

    <form x-show="aFormar" x-cloak method="POST" action="{{ route('estudiomusica.salas.store') }}"
          class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-4 dark:bg-zinc-900 dark:border-zinc-800">
        @csrf
        <input type="text" name="nome" value="{{ old('nome') }}" placeholder="Nome (ex.: Sala A - Captação)" required
               class="rounded-md border-slate-300 text-sm sm:col-span-2 dark:border-zinc-700">
        <input type="number" step="0.01" min="0" max="99999999.99" name="preco_hora" value="{{ old('preco_hora') }}" placeholder="Preço/hora (Kz)" required
               class="rounded-md border-slate-300 text-sm dark:border-zinc-700">
        <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
            Guardar
        </button>
        <textarea name="descricao" placeholder="Descrição (opcional)" rows="2"
                  class="rounded-md border-slate-300 text-sm sm:col-span-4 dark:border-zinc-700">{{ old('descricao') }}</textarea>
    </form>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:bg-zinc-900 dark:border-zinc-800">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-zinc-800">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500 dark:bg-zinc-950 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-2">Nome</th>
                    <th class="px-4 py-2">Preço/hora</th>
                    <th class="px-4 py-2">Estado</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                @forelse ($salas as $sala)
                    <tr class="align-top">
                        <td class="px-4 py-2 font-medium text-slate-900 dark:text-zinc-100">
                            {{ $sala->nome }}
                            @if ($sala->descricao)
                                <span class="block text-xs font-normal text-slate-400">{{ $sala->descricao }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-slate-600 dark:text-zinc-300">{{ number_format($sala->preco_hora, 2) }} Kz</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs {{ $sala->ativa ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                {{ $sala->ativa ? 'Ativa' : 'Inativa' }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            @can('estudiomusica.gerir-salas')
                                <details class="text-left">
                                    <summary class="cursor-pointer text-right text-xs text-indigo-600 underline">Editar</summary>
                                    <form method="POST" action="{{ route('estudiomusica.salas.update', $sala) }}" class="mt-2 grid gap-2 sm:grid-cols-2">
                                        @csrf
                                        @method('PUT')
                                        <input type="text" name="nome" value="{{ $sala->nome }}" required class="rounded-md border-slate-300 text-xs dark:border-zinc-700">
                                        <input type="number" step="0.01" min="0" max="99999999.99" name="preco_hora" value="{{ $sala->preco_hora }}" required class="rounded-md border-slate-300 text-xs dark:border-zinc-700">
                                        <textarea name="descricao" rows="2" placeholder="Descrição" class="rounded-md border-slate-300 text-xs sm:col-span-2 dark:border-zinc-700">{{ $sala->descricao }}</textarea>
                                        <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-zinc-300">
                                            <input type="hidden" name="ativa" value="0">
                                            <input type="checkbox" name="ativa" value="1" @checked($sala->ativa) class="rounded border-slate-300 dark:border-zinc-700">
                                            Sala ativa (aceita novas sessões)
                                        </label>
                                        <button type="submit" class="rounded-md bg-slate-900 px-2.5 py-1 text-xs text-white hover:bg-slate-700">Guardar</button>
                                    </form>
                                </details>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500 dark:text-zinc-400">Ainda sem salas registadas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $salas->links() }}
</div>
@endsection
