@php
    $tabela = $tabela ?? [];
    $colunas = $tabela['colunas'] ?? [];
    $linhas = $tabela['linhas'] ?? [];
    $fmt = function ($valor, $tipo = null) {
        if ($valor === null || $valor === '') return '—';
        if ($tipo === 'moeda' && is_numeric($valor)) return number_format((float) $valor, 2, ',', ' ').' Kz';
        if ($tipo === 'data') {
            try { return \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y'); } catch (\Throwable $e) { return $valor; }
        }
        return $valor;
    };
@endphp
<section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-4 dark:border-zinc-800">
        <h2 class="font-semibold text-slate-900 dark:text-zinc-100">{{ $tabela['titulo'] ?? 'Registos' }}</h2>
        @if (!empty($tabela['filtroEstado']) && !empty($tabela['estados']))
            <label class="text-sm text-slate-500">Filtrar por estado
                <select class="ml-2 rounded-lg border border-slate-200 bg-white px-2 py-1 text-sm dark:border-zinc-700 dark:bg-zinc-800" onchange="const v=this.value;this.closest('section').querySelectorAll('tbody tr').forEach(r=>r.hidden=!!v&&r.dataset.estado!==v)">
                    <option value="">Todos</option>
                    @foreach ($tabela['estados'] as $estado)<option value="{{ $estado }}">{{ $estado }}</option>@endforeach
                </select>
            </label>
        @endif
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-zinc-800">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-zinc-950 dark:text-zinc-400"><tr>
                @foreach ($colunas as $coluna)<th class="whitespace-nowrap px-4 py-3 font-semibold {{ ($coluna['alinhar'] ?? '') === 'direita' ? 'text-right' : '' }}">{{ $coluna['rotulo'] ?? $coluna['chave'] }}</th>@endforeach
            </tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                @forelse ($linhas as $linha)
                    <tr @if(!empty($tabela['filtroEstado'])) data-estado="{{ data_get($linha, $tabela['filtroEstado']) }}" @endif class="text-slate-700 dark:text-zinc-300">
                        @foreach ($colunas as $coluna)
                            @php($valor = data_get($linha, $coluna['chave'] ?? ''))
                            <td class="whitespace-nowrap px-4 py-3 {{ ($coluna['alinhar'] ?? '') === 'direita' ? 'text-right' : '' }} {{ !empty($coluna['forte']) ? 'font-medium text-slate-900 dark:text-zinc-100' : '' }}">{{ $fmt($valor, $coluna['tipo'] ?? null) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ max(1, count($colunas)) }}" class="px-4 py-8 text-center text-slate-500 dark:text-zinc-400">{{ $tabela['vazio'] ?? 'Nenhum registo encontrado.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
