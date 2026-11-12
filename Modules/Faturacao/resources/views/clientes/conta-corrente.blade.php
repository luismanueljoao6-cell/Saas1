@extends('core::layouts.app')

@section('titulo', 'Conta corrente — '.$cliente->nome)

@section('conteudo')
    <div class="w-full space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <a href="{{ route('faturacao.clientes.index') }}" class="text-sm text-indigo-600 hover:underline">← Clientes</a>
                <h1 class="mt-2 text-xl font-semibold text-slate-900">Conta corrente</h1>
                <p class="text-sm text-slate-500">{{ $cliente->nome }} · {{ $cliente->nif ?: 'Consumidor final' }}</p>
            </div>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <label class="text-xs text-slate-600">De
                    <input type="date" name="inicio" value="{{ $extrato['inicio'] }}" class="mt-1 block rounded-md border border-slate-300 px-2 py-2 text-sm">
                </label>
                <label class="text-xs text-slate-600">Até
                    <input type="date" name="fim" value="{{ $extrato['fim'] }}" class="mt-1 block rounded-md border border-slate-300 px-2 py-2 text-sm">
                </label>
                <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">Filtrar</button>
            </form>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border bg-white p-4"><p class="text-xs text-slate-500">Saldo anterior</p><p class="mt-1 text-lg font-semibold">{{ number_format($extrato['saldoInicial'] / 100, 2, ',', '.') }} Kz</p></div>
            <div class="rounded-lg border bg-white p-4"><p class="text-xs text-slate-500">Débitos do período</p><p class="mt-1 text-lg font-semibold">{{ number_format($extrato['totalDebito'] / 100, 2, ',', '.') }} Kz</p></div>
            <div class="rounded-lg border bg-white p-4"><p class="text-xs text-slate-500">Créditos do período</p><p class="mt-1 text-lg font-semibold">{{ number_format($extrato['totalCredito'] / 100, 2, ',', '.') }} Kz</p></div>
            <div class="rounded-lg border bg-white p-4"><p class="text-xs text-slate-500">Saldo final</p><p class="mt-1 text-lg font-semibold">{{ number_format($extrato['saldoFinal'] / 100, 2, ',', '.') }} Kz</p></div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr><th class="px-4 py-3">Data</th><th class="px-4 py-3">Movimento</th><th class="px-4 py-3">Documento</th><th class="px-4 py-3 text-right">Débito</th><th class="px-4 py-3 text-right">Crédito</th><th class="px-4 py-3 text-right">Saldo</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr class="bg-slate-50 font-medium"><td class="px-4 py-3" colspan="5">Saldo de abertura</td><td class="px-4 py-3 text-right">{{ number_format($extrato['saldoInicial'] / 100, 2, ',', '.') }} Kz</td></tr>
                    @forelse ($extrato['movimentos'] as $movimento)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3">{{ $movimento['data'] }}</td>
                            <td class="px-4 py-3">{{ ['fatura' => 'Fatura', 'nota_credito' => 'Nota de crédito', 'nota_debito' => 'Nota de débito', 'recibo' => 'Recebimento', 'abatimento_adiantamento' => 'Abatimento de adiantamento'][$movimento['tipo']] ?? $movimento['tipo'] }}</td>
                            <td class="px-4 py-3">{{ $movimento['documento'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ $movimento['debito'] ? number_format($movimento['debito'] / 100, 2, ',', '.') : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ $movimento['credito'] ? number_format($movimento['credito'] / 100, 2, ',', '.') : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ number_format($movimento['saldo'] / 100, 2, ',', '.') }} Kz</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Sem movimentos neste período.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="text-xs text-slate-500">Os abatimentos de adiantamentos liquidam faturas finais, mas não são contabilizados como novo recebimento. Os recebimentos são reconhecidos uma única vez, na data do recibo.</p>
    </div>
@endsection
