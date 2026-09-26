@extends('core::layouts.app')

@section('titulo', 'Faturas')

@section('conteudo')
    <div class="w-full max-w-3xl">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Faturas</h1>
            <a href="{{ route('faturacao.faturas.criar') }}"
               class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                Nova fatura
            </a>
        </div>

        @if (session('erro'))
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ session('erro') }}</div>
        @endif

        <div class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
            @forelse ($faturas as $fatura)
                <a href="{{ route('faturacao.faturas.mostrar', $fatura) }}" class="flex items-center justify-between px-4 py-3 hover:bg-slate-50">
                    <div>
                        <p class="text-sm font-medium text-slate-900">
                            {{ $fatura->numero_documento ?? 'Rascunho #'.$fatura->id }}
                        </p>
                        <p class="text-xs text-slate-500">{{ $fatura->cliente->nome }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-medium text-slate-900">{{ number_format((float) $fatura->valor_total, 2, ',', ' ') }} {{ $fatura->moeda }}</p>
                        <span class="text-xs {{ $fatura->estado === 'emitida' ? 'text-emerald-600' : 'text-amber-600' }}">
                            {{ ucfirst($fatura->estado) }}
                        </span>
                    </div>
                </a>
            @empty
                <p class="px-4 py-6 text-center text-sm text-slate-500">Ainda não tens faturas.</p>
            @endforelse
        </div>

        {{ $faturas->links() }}
    </div>
@endsection
