@extends('core::layouts.app')
@section('titulo', 'Orçamentos')
@section('conteudo')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h1 class="text-2xl font-semibold">Orçamentos</h1><p class="mt-1 text-sm text-slate-500">Propostas comerciais sem valor fiscal.</p></div>
        <a href="{{ route('faturacao.orcamentos.criar') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Novo orçamento</a>
    </div>
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left dark:bg-zinc-800"><tr><th class="px-4 py-3">Número</th><th class="px-4 py-3">Cliente</th><th class="px-4 py-3">Validade</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3">Ações</th></tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
            @forelse($orcamentos as $orcamento)
                <tr><td class="px-4 py-3 font-medium">{{ $orcamento->numero }}</td><td class="px-4 py-3">{{ $orcamento->cliente?->nome }}</td><td class="px-4 py-3">{{ $orcamento->data_validade?->format('d/m/Y') ?? '—' }}</td><td class="px-4 py-3">{{ number_format((float)$orcamento->valor_total,2,',',' ') }} AOA</td><td class="px-4 py-3">{{ ucfirst($orcamento->estado) }}</td><td class="px-4 py-3">
                @if(in_array($orcamento->estado,['rascunho','enviado']))
                <form method="POST" action="{{ route('faturacao.orcamentos.estado',$orcamento->id) }}" class="inline">@csrf @method('PATCH')<input type="hidden" name="estado" value="aceite"><button class="text-indigo-600">Marcar aceite</button></form>
                @endif
                @if($orcamento->estado === 'aceite')
                <form method="POST" action="{{ route('faturacao.orcamentos.converter',$orcamento->id) }}" class="inline ml-2">@csrf<button class="text-emerald-600">Converter em fatura</button></form>
                @endif
            </td></tr>
            @empty<tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Ainda não existem orçamentos.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    {{ $orcamentos->links() }}
</div>
@endsection
