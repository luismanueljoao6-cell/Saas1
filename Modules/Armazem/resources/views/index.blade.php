@extends('core::layouts.app')

@section('titulo', 'Gestão de armazéns')

@section('conteudo')
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-zinc-50">Armazéns</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">Organiza os locais onde a empresa guarda os seus produtos.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('armazem.transferencias.index') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Transferências</a>
                <span class="rounded-full bg-indigo-50 px-3 py-1 text-sm font-medium text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                    {{ $armazens->total() }} {{ $armazens->total() === 1 ? 'local' : 'locais' }}
                </span>
            </div>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="font-semibold text-slate-900 dark:text-zinc-100">Adicionar armazém</h2>
            <form method="POST" action="{{ route('armazem.store') }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @csrf
                <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Código
                    <input name="codigo" required maxlength="40" value="{{ old('codigo') }}" placeholder="Ex.: LOJA-01" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                </label>
                <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Nome
                    <input name="nome" required maxlength="150" value="{{ old('nome') }}" placeholder="Armazém central" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                </label>
                <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Morada
                    <input name="morada" maxlength="255" value="{{ old('morada') }}" placeholder="Localização" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                </label>
                <label class="text-sm font-medium text-slate-700 dark:text-zinc-300">Descrição
                    <input name="descricao" maxlength="2000" value="{{ old('descricao') }}" placeholder="Notas internas" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950">
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-zinc-300 sm:col-span-2">
                    <input type="checkbox" name="principal" value="1" @checked(old('principal')) class="rounded border-slate-300 text-indigo-600">
                    Definir como armazém principal
                </label>
                <div class="sm:col-span-2 xl:col-span-4">
                    <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Guardar armazém</button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="border-b border-slate-100 px-5 py-4 dark:border-zinc-800">
                <h2 class="font-semibold text-slate-900 dark:text-zinc-100">Locais registados</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm dark:divide-zinc-800">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-zinc-950 dark:text-zinc-400">
                        <tr><th class="px-5 py-3">Código / nome</th><th class="px-5 py-3">Morada</th><th class="px-5 py-3">Estado</th><th class="px-5 py-3">Principal</th><th class="px-5 py-3">Ações</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                        @forelse ($armazens as $armazem)
                            <tr>
                                <td class="px-5 py-4">
                                    <p class="font-semibold text-slate-900 dark:text-zinc-100">{{ $armazem->nome }}</p>
                                    <p class="mt-1 text-xs text-slate-500 dark:text-zinc-400">{{ $armazem->codigo }}</p>
                                    @if ($armazem->descricao)<p class="mt-1 text-xs text-slate-500 dark:text-zinc-400">{{ $armazem->descricao }}</p>@endif
                                </td>
                                <td class="px-5 py-4 text-slate-600 dark:text-zinc-300">{{ $armazem->morada ?: '—' }}</td>
                                <td class="px-5 py-4">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $armazem->ativo ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                        {{ $armazem->ativo ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    @if ($armazem->principal)
                                        <span class="font-semibold text-indigo-700 dark:text-indigo-300">Principal</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <details class="relative">
                                            <summary class="cursor-pointer font-medium text-indigo-600 hover:text-indigo-500">Editar</summary>
                                            <form method="POST" action="{{ route('armazem.update', $armazem) }}" class="mt-2 grid min-w-64 gap-2 rounded-lg border border-slate-200 bg-white p-3 shadow-lg dark:border-zinc-700 dark:bg-zinc-950">
                                                @csrf @method('PUT')
                                                <input name="codigo" required maxlength="40" value="{{ $armazem->codigo }}" aria-label="Código" class="rounded-md border px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900">
                                                <input name="nome" required maxlength="150" value="{{ $armazem->nome }}" aria-label="Nome" class="rounded-md border px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900">
                                                <input name="morada" maxlength="255" value="{{ $armazem->morada }}" aria-label="Morada" placeholder="Morada" class="rounded-md border px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900">
                                                <textarea name="descricao" maxlength="2000" aria-label="Descrição" placeholder="Descrição" class="rounded-md border px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900">{{ $armazem->descricao }}</textarea>
                                                <button class="rounded-md bg-indigo-600 px-3 py-2 text-white">Guardar</button>
                                            </form>
                                        </details>
                                        @if ($armazem->ativo && ! $armazem->principal)
                                            <form method="POST" action="{{ route('armazem.principal', $armazem) }}">@csrf
                                                <button class="font-medium text-indigo-600 hover:text-indigo-500">Tornar principal</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('armazem.estado', $armazem) }}">@csrf @method('PATCH')
                                            <button class="font-medium {{ $armazem->ativo ? 'text-rose-600' : 'text-emerald-600' }}">{{ $armazem->ativo ? 'Inativar' : 'Reativar' }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-10 text-center text-slate-500 dark:text-zinc-400">Ainda não existem armazéns registados.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 px-5 py-3 dark:border-zinc-800">{{ $armazens->links() }}</div>
        </section>
    </div>
@endsection
