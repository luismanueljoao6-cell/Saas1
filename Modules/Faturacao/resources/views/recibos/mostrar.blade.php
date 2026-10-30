@extends('core::layouts.app')

@section('titulo', $recibo->numero_documento ?? 'Recibo')

@section('conteudo')
    <div class="w-full max-w-xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">
                    {{ $recibo->numero_documento ?? 'Recibo #'.$recibo->id }}
                    <span class="ml-1 text-sm font-normal text-slate-500">Recibo</span>
                </h1>
                <p class="text-sm text-slate-500">
                    @php
                        $clienteNome = $recibo->cliente_nome ?? $recibo->cliente->nome;
                        $clienteNif = $recibo->cliente_nome !== null ? $recibo->cliente_nif : $recibo->cliente->nif;
                    @endphp
                    {{ $clienteNome }} @if($clienteNif) — NIF {{ $clienteNif }} @endif
                </p>
                @if ($recibo->fatura)
                    <p class="mt-1 text-sm text-slate-500">
                        Fatura:
                        <a href="{{ route('faturacao.faturas.mostrar', $recibo->fatura->id) }}" class="font-medium text-indigo-600 hover:text-indigo-500">{{ $recibo->fatura->numero_documento }}</a>
                    </p>
                @endif
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-medium {{ $recibo->estaEmitido() ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                {{ ucfirst($recibo->estado) }}
            </span>
        </div>

        @if (session('sucesso'))
            <div class="mt-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
        @endif

        <div class="mt-6 rounded-md bg-slate-50 border border-slate-200 p-4 text-sm">
            <div class="flex justify-between"><span class="text-slate-500">Valor recebido</span><span class="font-semibold">{{ number_format((float) $recibo->valor, 2, ',', ' ') }} {{ $recibo->moeda }}</span></div>
            <div class="mt-1 flex justify-between"><span class="text-slate-500">Meio de pagamento</span><span>{{ config('faturacao.meios_pagamento.'.$recibo->meio_pagamento, $recibo->meio_pagamento) }}</span></div>
        </div>

        @if (config('faturacao.agt.ativo') && $recibo->estaEmitido() && $recibo->urlQrAgt())
            @php
                $qrImagem = \Modules\Faturacao\Support\QrCodeAgt::dataUri($recibo->urlQrAgt());
            @endphp
            <div class="mt-6 flex items-center gap-4 border-t border-slate-200 pt-4">
                @if ($qrImagem)
                    <img src="{{ $qrImagem }}" alt="QR Code da AGT" width="175" height="175">
                @endif
                <p class="text-xs text-slate-500">Consulte este documento no portal da AGT: {{ $recibo->urlQrAgt() }}</p>
            </div>
        @endif

        @if ($recibo->estaEmitido())
            <div class="mt-6 rounded-lg bg-slate-50 border border-slate-200 p-4 font-mono text-xs text-slate-500">
                <p>Data/hora do sistema: {{ $recibo->data_hora_sistema->format('Y-m-d H:i:s') }}</p>
                <p class="mt-1 break-all">Hash: {{ Str::limit($recibo->hash, 60) }}</p>
                <p class="mt-1">Versão da chave: {{ $recibo->chave_versao }}</p>
            </div>

            @include('faturacao::partials.estado-agt', ['documento' => $recibo])
        @endif

        <p class="mt-6 text-sm"><a href="{{ route('faturacao.recibos.index') }}" class="font-medium text-indigo-600 hover:text-indigo-500">← Recibos</a></p>
    </div>
@endsection
