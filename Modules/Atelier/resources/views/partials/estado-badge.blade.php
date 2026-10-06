@php
    $cores = [
        'pendente' => 'bg-slate-100 text-slate-700',
        'em_corte' => 'bg-amber-50 text-amber-700',
        'em_costura' => 'bg-amber-50 text-amber-700',
        'primeira_prova' => 'bg-blue-50 text-blue-700',
        'ajustes' => 'bg-blue-50 text-blue-700',
        'pronto_para_retirada' => 'bg-emerald-50 text-emerald-700',
        'entregue' => 'bg-emerald-100 text-emerald-800',
        'cancelado' => 'bg-red-50 text-red-700',
    ];
@endphp
{{-- Espera: $estado, $rotulo e, opcionalmente, $classe (classes extra). --}}
<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $cores[$estado] ?? 'bg-slate-100 text-slate-700' }} {{ $classe ?? '' }}">
    {{ $rotulo }}
</span>
