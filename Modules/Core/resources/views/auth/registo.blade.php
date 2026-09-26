@extends('core::layouts.app')

@section('titulo', 'Registar empresa')

@section('conteudo')
    <div class="rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Regista a tua empresa</h1>
        <p class="mt-1 text-sm text-slate-500">Cria a conta da tua empresa e do primeiro administrador. Começas em período experimental.</p>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('core.registo') }}" class="mt-6 space-y-5">
            @csrf

            <fieldset class="space-y-4">
                <legend class="text-sm font-semibold text-slate-700">Dados da empresa</legend>

                <div>
                    <label for="nome_comercial" class="block text-sm font-medium text-slate-700">Nome comercial</label>
                    <input id="nome_comercial" name="nome_comercial" value="{{ old('nome_comercial') }}" required
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="nif" class="block text-sm font-medium text-slate-700">NIF</label>
                    <input id="nif" name="nif" value="{{ old('nif') }}" required
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="telefone_empresa" class="block text-sm font-medium text-slate-700">Telefone (opcional)</label>
                    <input id="telefone_empresa" name="telefone_empresa" value="{{ old('telefone_empresa') }}"
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                </div>
            </fieldset>

            <fieldset class="space-y-4 border-t border-slate-100 pt-4">
                <legend class="text-sm font-semibold text-slate-700">Conta do administrador</legend>

                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700">O teu nome</label>
                    <input id="name" name="name" value="{{ old('name') }}" required
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">E-mail</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">Palavra-passe</label>
                    <input id="password" type="password" name="password" required autocomplete="new-password"
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-slate-700">Confirmar palavra-passe</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                </div>
            </fieldset>

            <label class="flex items-start gap-2 text-sm text-slate-600">
                <input type="checkbox" name="aceita_termos" value="1" required class="mt-0.5 rounded border-slate-300">
                Aceito os termos de utilização e a política de privacidade.
            </label>

            <button type="submit"
                    class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                Criar empresa e conta
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-slate-500">
            Já tens conta?
            <a href="{{ route('core.login') }}" class="font-medium text-indigo-600 hover:text-indigo-500">Inicia sessão</a>
        </p>
    </div>
@endsection
