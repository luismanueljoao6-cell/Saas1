@php
    $kpi = $kpi ?? [];
    $tendencia = $kpi['tendencia'] ?? 'neutra';
    $classeTendencia = $tendencia === 'alta'
        ? 'text-emerald-600 dark:text-emerald-400'
        : ($tendencia === 'baixa' ? 'text-amber-600 dark:text-amber-400' : 'text-slate-500 dark:text-zinc-400');
@endphp
<article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <div class="flex items-start justify-between gap-3">
        <p class="text-sm font-medium text-slate-500 dark:text-zinc-400">{{ $kpi['rotulo'] ?? 'Indicador' }}</p>
        <span class="rounded-lg bg-indigo-50 p-2 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300">
            @include('core::partials.icone', ['nome' => $kpi['icone'] ?? '', 'classe' => 'size-5'])
        </span>
    </div>
    <p class="mt-3 text-2xl font-semibold tracking-tight text-slate-900 dark:text-zinc-50">{{ $kpi['valor'] ?? '—' }}</p>
    @if (isset($kpi['progresso']))
        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-zinc-800">
            <div class="h-full rounded-full bg-indigo-600" style="width: {{ max(0, min(100, (float) $kpi['progresso'])) }}%"></div>
        </div>
        @if (isset($kpi['limite']))
            <p class="mt-2 text-xs text-slate-500 dark:text-zinc-400">{{ $kpi['limite'] }}</p>
        @endif
    @elseif (isset($kpi['variacao']))
        <p class="mt-2 text-xs {{ $classeTendencia }}">{{ $kpi['variacao'] }} <span class="text-slate-500 dark:text-zinc-400">{{ $kpi['nota'] ?? '' }}</span></p>
    @endif
</article>
