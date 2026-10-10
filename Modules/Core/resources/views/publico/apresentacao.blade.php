@extends('core::layouts.app')

@section('titulo', 'Gestão para o teu negócio')

@section('conteudo')
    <header class="flex items-center justify-between py-2">
        <span class="text-sm font-semibold text-slate-900">Plataforma de Gestão</span>
        <nav class="flex items-center gap-4 text-sm">
            <a href="{{ route('core.login') }}" class="text-slate-600 hover:text-slate-900">Entrar</a>
            <a href="{{ route('core.registo') }}"
               class="rounded-md bg-indigo-600 px-3 py-1.5 font-semibold text-white hover:bg-indigo-500">Criar conta</a>
        </nav>
    </header>

    <section class="py-16 text-center">
        <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
            Um só sistema para gerir o teu negócio
        </h1>
        <p class="mx-auto mt-4 max-w-2xl text-slate-600">
            Escolhe só os serviços de que precisas — faturação, atelier, estúdio — ou todos.
            No painel vês apenas aquilo a que aderiste.
        </p>
        <div class="mt-8 flex justify-center gap-3">
            <a href="{{ route('core.registo') }}"
               class="rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                Criar conta
            </a>
            <a href="{{ route('core.login') }}"
               class="rounded-md border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Já tenho conta
            </a>
        </div>
    </section>

    <section class="grid gap-4 pb-16 sm:grid-cols-3">
        @foreach (\Modules\Core\Support\Servico::cases() as $servico)
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">{{ $servico->rotulo() }}</h2>
                <p class="mt-2 text-sm text-slate-600">{{ $servico->descricao() }}</p>
                @if ($servico->nota())
                    <p class="mt-3 text-xs text-indigo-600">{{ $servico->nota() }}</p>
                @endif
            </div>
        @endforeach
    </section>
@endsection
