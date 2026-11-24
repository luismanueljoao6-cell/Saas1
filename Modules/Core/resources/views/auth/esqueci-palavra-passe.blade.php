@extends('core::layouts.app')

@section('titulo', 'Recuperar palavra-passe')

@section('conteudo')
    <div class="rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Recuperar palavra-passe</h1>
        <p class="mt-1 text-sm text-slate-500">Indica o e-mail da tua conta e enviamos uma ligação para definires uma nova palavra-passe.</p>

        @if (session('status'))
            <div role="status" class="mt-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-slate-700">E-mail</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                       autocomplete="username"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <button type="submit"
                    class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                Enviar ligação
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-slate-500">
            <a href="{{ route('core.login') }}" class="font-medium text-indigo-600 hover:text-indigo-500">Voltar a iniciar sessão</a>
        </p>
    </div>
@endsection
