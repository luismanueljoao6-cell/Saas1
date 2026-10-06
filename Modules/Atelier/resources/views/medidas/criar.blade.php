@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Nova ficha de medidas')

@section('conteudo')
    <h1 class="mb-1 text-lg font-semibold text-slate-900">Nova ficha de medidas — {{ $cliente->nome }}</h1>
    <p class="mb-6 text-sm text-slate-500">
        Cria sempre um novo registo — não substitui o anterior, para manteres o histórico completo.
        @if ($ultimaMedida)
            Os campos abaixo vêm pré-preenchidos com a última ficha ({{ $ultimaMedida->created_at->format('d/m/Y') }}).
        @endif
    </p>

    <form method="POST" action="{{ route('atelier.medidas.guardar') }}" class="space-y-6 rounded-md border border-slate-200 bg-white p-6">
        @csrf
        <input type="hidden" name="cliente_id" value="{{ $cliente->id }}">

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
            @foreach ($camposMedida as $campo)
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ ucfirst(str_replace('_', ' ', $campo)) }} (cm)</label>
                    <input type="number" step="0.01" name="{{ $campo }}"
                           value="{{ old($campo, $ultimaMedida->{$campo} ?? '') }}"
                           class="w-full rounded-md border-slate-300 text-sm">
                </div>
            @endforeach
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Observações</label>
            <textarea name="observacoes" rows="3" class="w-full rounded-md border-slate-300 text-sm">{{ old('observacoes') }}</textarea>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('atelier.medidas.historico', $cliente) }}" class="text-sm text-slate-500">Cancelar</a>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Guardar ficha
            </button>
        </div>
    </form>
@endsection
