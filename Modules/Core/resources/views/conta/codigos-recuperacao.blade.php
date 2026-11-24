@extends('core::layouts.app')

@section('titulo', 'Códigos de recuperação')

@section('conteudo')
    <div class="mx-auto w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <h1 class="text-xl font-semibold text-slate-900 dark:text-zinc-100">Códigos de recuperação</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">{{ $mensagem }}</p>

        <p class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
            Guarda estes códigos num sítio seguro (por exemplo, num gestor de palavras-passe).
            Cada um serve uma só vez e esta é a única vez que os vês.
        </p>

        <ul class="mt-4 grid grid-cols-2 gap-2 font-mono text-sm tracking-wider">
            @foreach ($codigos as $codigo)
                <li class="select-all rounded-md bg-slate-100 px-3 py-2 text-center text-slate-900 dark:bg-zinc-800 dark:text-zinc-100">{{ $codigo }}</li>
            @endforeach
        </ul>

        <a href="{{ route('core.seguranca.index') }}"
           class="mt-6 inline-block rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
            Já guardei os códigos
        </a>
    </div>
@endsection
