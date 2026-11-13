@extends('core::layouts.app')

@section('titulo', 'Pedidos de Orçamento')

@section('conteudo')
@include('estudiomusica::partials.erros')
<div class="space-y-6">
    <h1 class="mb-3 text-lg font-semibold text-slate-900 dark:text-zinc-100">Pedidos de Orçamento (landing page)</h1>

    <form method="GET" action="{{ route('estudiomusica.solicitacoes.index') }}" role="search" class="mb-4 flex flex-col gap-2 sm:flex-row">
        <label for="pesquisa-solicitacoes" class="sr-only">Pesquisar pedidos recebidos</label>
        <input id="pesquisa-solicitacoes" type="search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Nome, e-mail, telefone ou mensagem" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 sm:max-w-md">
        <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white dark:bg-zinc-100 dark:text-zinc-900">Pesquisar</button>
        @if (request()->filled('q'))
            <a href="{{ route('estudiomusica.solicitacoes.index') }}" class="rounded-md border border-slate-300 px-4 py-2 text-center text-sm dark:border-zinc-700 dark:text-zinc-300">Limpar</a>
        @endif
    </form>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:bg-zinc-900 dark:border-zinc-800">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-zinc-800">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500 dark:bg-zinc-950 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-2">Nome</th>
                    <th class="px-4 py-2">Contacto</th>
                    <th class="px-4 py-2">Serviço desejado</th>
                    <th class="px-4 py-2">Mensagem</th>
                    <th class="px-4 py-2">Marketing</th>
                    <th class="px-4 py-2">Recebido</th>
                    <th class="px-4 py-2">Estado</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                @forelse ($solicitacoes as $solicitacao)
                    <tr>
                        <td class="px-4 py-2 font-medium text-slate-900 dark:text-zinc-100">{{ $solicitacao->nome }}</td>
                        <td class="px-4 py-2 text-slate-600 dark:text-zinc-300">{{ $solicitacao->email }} {{ $solicitacao->telefone }}</td>
                        <td class="px-4 py-2 text-slate-600 dark:text-zinc-300">{{ config('estudiomusica.tipos_servico.'.$solicitacao->tipo_servico_desejado, '—') }}</td>
                        <td class="px-4 py-2 text-slate-600 dark:text-zinc-300">{{ \Illuminate\Support\Str::limit($solicitacao->mensagem, 60) }}</td>
                        <td class="px-4 py-2 text-xs {{ $solicitacao->aceita_marketing ? 'text-emerald-700' : 'text-slate-400' }}">{{ $solicitacao->aceita_marketing ? 'Aceitou' : 'Não' }}</td>
                        <td class="px-4 py-2 text-xs text-slate-500 dark:text-zinc-400">{{ $solicitacao->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2">
                            <form method="POST" action="{{ route('estudiomusica.solicitacoes.estado', $solicitacao) }}">
                                @csrf
                                @method('PUT')
                                <select name="estado" onchange="this.form.submit()" class="rounded-md border-slate-300 text-xs dark:border-zinc-700">
                                    @foreach (['pendente', 'contactado', 'convertido', 'descartado'] as $estado)
                                        <option value="{{ $estado }}" @selected($solicitacao->estado === $estado)>{{ ucfirst($estado) }}</option>
                                    @endforeach
                                </select>
                            </form>
                        </td>
                        <td class="px-4 py-2">
                            @if ($solicitacao->estado !== 'convertido')
                                <form method="POST" action="{{ route('estudiomusica.solicitacoes.converter', $solicitacao) }}">
                                    @csrf
                                    <button type="submit" class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50 dark:border-zinc-700">Converter em cliente</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-slate-500 dark:text-zinc-400">Ainda sem pedidos recebidos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $solicitacoes->links() }}
</div>
@endsection
