@extends('core::layouts.app')

@section('titulo', $fatura->numero_documento ?? 'Rascunho de fatura')

@section('conteudo')
    <div class="w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">
                    {{ $fatura->numero_documento ?? 'Rascunho #'.$fatura->id }}
                </h1>
                <p class="text-sm text-slate-500">{{ $fatura->cliente->nome }} @if($fatura->cliente->nif) — NIF {{ $fatura->cliente->nif }} @endif</p>
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-medium {{ $fatura->estado === 'emitida' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                {{ ucfirst($fatura->estado) }}
            </span>
        </div>

        @if (session('sucesso'))
            <div class="mt-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
        @endif
        @if (session('erro'))
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ session('erro') }}</div>
        @endif

        <table class="mt-6 w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500">
                    <th class="py-2">Descrição</th>
                    <th class="py-2 text-right">Qtd</th>
                    <th class="py-2 text-right">Preço</th>
                    <th class="py-2 text-right">IVA</th>
                    <th class="py-2 text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($fatura->linhas as $linha)
                    <tr class="border-b border-slate-100">
                        <td class="py-2">{{ $linha->descricao }}</td>
                        <td class="py-2 text-right">{{ rtrim(rtrim($linha->quantidade, '0'), '.') }}</td>
                        <td class="py-2 text-right">{{ number_format((float) $linha->preco_unitario, 2, ',', ' ') }}</td>
                        <td class="py-2 text-right">{{ rtrim(rtrim($linha->taxa_iva, '0'), '.') }}%</td>
                        <td class="py-2 text-right">{{ number_format((float) $linha->valor_total, 2, ',', ' ') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="mt-4 flex justify-end">
            <div class="w-48 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span>{{ number_format((float) $fatura->valor_sem_iva, 2, ',', ' ') }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">IVA</span><span>{{ number_format((float) $fatura->valor_iva, 2, ',', ' ') }}</span></div>
                <div class="flex justify-between border-t border-slate-200 pt-1 font-semibold"><span>Total</span><span>{{ number_format((float) $fatura->valor_total, 2, ',', ' ') }} {{ $fatura->moeda }}</span></div>
            </div>
        </div>

        @if ($fatura->estado === 'rascunho')
            <form method="POST" action="{{ route('faturacao.faturas.emitir', $fatura) }}" class="mt-6">
                @csrf
                <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Emitir fatura
                </button>
                <p class="mt-2 text-center text-xs text-slate-500">Depois de emitida, este documento não pode ser alterado nem apagado — só corrigido por Nota de Crédito.</p>
            </form>
        @else
            <div class="mt-6 rounded-lg bg-slate-50 border border-slate-200 p-4 font-mono text-xs text-slate-500">
                <p>Data/hora do sistema: {{ $fatura->data_hora_sistema->format('Y-m-d H:i:s') }}</p>
                <p class="mt-1 break-all">Hash: {{ Str::limit($fatura->hash, 60) }}</p>
                <p class="mt-1">Versão da chave: {{ $fatura->chave_versao }}</p>
            </div>
        @endif
    </div>
@endsection
