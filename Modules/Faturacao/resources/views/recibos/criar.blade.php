@extends('core::layouts.app')

@section('titulo', 'Registar recibo')

@section('conteudo')
    <div class="w-full max-w-xl">
        <h1 class="text-xl font-semibold text-slate-900">Registar recibo</h1>
        <p class="mt-1 text-sm text-slate-500">
            Fatura <strong>{{ $fatura->numero_documento }}</strong> — {{ $fatura->cliente_nome ?? $fatura->cliente->nome }}.
            O recibo é emitido logo (recebe número e assinatura fiscal) e não pode ser alterado nem apagado.
        </p>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-4 rounded-md bg-slate-50 border border-slate-200 p-4 text-sm text-slate-600">
            <div class="flex justify-between"><span>Total da fatura</span><span>{{ number_format((float) $fatura->valor_total, 2, ',', ' ') }} {{ $fatura->moeda }}</span></div>
            <div class="flex justify-between"><span>Já recebido</span><span>{{ number_format($resumo['recebido'] / 100, 2, ',', ' ') }}</span></div>
            <div class="flex justify-between"><span>Notas de crédito</span><span>{{ number_format($resumo['creditado'] / 100, 2, ',', ' ') }}</span></div>
            <div class="mt-1 flex justify-between border-t border-slate-200 pt-1 font-semibold text-slate-800"><span>Em dívida</span><span>{{ number_format($resumo['saldo'] / 100, 2, ',', ' ') }} {{ $fatura->moeda }}</span></div>
        </div>

        <form method="POST" action="{{ route('faturacao.recibos.guardar') }}" class="mt-6 space-y-5"
              onsubmit="return confirm('Emitir este recibo? Depois de emitido não pode ser alterado nem apagado.');">
            @csrf
            <input type="hidden" name="fatura_id" value="{{ $fatura->id }}">

            <div>
                <label for="valor" class="block text-sm font-medium text-slate-700">Valor recebido ({{ $fatura->moeda }})</label>
                <input type="number" step="0.01" min="0.01" name="valor" id="valor" required value="{{ old('valor', $saldoSugerido) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>

            <div>
                <label for="meio_pagamento" class="block text-sm font-medium text-slate-700">Meio de pagamento</label>
                <select name="meio_pagamento" id="meio_pagamento" required class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    @foreach ($meiosPagamento as $codigo => $rotulo)
                        <option value="{{ $codigo }}" @selected(old('meio_pagamento') === $codigo)>{{ $rotulo }}</option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                Emitir recibo
            </button>
        </form>

        <p class="mt-4 text-sm"><a href="{{ route('faturacao.faturas.mostrar', $fatura->id) }}" class="font-medium text-indigo-600 hover:text-indigo-500">← Voltar à fatura</a></p>
    </div>
@endsection
