@php
    $tabela = $tabela ?? [];
    $colunas = $tabela['colunas'] ?? [];
    $linhas = collect($tabela['linhas'] ?? []);
    $formatarValor = static function (mixed $valor, array $coluna): string {
        if (($coluna['tipo'] ?? null) === 'moeda' && is_numeric($valor)) {
            return number_format((float) $valor, 2, ',', ' ').' Kz';
        }
        if (($coluna['tipo'] ?? null) === 'data' && $valor) {
            try {
                return Illuminate\Support\Carbon::parse($valor)->format('d/m/Y');
            } catch (Throwable) {
                return (string) $valor;
            }
        }
        return is_scalar($valor) || $valor === null ? (string) ($valor ?? '—') : '';
    };
@endphp
<section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-zinc-800">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-zinc-100">{{ $tabela['titulo'] ?? 'Registos' }}</h2>
        <span class="text-xs text-slate-500 dark:text-zinc-400">{{ $linhas->count() }} registo(s)</span>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 dark:divide-zinc-800">
            <thead class="bg-slate-50 dark:bg-zinc-950">
                <tr>
                    @foreach ($colunas as $coluna)
                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-zinc-400">{{ $coluna['rotulo'] ?? $coluna['chave'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                @forelse ($linhas as $linha)
                    <tr class="hover:bg-slate-50 dark:hover:bg-zinc-800/50">
                        @foreach ($colunas as $coluna)
                            @php($valor = data_get($linha, $coluna['chave'] ?? ''))
                            <td class="whitespace-nowrap px-4 py-3 text-sm {{ ($coluna['forte'] ?? false) ? 'font-medium text-slate-900 dark:text-zinc-100' : 'text-slate-600 dark:text-zinc-300' }}">
                                @if (($coluna['tipo'] ?? null) === 'badge')
                                    <span class="inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs dark:bg-zinc-800">{{ $formatarValor($valor, $coluna) }}</span>
                                @else
                                    {{ $formatarValor($valor, $coluna) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ max(1, count($colunas)) }}" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-zinc-400">{{ $tabela['vazio'] ?? 'Não existem registos.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
