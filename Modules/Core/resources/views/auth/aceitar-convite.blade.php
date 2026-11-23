@extends('core::layouts.app')

@section('titulo', 'Aceitar convite')

@section('conteudo')
    <div class="mx-auto w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        @if ($convite === null)
            <h1 class="text-xl font-semibold text-slate-900 dark:text-zinc-100">Convite indisponível</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-zinc-400">{{ $mensagem }}</p>
            <a href="{{ route('core.login') }}"
               class="mt-6 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500">Ir para o início de sessão</a>
        @else
            <h1 class="text-xl font-semibold text-slate-900 dark:text-zinc-100">Aceitar convite</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">
                Foste convidado(a) para a empresa <strong>{{ $convite->empresa->nome_comercial }}</strong>.
                Escolhe a tua palavra-passe para criar a conta.
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

            <form method="POST" action="{{ route('core.convite.aceitar', $token) }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <span class="block text-sm font-medium text-slate-700 dark:text-zinc-300">E-mail</span>
                    <p class="mt-1 text-sm text-slate-900 dark:text-zinc-100">{{ $convite->email }}</p>
                </div>

                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700 dark:text-zinc-300">Nome</label>
                    <input id="name" type="text" name="name" value="{{ old('name', $convite->name) }}" required autofocus
                           autocomplete="name"
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700 dark:text-zinc-300">Palavra-passe</label>
                    <input id="password" type="password" name="password" required autocomplete="new-password"
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-slate-700 dark:text-zinc-300">Confirma a palavra-passe</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                </div>

                <button type="submit"
                        class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Criar conta
                </button>
            </form>
        @endif
    </div>
@endsection
