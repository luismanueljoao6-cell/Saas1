@extends('core::layouts.app')
@section('titulo', 'Orçamentos')
@section('conteudo')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Orçamentos e proformas</h1>
            <p class="mt-1 text-sm text-slate-500">Propostas comerciais sem valor fiscal. Cada cópia gera um novo rascunho.</p>
        </div>
        <a href="{{ route('faturacao.orcamentos.criar') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Novo orçamento</a>
    </div>

    <form method="GET" action="{{ route('faturacao.orcamentos.index') }}" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5 dark:border-zinc-800 dark:bg-zinc-900">
        <label class="text-sm lg:col-span-2">Pesquisar
            <input type="search" name="pesquisa" value="{{ $filtros['pesquisa'] ?? '' }}" placeholder="Número ou nome do cliente" class="mt-1 w-full rounded-lg border-slate-300 dark:border-zinc-700 dark:bg-zinc-800">
        </label>
        <label class="text-sm">Estado
            <select name="estado" class="mt-1 w-full rounded-lg border-slate-300 dark:border-zinc-700 dark:bg-zinc-800">
                <option value="">Todos os estados</option>
                @foreach(['rascunho'=>'Rascunho','enviado'=>'Enviado','aceite'=>'Aceite','recusado'=>'Recusado','expirado'=>'Expirado','convertido'=>'Convertido'] as $valor=>$rotulo)
                    <option value="{{ $valor }}" @selected(($filtros['estado'] ?? '') === $valor)>{{ $rotulo }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">De
            <input type="date" name="data_inicio" value="{{ $filtros['data_inicio'] ?? '' }}" class="mt-1 w-full rounded-lg border-slate-300 dark:border-zinc-700 dark:bg-zinc-800">
        </label>
        <label class="text-sm">Até
            <input type="date" name="data_fim" value="{{ $filtros['data_fim'] ?? '' }}" class="mt-1 w-full rounded-lg border-slate-300 dark:border-zinc-700 dark:bg-zinc-800">
        </label>
        <div class="flex gap-2 lg:col-span-5">
            <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white dark:bg-zinc-700">Aplicar filtros</button>
            <a href="{{ route('faturacao.orcamentos.index') }}" class="rounded-lg border px-4 py-2 text-sm">Limpar</a>
        </div>
    </form>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left dark:bg-zinc-800">
                <tr>
                    <th class="px-4 py-3">Número / Data</th><th class="px-4 py-3">Cliente</th><th class="px-4 py-3">Validade</th>
                    <th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
            @forelse($orcamentos as $orcamento)
                @php
                    $expirado = $orcamento->estado === 'expirado' || ($orcamento->data_validade && $orcamento->data_validade->isBefore(today()) && !in_array($orcamento->estado, ['convertido', 'recusado']));
                    $estadoClasses = ['rascunho'=>'bg-slate-100 text-slate-700','enviado'=>'bg-blue-100 text-blue-800','aceite'=>'bg-emerald-100 text-emerald-800','recusado'=>'bg-red-100 text-red-800','expirado'=>'bg-orange-100 text-orange-800','convertido'=>'bg-violet-100 text-violet-800'];
                @endphp
                <tr>
                    <td class="px-4 py-3"><span class="font-medium">{{ $orcamento->numero }}</span><span class="block text-xs text-slate-500">{{ $orcamento->created_at?->format('d/m/Y') }}</span></td>
                    <td class="px-4 py-3">{{ $orcamento->cliente?->nome ?? '—' }}</td>
                    <td class="px-4 py-3">
                        {{ $orcamento->data_validade?->format('d/m/Y') ?? '—' }}
                        @if($expirado)<span class="mt-1 block text-xs font-semibold text-red-600">Expirado</span>@endif
                    </td>
                    @php
                        $total = \Modules\Faturacao\Support\Dinheiro::formatar(\Modules\Faturacao\Support\Dinheiro::centimos($orcamento->valor_total));
                        [$inteira, $decimal] = explode('.', $total);
                    @endphp
                    <td class="px-4 py-3 text-right font-medium">{{ number_format((int) $inteira, 0, ',', ' ') }},{{ $decimal }} AOA</td>
                    <td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-semibold {{ $expirado ? 'bg-orange-100 text-orange-800' : ($estadoClasses[$orcamento->estado] ?? 'bg-slate-100 text-slate-700') }}">{{ $expirado ? 'Expirado' : ucfirst($orcamento->estado) }}</span></td>
                    <td class="px-4 py-3">
                        <div class="flex min-w-44 flex-wrap gap-x-3 gap-y-2">
                            <a href="{{ route('faturacao.orcamentos.mostrar', $orcamento->id) }}" class="text-indigo-600">Ver</a>
                            @if($orcamento->estado === 'rascunho')
                                <a href="{{ route('faturacao.orcamentos.editar', $orcamento->id) }}" class="text-amber-600">Editar</a>
                            @endif
                            <form method="POST" action="{{ route('faturacao.orcamentos.duplicar', $orcamento->id) }}">@csrf<button class="text-slate-600">Copiar</button></form>
                            @if(in_array($orcamento->estado,['rascunho','enviado']) && !$expirado)
                                <form method="POST" action="{{ route('faturacao.orcamentos.estado',$orcamento->id) }}">@csrf @method('PATCH')<input type="hidden" name="estado" value="aceite"><button class="text-emerald-700">Marcar aceite</button></form>
                                <form method="POST" action="{{ route('faturacao.orcamentos.estado',$orcamento->id) }}">@csrf @method('PATCH')<input type="hidden" name="estado" value="recusado"><button class="text-red-600">Recusar</button></form>
                            @endif
                            @if($orcamento->estado === 'aceite' && !$expirado)
                                <form method="POST" action="{{ route('faturacao.orcamentos.converter',$orcamento->id) }}">@csrf<button class="font-semibold text-emerald-700">Converter em fatura</button></form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">Não foram encontrados orçamentos para estes filtros.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $orcamentos->links() }}
</div>
@endsection
