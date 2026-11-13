@extends('core::layouts.app')

@section('titulo', 'Projetos Musicais')

@section('conteudo')
@include('estudiomusica::partials.erros')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900 dark:text-zinc-100">Projetos Musicais</h1>
        <a href="{{ route('estudiomusica.projetos.criar') }}" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
            Novo projeto
        </a>
    </div>

    <form method="GET" action="{{ route('estudiomusica.projetos.index') }}" role="search" class="mb-4 flex flex-col gap-2 sm:flex-row">
        <label for="pesquisa-modulo" class="sr-only">Pesquisar projetos</label>
        <input id="pesquisa-modulo" type="search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Nome do projeto ou artista" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 sm:max-w-md">
        <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white dark:bg-zinc-100 dark:text-zinc-900">Pesquisar</button>
        @if (request()->filled('q'))
            <a href="{{ route('estudiomusica.projetos.index') }}" class="rounded-md border border-slate-300 px-4 py-2 text-center text-sm dark:border-zinc-700 dark:text-zinc-300">Limpar</a>
        @endif
    </form>
    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:bg-zinc-900 dark:border-zinc-800">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-zinc-800">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500 dark:bg-zinc-950 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-2">Projeto</th>
                    <th class="px-4 py-2">Artista</th>
                    <th class="px-4 py-2">Estado</th>
                    <th class="px-4 py-2">Cobrança</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                @forelse ($projetos as $projeto)
                    <tr class="cursor-pointer hover:bg-slate-50" onclick="window.location='{{ route('estudiomusica.projetos.show', $projeto) }}'">
                        <td class="px-4 py-2 font-medium text-slate-900 dark:text-zinc-100">{{ $projeto->nome }}</td>
                        <td class="px-4 py-2 text-slate-600 dark:text-zinc-300">{{ $projeto->cliente->nome_artistico ?: $projeto->cliente->nome }}</td>
                        <td class="px-4 py-2 text-slate-600 dark:text-zinc-300">{{ $projeto->rotuloEstado() }}</td>
                        <td class="px-4 py-2 text-slate-600 dark:text-zinc-300">{{ $projeto->tipo_cobranca === 'pacote' ? 'Pacote' : 'Por hora' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500 dark:text-zinc-400">Ainda sem projetos registados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $projetos->links() }}
</div>
@endsection
