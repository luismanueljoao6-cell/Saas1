@extends('core::layouts.app')

@section('titulo', $fatura->numero_documento ?? 'Rascunho de fatura')

@section('conteudo')
    <div class="w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">
                    {{ $fatura->numero_documento ?? 'Rascunho #'.$fatura->id }}
                </h1>
                <p class="text-sm text-slate-500">
                    @php
                        $clienteNome = $fatura->cliente_nome ?? $fatura->cliente->nome;
                        $clienteNif = $fatura->cliente_nome !== null ? $fatura->cliente_nif : $fatura->cliente->nif;
                    @endphp
                    {{ $clienteNome }} @if($clienteNif) — NIF {{ $clienteNif }} @endif
                </p>
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-medium {{ $fatura->estado === 'emitida' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                {{ ucfirst($fatura->estado) }}
            </span>
        </div>

        @if (session('sucesso'))
            <div class="mt-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
        @endif
        @if (session('erro'))
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ session('erro') }}</div>
        @endif

        @if ($fatura->rejeitado_documento_no)
            <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Esta fatura corrige o documento <strong>{{ $fatura->rejeitado_documento_no }}</strong>, que a AGT não aceitou.
            </div>
        @endif

        <table class="mt-6 w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-slate-500">
                    <th class="py-2">Descrição</th>
                    <th class="py-2 text-right">Qtd</th>
                    <th class="py-2 text-right">Preço</th>
                    <th class="py-2 text-right">IVA</th>
                    <th class="py-2 text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($fatura->linhas as $linha)
                    <tr class="border-b border-slate-100">
                        <td class="py-2">{{ $linha->descricao }}</td>
                        <td class="py-2 text-right">{{ rtrim(rtrim($linha->quantidade, '0'), '.') }}</td>
                        <td class="py-2 text-right">{{ number_format((float) $linha->preco_unitario, 2, ',', ' ') }}</td>
                        <td class="py-2 text-right">{{ rtrim(rtrim($linha->taxa_iva, '0'), '.') }}%</td>
                        <td class="py-2 text-right">{{ number_format((float) $linha->valor_total, 2, ',', ' ') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="mt-4 flex justify-end">
            <div class="w-48 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span>{{ number_format((float) $fatura->valor_sem_iva, 2, ',', ' ') }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">IVA</span><span>{{ number_format((float) $fatura->valor_iva, 2, ',', ' ') }}</span></div>
                <div class="flex justify-between border-t border-slate-200 pt-1 font-semibold"><span>Total</span><span>{{ number_format((float) $fatura->valor_total, 2, ',', ' ') }} {{ $fatura->moeda }}</span></div>
            </div>
        </div>

        @if (config('faturacao.agt.ativo') && $fatura->urlQrAgt())
            @php
                $qrImagem = \Modules\Faturacao\Support\QrCodeAgt::dataUri($fatura->urlQrAgt());
            @endphp
            <div class="mt-6 flex items-center gap-4 border-t border-slate-200 pt-4">
                @if ($qrImagem)
                    <img src="{{ $qrImagem }}" alt="QR Code da AGT" width="175" height="175">
                @endif
                <p class="text-xs text-slate-500">Consulte este documento no portal da AGT: {{ $fatura->urlQrAgt() }}</p>
            </div>
        @endif

        @if ($fatura->estado === 'rascunho')
            <form method="POST" action="{{ route('faturacao.faturas.emitir', $fatura) }}" class="mt-6">
                @csrf
                <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Emitir fatura
                </button>
                <p class="mt-2 text-center text-xs text-slate-500">Depois de emitida, este documento não pode ser alterado nem apagado — só corrigido por Nota de Crédito.</p>
            </form>

            <div class="mt-4 flex items-center justify-between border-t border-slate-200 pt-4 text-sm">
                <a href="{{ route('faturacao.faturas.editar', $fatura->id) }}" class="font-medium text-indigo-600 hover:text-indigo-500">Editar rascunho</a>
                <form method="POST" action="{{ route('faturacao.faturas.eliminar', $fatura->id) }}"
                      onsubmit="return confirm('Eliminar este rascunho? Esta ação não pode ser desfeita.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="font-medium text-red-600 hover:text-red-500">Eliminar rascunho</button>
                </form>
            </div>
        @else
            <div class="mt-6 rounded-lg bg-slate-50 border border-slate-200 p-4 font-mono text-xs text-slate-500">
                <p>Data/hora do sistema: {{ $fatura->data_hora_sistema->format('Y-m-d H:i:s') }}</p>
                <p class="mt-1 break-all">Hash: {{ Str::limit($fatura->hash, 60) }}</p>
                <p class="mt-1">Versão da chave: {{ $fatura->chave_versao }}</p>
            </div>

            @include('faturacao::partials.estado-agt', ['documento' => $fatura])

            @if ($fatura->correcao)
                <div class="mt-4 rounded-md border border-slate-200 px-4 py-3 text-sm text-slate-600">
                    Corrigida por
                    <a href="{{ route('faturacao.faturas.mostrar', $fatura->correcao->id) }}" class="font-medium text-indigo-600 hover:text-indigo-500">
                        {{ $fatura->correcao->numero_documento ?? 'rascunho #'.$fatura->correcao->id }}
                    </a>.
                </div>
            @elseif (config('faturacao.agt.ativo') && in_array($fatura->agtSubmissao?->estado, ['invalido', 'rejeitado'], true))
                <form method="POST" action="{{ route('faturacao.faturas.corrigir', $fatura->id) }}" class="mt-4 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                    @csrf
                    <p>A AGT não aceita reenviar este documento: a correção é uma fatura nova (com outro número) que segue como correção desta.</p>
                    <button type="submit" class="mt-3 rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">
                        Criar rascunho de correção
                    </button>
                </form>
            @endif

            @if ($resumo)
                <div class="mt-4 rounded-lg border border-slate-200 p-4 text-sm">
                    <p class="font-medium text-slate-700">Recebimentos</p>
                    <div class="mt-2 space-y-1 text-slate-600">
                        <div class="flex justify-between"><span>Recebido (recibos)</span><span>{{ number_format($resumo['recebido'] / 100, 2, ',', ' ') }}</span></div>
                        <div class="flex justify-between"><span>Creditado (notas de crédito)</span><span>{{ number_format($resumo['creditado'] / 100, 2, ',', ' ') }}</span></div>
                        <div class="flex justify-between border-t border-slate-200 pt-1 font-semibold text-slate-800"><span>Em dívida</span><span>{{ number_format($resumo['saldo'] / 100, 2, ',', ' ') }} {{ $fatura->moeda }}</span></div>
                    </div>

                    @if ($fatura->recibos->isNotEmpty())
                        <ul class="mt-3 space-y-1 border-t border-slate-100 pt-2 text-slate-600">
                            @foreach ($fatura->recibos as $recibo)
                                <li class="flex justify-between">
                                    <a href="{{ route('faturacao.recibos.mostrar', $recibo->id) }}" class="text-indigo-600 hover:text-indigo-500">{{ $recibo->numero_documento ?? 'Recibo #'.$recibo->id }}</a>
                                    <span>{{ number_format((float) $recibo->valor, 2, ',', ' ') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-slate-100 pt-3 font-medium">
                        @if ($resumo['saldo'] > 0)
                            <a href="{{ route('faturacao.recibos.criar', ['fatura' => $fatura->id]) }}" class="text-indigo-600 hover:text-indigo-500">Registar recibo</a>
                        @endif
                        <a href="{{ route('faturacao.notas.criar', ['tipo' => 'credito', 'fatura' => $fatura->id]) }}" class="text-indigo-600 hover:text-indigo-500">Nota de crédito (parcial)</a>
                        <a href="{{ route('faturacao.notas.criar', ['tipo' => 'debito', 'fatura' => $fatura->id]) }}" class="text-indigo-600 hover:text-indigo-500">Nota de débito</a>
                    </div>
                </div>
            @endif

            @if ($fatura->notasCreditoDebito->isNotEmpty())
                <div class="mt-4 rounded-lg border border-slate-200 p-4 text-sm">
                    <p class="font-medium text-slate-700">Notas associadas</p>
                    <ul class="mt-2 space-y-1 text-slate-600">
                        @foreach ($fatura->notasCreditoDebito as $nota)
                            <li class="flex justify-between">
                                <a href="{{ route('faturacao.notas.mostrar', $nota->id) }}" class="text-indigo-600 hover:text-indigo-500">{{ $nota->numero_documento ?? 'Rascunho #'.$nota->id }} — {{ $nota->tipo === 'credito' ? 'Crédito' : 'Débito' }} ({{ $nota->estado }})</a>
                                <span>{{ number_format((float) $nota->valor_total, 2, ',', ' ') }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! $fatura->notasCreditoDebito->contains('tipo', 'credito'))
                <form method="POST" action="{{ route('faturacao.faturas.anular', $fatura->id) }}" class="mt-4 space-y-2"
                      onsubmit="return confirm('Emitir uma nota de crédito que anula TODA esta fatura? Esta ação é fiscal e não pode ser desfeita.');">
                    @csrf
                    <label for="motivo" class="block text-sm font-medium text-slate-700">Anular fatura (nota de crédito total)</label>
                    <input type="text" name="motivo" id="motivo" required minlength="3" maxlength="60" value="{{ old('motivo') }}"
                           placeholder="Motivo (máx. 60 caracteres)"
                           class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    @error('motivo')
                        <p class="text-xs text-red-600">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="w-full rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500">
                        Anular com nota de crédito
                    </button>
                </form>
            @endif
        @endif
    </div>
@endsection
