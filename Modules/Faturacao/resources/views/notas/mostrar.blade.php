@extends('core::layouts.app')

@section('titulo', $nota->numero_documento ?? 'Rascunho de nota')

@section('conteudo')
    <div class="w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">
                    {{ $nota->numero_documento ?? 'Rascunho #'.$nota->id }}
                    <span class="ml-1 text-sm font-normal text-slate-500">{{ $nota->tipo === 'credito' ? 'Nota de crédito' : 'Nota de débito' }}</span>
                </h1>
                <p class="text-sm text-slate-500">
                    @php
                        $clienteNome = $nota->cliente_nome ?? $nota->cliente->nome;
                        $clienteNif = $nota->cliente_nome !== null ? $nota->cliente_nif : $nota->cliente->nif;
                    @endphp
                    {{ $clienteNome }} @if($clienteNif) — NIF {{ $clienteNif }} @endif
                </p>
                @if ($nota->fatura)
                    <p class="mt-1 text-sm text-slate-500">
                        Fatura de origem:
                        <a href="{{ route('faturacao.faturas.mostrar', $nota->fatura->id) }}" class="font-medium text-indigo-600 hover:text-indigo-500">{{ $nota->fatura->numero_documento }}</a>
                    </p>
                @endif
                <p class="mt-1 text-sm text-slate-500">Motivo: {{ $nota->motivo }}</p>
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-medium {{ $nota->estado === 'emitida' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                {{ ucfirst($nota->estado) }}
            </span>
        </div>

        @if (session('sucesso'))
            <div class="mt-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
        @endif
        @if (session('erro'))
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ session('erro') }}</div>
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
                @foreach ($nota->linhas as $linha)
                    <tr class="border-b border-slate-100">
                        <td class="py-2">{{ $linha->descricao }}</td>
                        <td class="py-2 text-right">{{ rtrim(rtrim($linha->quantidade, '0'), '.') }}</td>
                        <td class="py-2 text-right">{{ number_format((float) $linha->preco_unitario, 2, ',', ' ') }}</td>
                        <td class="py-2 text-right">{{ rtrim(rtrim($linha->taxa_iva, '0'), '.') ?: '0' }}%</td>
                        <td class="py-2 text-right">{{ number_format((float) $linha->valor_total, 2, ',', ' ') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="mt-4 flex justify-end">
            <div class="w-48 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span>{{ number_format((float) $nota->valor_sem_iva, 2, ',', ' ') }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">IVA</span><span>{{ number_format((float) $nota->valor_iva, 2, ',', ' ') }}</span></div>
                <div class="flex justify-between border-t border-slate-200 pt-1 font-semibold"><span>Total</span><span>{{ number_format((float) $nota->valor_total, 2, ',', ' ') }} {{ $nota->moeda }}</span></div>
            </div>
        </div>

        @if (config('faturacao.agt.ativo') && $nota->estaEmitido() && $nota->urlQrAgt())
            @php
                $qrImagem = \Modules\Faturacao\Support\QrCodeAgt::dataUri($nota->urlQrAgt());
            @endphp
            <div class="mt-6 flex items-center gap-4 border-t border-slate-200 pt-4">
                @if ($qrImagem)
                    <img src="{{ $qrImagem }}" alt="QR Code da AGT" width="175" height="175">
                @endif
                <p class="text-xs text-slate-500">Consulte este documento no portal da AGT: {{ $nota->urlQrAgt() }}</p>
            </div>
        @endif

        @if ($nota->estado === 'rascunho')
            <form method="POST" action="{{ route('faturacao.notas.emitir', $nota->id) }}" class="mt-6">
                @csrf
                <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Emitir nota
                </button>
                <p class="mt-2 text-center text-xs text-slate-500">Depois de emitida, esta nota não pode ser alterada nem apagada.</p>
            </form>

            <div class="mt-4 flex items-center justify-end border-t border-slate-200 pt-4 text-sm">
                <form method="POST" action="{{ route('faturacao.notas.eliminar', $nota->id) }}"
                      onsubmit="return confirm('Eliminar este rascunho? Esta ação não pode ser desfeita.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="font-medium text-red-600 hover:text-red-500">Eliminar rascunho</button>
                </form>
            </div>
        @else
            <div class="mt-6 rounded-lg bg-slate-50 border border-slate-200 p-4 font-mono text-xs text-slate-500">
                <p>Data/hora do sistema: {{ $nota->data_hora_sistema->format('Y-m-d H:i:s') }}</p>
                <p class="mt-1 break-all">Hash: {{ Str::limit($nota->hash, 60) }}</p>
                <p class="mt-1">Versão da chave: {{ $nota->chave_versao }}</p>
            </div>

            @include('faturacao::partials.estado-agt', ['documento' => $nota])
        @endif

        <p class="mt-6 text-sm"><a href="{{ route('faturacao.notas.index') }}" class="font-medium text-indigo-600 hover:text-indigo-500">← Notas de crédito e débito</a></p>
    </div>
@endsection
