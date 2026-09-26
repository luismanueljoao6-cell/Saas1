@extends('core::layouts.app')

@section('titulo', 'Iniciar sessão')

@section('conteudo')
    <div class="rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Iniciar sessão</h1>
        <p class="mt-1 text-sm text-slate-500">Acede à plataforma de gestão da tua empresa.</p>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('core.login') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-slate-700">E-mail</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                       autocomplete="username"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div x-data="{ mostrar: false }">
                <label for="password" class="block text-sm font-medium text-slate-700">Palavra-passe</label>
                <div class="relative mt-1">
                    <input :type="mostrar ? 'text' : 'password'" id="password" name="password" required
                           autocomplete="current-password"
                           class="block w-full rounded-md border border-slate-300 px-3 py-2 pr-16 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                    <button type="button" @click="mostrar = !mostrar"
                            class="absolute inset-y-0 right-0 px-3 text-xs font-medium text-indigo-600 hover:text-indigo-500">
                        <span x-text="mostrar ? 'Ocultar' : 'Mostrar'"></span>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2 text-slate-600">
                    <input type="checkbox" name="lembrar" class="rounded border-slate-300">
                    Lembrar-me
                </label>
            </div>

            <button type="submit"
                    class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                Entrar
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-slate-500">
            Ainda não tens conta?
            <a href="{{ route('core.registo') }}" class="font-medium text-indigo-600 hover:text-indigo-500">Regista a tua empresa</a>
        </p>
    </div>
@endsection
