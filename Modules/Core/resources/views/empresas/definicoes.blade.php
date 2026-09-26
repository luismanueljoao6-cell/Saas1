@extends('core::layouts.app')

@section('titulo', 'Definições da empresa')

@section('conteudo')
    <div class="w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Definições da empresa</h1>
            <a href="{{ route('core.painel') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">&larr; Painel</a>
        </div>
        <p class="mt-1 text-sm text-slate-500">Estes dados são usados nos documentos fiscais emitidos pela plataforma — confirma-os com atenção.</p>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('core.empresa.atualizar', $empresa) }}" class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            @method('PUT')

            <div class="sm:col-span-2">
                <label for="nome_comercial" class="block text-sm font-medium text-slate-700">Nome comercial</label>
                <input id="nome_comercial" name="nome_comercial" value="{{ old('nome_comercial', $empresa->nome_comercial) }}" required
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div class="sm:col-span-2">
                <label for="nome_legal" class="block text-sm font-medium text-slate-700">Nome legal / razão social</label>
                <input id="nome_legal" name="nome_legal" value="{{ old('nome_legal', $empresa->nome_legal) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="nif" class="block text-sm font-medium text-slate-700">NIF</label>
                <input id="nif" name="nif" value="{{ old('nif', $empresa->nif) }}" required
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="regime_fiscal" class="block text-sm font-medium text-slate-700">Regime fiscal</label>
                <input id="regime_fiscal" name="regime_fiscal" value="{{ old('regime_fiscal', $empresa->regime_fiscal) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div class="sm:col-span-2">
                <label for="morada" class="block text-sm font-medium text-slate-700">Morada</label>
                <input id="morada" name="morada" value="{{ old('morada', $empresa->morada) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="municipio" class="block text-sm font-medium text-slate-700">Município</label>
                <input id="municipio" name="municipio" value="{{ old('municipio', $empresa->municipio) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="provincia" class="block text-sm font-medium text-slate-700">Província</label>
                <input id="provincia" name="provincia" value="{{ old('provincia', $empresa->provincia) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="telefone" class="block text-sm font-medium text-slate-700">Telefone</label>
                <input id="telefone" name="telefone" value="{{ old('telefone', $empresa->telefone) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-slate-700">E-mail</label>
                <input id="email" type="email" name="email" value="{{ old('email', $empresa->email) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div class="sm:col-span-2 pt-2">
                <button type="submit"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Guardar alterações
                </button>
            </div>
        </form>
    </div>
@endsection
