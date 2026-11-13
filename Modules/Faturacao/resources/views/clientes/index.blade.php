@extends('core::layouts.app')

@section('titulo', 'Clientes')

@section('conteudo')
    <div class="w-full max-w-7xl">
        <h1 class="text-xl font-semibold text-slate-900 dark:text-zinc-100">Clientes</h1>

        <form method="GET" action="{{ route('faturacao.clientes.index') }}" role="search" class="mt-4 flex flex-col gap-2 sm:flex-row">
            <label for="pesquisa-registos" class="sr-only">Pesquisar clientes</label>
            <input id="pesquisa-registos" type="search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Nome, NIF, telefone ou e-mail" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 sm:max-w-md">
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-zinc-100 dark:text-zinc-900">Pesquisar</button>
            @if (request()->filled('q'))
                <a href="{{ route('faturacao.clientes.index') }}" class="rounded-md border border-slate-300 px-4 py-2 text-center text-sm font-medium text-slate-600 dark:border-zinc-700 dark:text-zinc-300">Limpar</a>
            @endif
        </form>

        @if (session('sucesso'))
            <div class="mt-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
                {{ session('sucesso') }}
            </div>
        @endif

        <div x-data="{ aFormulario: false }" class="mt-4">
            <button type="button" @click="aFormulario = !aFormulario"
                    class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                <span x-text="aFormulario ? '− Fechar' : '+ Novo cliente'"></span>
            </button>

            <form x-show="aFormulario" x-cloak method="POST" action="{{ route('faturacao.clientes.store') }}"
                  class="mt-3 grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2 dark:bg-zinc-900 dark:border-zinc-800">
                @csrf
                <input name="nome" placeholder="Nome" required class="rounded-md border border-slate-300 px-3 py-2 text-sm sm:col-span-2 dark:border-zinc-700">
                <input name="nif" placeholder="NIF (opcional — consumidor final)" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-zinc-700">
                <input name="telefone" placeholder="Telefone" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-zinc-700">
                <input name="email" type="email" placeholder="E-mail" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-zinc-700">
                <input name="morada" placeholder="Morada" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-zinc-700">
                <button type="submit" class="sm:col-span-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    Guardar cliente
                </button>
            </form>
        </div>

        <div class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white dark:bg-zinc-900 dark:border-zinc-800 dark:divide-zinc-800">
            @forelse ($clientes as $cliente)
                <div class="flex items-center justify-between px-4 py-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-zinc-100">{{ $cliente->nome }}</p>
                        <p class="text-xs text-slate-500 dark:text-zinc-400">{{ $cliente->nif ?? 'Consumidor final' }}</p>
                    </div>
                    <a href="{{ route('faturacao.clientes.conta-corrente', $cliente) }}"
                       class="text-xs font-medium text-indigo-600 hover:text-indigo-500">
                        Conta corrente →
                    </a>
                </div>
            @empty
                <p class="px-4 py-6 text-center text-sm text-slate-500 dark:text-zinc-400">Ainda não tens clientes.</p>
            @endforelse
        </div>

        {{ $clientes->links() }}
    </div>
@endsection
