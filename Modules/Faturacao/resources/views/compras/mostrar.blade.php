@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-5xl space-y-5 px-4 py-6">
    <div class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-bold">Compra {{ $compra->numero }}</h1><p class="text-sm text-slate-500">{{ $compra->fornecedor->nome }} · {{ $compra->data_documento->format('d/m/Y') }}</p></div><a href="{{ route('faturacao.compras.index') }}" class="rounded-md border px-4 py-2">Voltar</a></div>
    @if (session('sucesso'))<div class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>@endif
    @if ($errors->any())<div class="rounded-md bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>@endif
    <section class="rounded-lg border bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"><div class="mb-3 flex justify-between"><span class="font-semibold">Estado: {{ ucfirst($compra->estado) }}</span><span>{{ $compra->referencia_fornecedor ?: 'Sem referência externa' }}</span></div>
        <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="text-left"><tr><th class="p-2">Artigo</th><th class="p-2">Qtd.</th><th class="p-2">Preço</th><th class="p-2">IVA</th><th class="p-2">Total</th></tr></thead><tbody>@foreach ($compra->linhas as $linha)<tr class="border-t dark:border-zinc-700"><td class="p-2">{{ $linha->descricao }}</td><td class="p-2">{{ $linha->quantidade }}</td><td class="p-2">{{ number_format((float) $linha->preco_unitario, 2, ',', '.') }}</td><td class="p-2">{{ number_format((float) $linha->valor_iva, 2, ',', '.') }}</td><td class="p-2">{{ number_format((float) $linha->total, 2, ',', '.') }}</td></tr>@endforeach</tbody></table></div>
        <div class="mt-4 ml-auto max-w-xs space-y-1 text-right text-sm"><p>Subtotal: {{ number_format((float) $compra->subtotal, 2, ',', '.') }} Kz</p><p>IVA: {{ number_format((float) $compra->total_iva, 2, ',', '.') }} Kz</p><p class="text-lg font-bold">Total: {{ number_format((float) $compra->total, 2, ',', '.') }} Kz</p></div>
        @if ($compra->estado === 'rascunho')<div class="mt-5 flex flex-wrap gap-2 border-t pt-4 dark:border-zinc-700"><form method="POST" action="{{ route('faturacao.compras.receber', $compra) }}">@csrf<button class="rounded-md bg-emerald-700 px-5 py-2 font-semibold text-white" onclick="return confirm('Confirmas a receção? O stock será atualizado uma única vez.')">Confirmar receção e atualizar stock</button></form><form method="POST" action="{{ route('faturacao.compras.cancelar', $compra) }}">@csrf<button class="rounded-md border border-rose-300 px-5 py-2 font-semibold text-rose-700" onclick="return confirm('Cancelar esta compra? O stock não será alterado.')">Cancelar rascunho</button></form></div>@elseif ($compra->recebida_em)<p class="mt-4 text-sm text-emerald-700">Recebida em {{ $compra->recebida_em->format('d/m/Y H:i') }}.</p>@endif
        <div class="mt-4 grid gap-2 border-t pt-4 text-sm dark:border-zinc-700 sm:grid-cols-3">
            <p>Total pago: <strong>{{ number_format((float) $compra->total_pago, 2, ',', '.') }} Kz</strong></p>
            <p>Por pagar: <strong>{{ number_format(max(0, (float) $compra->total - (float) $compra->total_pago), 2, ',', '.') }} Kz</strong></p>
            <p>Estado financeiro: <strong>{{ ucfirst($compra->estado_pagamento ?? 'pendente') }}</strong></p>
        </div>
        @if ($compra->pagamentos->isNotEmpty())
            <div class="mt-4 overflow-x-auto border-t pt-4 dark:border-zinc-700">
                <h2 class="mb-2 font-semibold">Histórico de pagamentos</h2>
                <table class="min-w-full text-sm"><thead><tr class="text-left"><th class="p-2">Data</th><th class="p-2">Método</th><th class="p-2">Referência</th><th class="p-2">Valor</th></tr></thead><tbody>
                    @foreach ($compra->pagamentos as $pagamento)
                        <tr class="border-t dark:border-zinc-700"><td class="p-2">{{ $pagamento->data_pagamento->format('d/m/Y') }}</td><td class="p-2">{{ ucfirst($pagamento->metodo) }}</td><td class="p-2">{{ $pagamento->referencia ?: '—' }}</td><td class="p-2">{{ number_format((float) $pagamento->valor, 2, ',', '.') }} Kz</td></tr>
                    @endforeach
                </tbody></table>
            </div>
        @endif
        @if ($compra->estado === 'recebida' && (float) $compra->total_pago < (float) $compra->total)
            <form method="POST" action="{{ route('faturacao.compras.pagamentos.store', $compra) }}" class="mt-5 space-y-3 border-t pt-4 dark:border-zinc-700">
                @csrf
                <h2 class="font-semibold">Registar pagamento</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm">Valor (Kz)<input name="valor" type="number" min="0.01" max="{{ number_format((float) $compra->total - (float) $compra->total_pago, 2, '.', '') }}" step="0.01" required class="rounded-md border px-3 py-2"></label>
                    <label class="grid gap-1 text-sm">Data<input name="data_pagamento" type="date" value="{{ today()->toDateString() }}" required class="rounded-md border px-3 py-2"></label>
                    <label class="grid gap-1 text-sm">Método<select name="metodo" required class="rounded-md border px-3 py-2"><option value="dinheiro">Dinheiro</option><option value="transferencia">Transferência</option><option value="referencia">Referência</option><option value="multicaixa">Multicaixa</option><option value="outro">Outro</option></select></label>
                    <label class="grid gap-1 text-sm">Referência<input name="referencia" maxlength="100" class="rounded-md border px-3 py-2"></label>
                    <label class="grid gap-1 text-sm sm:col-span-2">Observações<textarea name="observacoes" maxlength="1000" class="rounded-md border px-3 py-2"></textarea></label>
                </div>
                <button class="rounded-md bg-blue-700 px-5 py-2 font-semibold text-white">Registar pagamento</button>
            </form>
        @endif
    </section>
</div>
@endsection
