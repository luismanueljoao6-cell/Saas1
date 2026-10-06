@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Pedido #' . $pedido->id)

@section('conteudo')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-slate-900">Pedido #{{ $pedido->id }} — {{ $pedido->cliente?->nome }}</h1>
            <p class="text-sm text-slate-500">{{ $pedido->tipoServicoRotulo() }}</p>
        </div>
        @include('atelier::partials.estado-badge', ['estado' => $pedido->status, 'rotulo' => $pedido->statusRotulo(), 'classe' => 'text-sm'])
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            {{-- Detalhes da peça --}}
            <div class="rounded-md border border-slate-200 bg-white p-5">
                <h2 class="mb-3 text-sm font-semibold text-slate-700">Detalhes da peça</h2>
                <p class="mb-3 text-sm text-slate-700">{{ $pedido->descricao }}</p>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-xs text-slate-400">Tecido/cor</dt><dd>{{ $pedido->tecido_cor ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Aviamentos</dt><dd>{{ $pedido->aviamentos_necessarios ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Responsável</dt><dd>{{ $pedido->responsavel?->name ?? 'Não atribuído' }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Entrega prevista</dt><dd>{{ $pedido->data_prevista_entrega?->format('d/m/Y') ?? '—' }}</dd></div>
                </dl>

                @if ($pedido->fotos->isNotEmpty())
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($pedido->fotos as $foto)
                            <img src="{{ $foto->url() }}" class="h-20 w-20 rounded object-cover" alt="{{ $foto->legenda }}">
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Fluxo de estado --}}
            <div class="rounded-md border border-slate-200 bg-white p-5">
                <h2 class="mb-3 text-sm font-semibold text-slate-700">Avançar estado</h2>
                @if (empty($proximosEstados))
                    <p class="text-sm text-slate-500">Este pedido está num estado final.</p>
                @else
                    <div class="flex flex-wrap gap-2">
                        @foreach ($proximosEstados as $estado)
                            <form method="POST" action="{{ route('atelier.pedidos.avancar-estado', $pedido) }}"
                                  @if ($estado === 'cancelado') x-data @submit.prevent="if (confirm('Cancelar este pedido?')) $el.submit()" @endif>
                                @csrf
                                <input type="hidden" name="novo_estado" value="{{ $estado }}">
                                @if ($estado === 'cancelado')
                                    <input type="text" name="motivo_cancelamento" placeholder="Motivo (obrigatório)"
                                           class="mr-2 rounded-md border-slate-300 text-xs" required>
                                @endif
                                <button type="submit"
                                        class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                    {{ \Modules\Atelier\Models\Pedido::rotuloParaStatus($estado) }}
                                </button>
                            </form>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Provas --}}
            <div class="rounded-md border border-slate-200 bg-white p-5">
                <h2 class="mb-3 text-sm font-semibold text-slate-700">Provas agendadas</h2>
                <div class="mb-4 space-y-2">
                    @forelse ($pedido->provas as $prova)
                        <div class="flex items-center justify-between rounded-md border border-slate-100 px-3 py-2 text-sm">
                            <span>{{ $prova->tipoRotulo() }} — {{ $prova->data_hora_agendada->format('d/m/Y H:i') }}</span>
                            <span class="text-xs text-slate-500">{{ ucfirst($prova->estado) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Nenhuma prova agendada ainda.</p>
                    @endforelse
                </div>
                <form method="POST" action="{{ route('atelier.provas.guardar', $pedido) }}" class="grid grid-cols-3 gap-2">
                    @csrf
                    <select name="tipo" required class="rounded-md border-slate-300 text-sm">
                        <option value="primeira">1ª Prova</option>
                        <option value="segunda">2ª Prova</option>
                        <option value="entrega">Entrega</option>
                    </select>
                    <input type="datetime-local" name="data_hora_agendada" required class="rounded-md border-slate-300 text-sm">
                    <button type="submit" class="rounded-md bg-slate-800 text-sm font-medium text-white hover:bg-slate-700">Agendar</button>
                </form>
            </div>

            @if ($podeVerFinancas && $pedido->materiais->isNotEmpty())
                <div class="rounded-md border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-semibold text-slate-700">Materiais extra</h2>
                    <table class="w-full text-sm">
                        @foreach ($pedido->materiais as $material)
                            <tr>
                                <td>{{ $material->descricao }}</td>
                                <td class="text-right">{{ $material->quantidade }}</td>
                                <td class="text-right">{{ number_format($material->valor_total, 2, ',', '.') }} AOA</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif
        </div>

        {{-- Coluna lateral: financeiro + portal do cliente --}}
        <div class="space-y-6">
            @if ($linkPortal)
                <div class="rounded-md border border-slate-200 bg-white p-5">
                    <h2 class="mb-2 text-sm font-semibold text-slate-700">Portal do cliente</h2>
                    <p class="mb-2 text-xs text-slate-500">Link temporário — o cliente vê o estado do pedido sem precisar de conta.</p>
                    <input type="text" readonly value="{{ $linkPortal }}" onclick="this.select()"
                           class="w-full rounded-md border-slate-300 text-xs text-slate-600">
                </div>
            @endif

            @if ($podeVerFinancas)
                <div class="rounded-md border border-slate-200 bg-white p-5">
                    <h2 class="mb-3 text-sm font-semibold text-slate-700">Faturação</h2>
                    <dl class="mb-4 space-y-1 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Valor acordado</dt><dd>{{ number_format($pedido->valor_orcamento, 2, ',', '.') }} AOA</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Total com IVA</dt><dd class="font-medium">{{ number_format($pedido->valorTotalComIva(), 2, ',', '.') }} AOA</dd></div>
                        @if ($pedido->valor_sinal)
                            <div class="flex justify-between"><dt class="text-slate-500">Sinal pago</dt><dd>{{ number_format($pedido->valor_sinal, 2, ',', '.') }} AOA</dd></div>
                        @endif
                        <div class="flex justify-between"><dt class="text-slate-500">Saldo em falta</dt><dd class="font-medium">{{ number_format($pedido->saldoEmFalta(), 2, ',', '.') }} AOA</dd></div>
                    </dl>

                    @unless ($podeEmitirFaturas)
                        <p class="mb-3 rounded bg-amber-50 p-2 text-xs text-amber-700">
                            A empresa está em período de tolerância — não é possível gerar novos documentos fiscais.
                        </p>
                    @endunless

                    @if ($podeEmitirFaturas)
                        @unless ($pedido->jaTemSinalRegistado())
                            <form method="POST" action="{{ route('atelier.pedidos.registar-sinal', $pedido) }}" class="mb-3 space-y-2 border-t border-slate-100 pt-3">
                                @csrf
                                <p class="text-xs font-medium text-slate-600">Registar sinal/adiantamento</p>
                                <input type="number" step="0.01" name="valor" placeholder="Valor (AOA)" required class="w-full rounded-md border-slate-300 text-sm">
                                <select name="meio_pagamento" required class="w-full rounded-md border-slate-300 text-sm">
                                    <option value="dinheiro">Dinheiro</option>
                                    <option value="transferencia">Transferência</option>
                                    <option value="multicaixa">Multicaixa</option>
                                    <option value="outro">Outro</option>
                                </select>
                                <button type="submit" class="w-full rounded-md bg-indigo-600 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
                                    Registar sinal e emitir recibo
                                </button>
                            </form>
                        @else
                            <p class="mb-3 text-xs text-emerald-700">✓ Sinal registado (recibo {{ $pedido->reciboSinal?->numero_documento }})</p>
                        @endif

                        @unless ($pedido->jaTemFaturaFinal())
                            <form method="POST" action="{{ route('atelier.pedidos.gerar-fatura-final', $pedido) }}" class="border-t border-slate-100 pt-3">
                                @csrf
                                <button type="submit" class="w-full rounded-md bg-slate-800 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
                                    Gerar rascunho da fatura final
                                </button>
                            </form>
                        @else
                            <p class="text-xs text-emerald-700">✓ Fatura final: {{ $pedido->fatura?->numero_documento ?? 'rascunho' }}</p>
                            <a href="{{ route('faturacao.faturas.mostrar', $pedido->fatura) }}" class="text-xs text-indigo-600">Ver/emitir fatura →</a>

                            @if ($pedido->fatura?->estaEmitido() && ! $pedido->recibo_saldo_final_id)
                                <form method="POST" action="{{ route('atelier.pedidos.registar-pagamento-final', $pedido) }}" class="mt-3 space-y-2 border-t border-slate-100 pt-3">
                                    @csrf
                                    <p class="text-xs font-medium text-slate-600">Registar pagamento do saldo ({{ number_format($pedido->saldoEmFalta(), 2, ',', '.') }} AOA)</p>
                                    <select name="meio_pagamento" required class="w-full rounded-md border-slate-300 text-sm">
                                        <option value="dinheiro">Dinheiro</option>
                                        <option value="transferencia">Transferência</option>
                                        <option value="multicaixa">Multicaixa</option>
                                        <option value="outro">Outro</option>
                                    </select>
                                    <button type="submit" class="w-full rounded-md bg-indigo-600 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
                                        Registar pagamento e emitir recibo
                                    </button>
                                </form>
                            @elseif ($pedido->recibo_saldo_final_id)
                                <p class="mt-2 text-xs text-emerald-700">✓ Saldo pago (recibo {{ $pedido->reciboSaldoFinal?->numero_documento }})</p>
                            @endif
                        @endif
                    @endif
                </div>
            @endif
        </div>
    </div>
@endsection
