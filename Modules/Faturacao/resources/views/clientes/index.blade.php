@extends('core::layouts.app')

@section('titulo', 'Clientes')

@section('conteudo')
    <div class="w-full max-w-2xl">
        <h1 class="text-xl font-semibold text-slate-900">Clientes</h1>

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
                  class="mt-3 grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2">
                @csrf
                <input name="nome" placeholder="Nome" required class="rounded-md border border-slate-300 px-3 py-2 text-sm sm:col-span-2">
                <input name="nif" placeholder="NIF (opcional — consumidor final)" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input name="telefone" placeholder="Telefone" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input name="email" type="email" placeholder="E-mail" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input name="morada" placeholder="Morada" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <button type="submit" class="sm:col-span-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    Guardar cliente
                </button>
            </form>
        </div>

        <div class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
            @forelse ($clientes as $cliente)
                <div class="flex items-center justify-between px-4 py-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900">{{ $cliente->nome }}</p>
                        <p class="text-xs text-slate-500">{{ $cliente->nif ?? 'Consumidor final' }}</p>
                    </div>
                </div>
            @empty
                <p class="px-4 py-6 text-center text-sm text-slate-500">Ainda não tens clientes.</p>
            @endforelse
        </div>

        {{ $clientes->links() }}
    </div>
@endsection
