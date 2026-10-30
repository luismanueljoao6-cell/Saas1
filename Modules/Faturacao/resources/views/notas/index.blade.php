@extends('core::layouts.app')

@section('titulo', 'Notas de crédito e débito')

@section('conteudo')
    <div class="w-full max-w-3xl">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Notas de crédito e débito</h1>
            <a href="{{ route('faturacao.notas.criar', ['tipo' => 'debito']) }}"
               class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                Nova nota de débito
            </a>
        </div>
        <p class="mt-1 text-sm text-slate-500">Uma nota de crédito parte sempre de uma fatura emitida: abre a fatura e escolhe «Nota de crédito».</p>

        @if (session('erro'))
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ session('erro') }}</div>
        @endif
        @if (session('sucesso'))
            <div class="mt-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
        @endif

        <div class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
            @forelse ($notas as $nota)
                <a href="{{ route('faturacao.notas.mostrar', $nota->id) }}" class="flex items-center justify-between px-4 py-3 hover:bg-slate-50">
                    <div>
                        <p class="text-sm font-medium text-slate-900">
                            {{ $nota->numero_documento ?? 'Rascunho #'.$nota->id }}
                            <span class="ml-1 text-xs font-normal text-slate-500">{{ $nota->tipo === 'credito' ? 'Crédito' : 'Débito' }}</span>
                        </p>
                        <p class="text-xs text-slate-500">{{ $nota->cliente->nome }} — {{ $nota->motivo }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-medium text-slate-900">{{ number_format((float) $nota->valor_total, 2, ',', ' ') }} {{ $nota->moeda }}</p>
                        <span class="text-xs {{ $nota->estado === 'emitida' ? 'text-emerald-600' : 'text-amber-600' }}">{{ ucfirst($nota->estado) }}</span>
                        @if (config('faturacao.agt.ativo') && $nota->estado === 'emitida')
                            <span class="block text-xs text-slate-400">AGT: {{ $nota->agtSubmissao?->rotulo() ?? 'sem registo de envio' }}</span>
                        @endif
                    </div>
                </a>
            @empty
                <p class="px-4 py-6 text-center text-sm text-slate-500">Ainda não tens notas de crédito ou débito.</p>
            @endforelse
        </div>

        {{ $notas->links() }}
    </div>
@endsection
