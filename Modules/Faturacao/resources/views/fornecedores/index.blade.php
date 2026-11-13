@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-7xl space-y-6 px-4 py-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h1 class="text-2xl font-bold text-slate-900 dark:text-zinc-100">Fornecedores</h1><p class="text-sm text-slate-500">Contactos comerciais e dados de identificação.</p></div>
        <a href="{{ route('faturacao.compras.index') }}" class="rounded-md border px-4 py-2 text-sm">Ver compras</a>
    </div>
    @if (session('sucesso'))<div class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>@endif
    @if ($errors->any())<div class="rounded-md bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>@endif
    <form method="GET" class="flex flex-wrap gap-2"><input name="q" value="{{ request('q') }}" placeholder="Nome, NIF ou telefone" class="rounded-md border px-3 py-2"><button class="rounded-md bg-slate-900 px-4 py-2 text-white">Pesquisar</button></form>
    <section class="rounded-lg border bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
        <h2 class="mb-3 font-semibold">Novo fornecedor</h2>
        <form method="POST" action="{{ route('faturacao.fornecedores.store') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">@csrf
            <input name="nome" required maxlength="180" placeholder="Nome / empresa" class="rounded-md border px-3 py-2">
            <input name="nif" maxlength="30" placeholder="NIF" class="rounded-md border px-3 py-2">
            <input name="telefone" maxlength="40" placeholder="Telefone" class="rounded-md border px-3 py-2">
            <input name="email" type="email" placeholder="Email" class="rounded-md border px-3 py-2">
            <input name="morada" maxlength="255" placeholder="Morada" class="rounded-md border px-3 py-2">
            <input name="pais" value="AO" required minlength="2" maxlength="2" placeholder="País (ISO)" class="rounded-md border px-3 py-2">
            <textarea name="observacoes" placeholder="Observações" class="rounded-md border px-3 py-2 sm:col-span-2"></textarea>
            <button class="rounded-md bg-slate-900 px-4 py-2 font-semibold text-white">Guardar fornecedor</button>
        </form>
    </section>
    <section class="overflow-x-auto rounded-lg border bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <table class="min-w-full text-sm"><thead class="bg-slate-50 text-left dark:bg-zinc-950"><tr><th class="p-3">Fornecedor</th><th class="p-3">NIF</th><th class="p-3">Telefone</th><th class="p-3">Email</th><th class="p-3">Estado</th></tr></thead><tbody>
        @forelse ($fornecedores as $fornecedor)<tr class="border-t dark:border-zinc-800"><td class="p-3 font-medium">{{ $fornecedor->nome }}</td><td class="p-3">{{ $fornecedor->nif ?: '—' }}</td><td class="p-3">{{ $fornecedor->telefone ?: '—' }}</td><td class="p-3">{{ $fornecedor->email ?: '—' }}</td><td class="p-3">{{ $fornecedor->ativo ? 'Ativo' : 'Inativo' }}</td></tr>@empty<tr><td colspan="5" class="p-5 text-center text-slate-500">Ainda não existem fornecedores.</td></tr>@endforelse
        </tbody></table>
        <div class="p-3">{{ $fornecedores->links() }}</div>
    </section>
</div>
@endsection
