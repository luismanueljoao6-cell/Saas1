@extends('core::layouts.app')

@section('titulo', 'Pagamento pendente')

@section('conteudo')
    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Falta só o pagamento</h1>
        <p class="mt-1 text-sm text-slate-500">
            Plano <strong>{{ $pagamento->subscricao->plano->nome }}</strong> —
            {{ number_format((float) $pagamento->valor, 0, ',', ' ') }} {{ $pagamento->moeda }}
        </p>

        <div class="mt-6 rounded-lg bg-slate-50 border border-slate-200 p-6">
            <p class="text-xs uppercase tracking-wide text-slate-500">Referência Multicaixa</p>
            <p class="mt-1 font-mono text-2xl font-semibold text-slate-900" x-data
               @click="navigator.clipboard.writeText('{{ $pagamento->referencia_externa }}')">
                {{ $pagamento->referencia_externa }}
            </p>
            <p class="mt-2 text-xs text-slate-500">Toca para copiar. Válida até {{ $pagamento->expira_em?->format('d/m/Y') }}.</p>
        </div>

        <p class="mt-6 text-sm text-slate-500">
            Assim que o pagamento for confirmado pelo banco, a tua subscrição fica ativa
            automaticamente — não precisas de fazer mais nada nem de recarregar esta página.
        </p>

        <a href="{{ route('core.painel') }}" class="mt-6 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500">
            &larr; Voltar ao painel
        </a>
    </div>
@endsection
