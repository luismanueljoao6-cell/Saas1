@extends('core::layouts.app')

@section('titulo', 'Verificação em dois passos')

@section('conteudo')
    <div class="mx-auto w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <h1 class="text-xl font-semibold text-slate-900 dark:text-zinc-100">Verificação em dois passos</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">
            Escreve o código de 6 dígitos da tua app autenticadora. Sem acesso à app? Usa um dos teus códigos de recuperação.
        </p>

        @if ($errors->any())
            <div class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('core.2fa.desafio.store') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="codigo" class="block text-sm font-medium text-slate-700 dark:text-zinc-300">Código</label>
                <input id="codigo" type="text" name="codigo" required autofocus autocomplete="one-time-code"
                       inputmode="text" maxlength="32" placeholder="123456"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm tracking-widest shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
            </div>

            <button type="submit"
                    class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                Verificar
            </button>
        </form>

        <a href="{{ route('core.login') }}"
           class="mt-6 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500">Voltar ao início de sessão</a>
    </div>
@endsection
