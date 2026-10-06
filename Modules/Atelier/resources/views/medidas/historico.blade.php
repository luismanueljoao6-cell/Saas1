@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Medidas de ' . $cliente->nome)

@section('conteudo')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold text-slate-900">Medidas — {{ $cliente->nome }}</h1>
            <p class="text-sm text-slate-500">Histórico de todas as fichas registadas, da mais recente para a mais antiga.</p>
        </div>
        <a href="{{ route('atelier.medidas.criar', $cliente) }}"
           class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            + Nova ficha de medidas
        </a>
    </div>

    @if ($medidas->isEmpty())
        <p class="rounded-md border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
            Ainda não há nenhuma ficha de medidas para este cliente.
        </p>
    @else
        <div class="space-y-4">
            @foreach ($medidas as $medida)
                <div class="rounded-md border border-slate-200 bg-white p-4">
                    <div class="mb-3 flex items-center justify-between text-xs text-slate-500">
                        <span>{{ $medida->created_at->format('d/m/Y \à\s H:i') }}</span>
                        @if ($medida->registadoPor)
                            <span>Registado por {{ $medida->registadoPor->name }}</span>
                        @endif
                    </div>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-4">
                        @foreach ($camposMedida as $campo)
                            @if ($medida->{$campo} !== null)
                                <div>
                                    <dt class="text-xs text-slate-400">{{ ucfirst(str_replace('_', ' ', $campo)) }}</dt>
                                    <dd class="text-sm font-medium text-slate-800">{{ $medida->{$campo} }} cm</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                    @if ($medida->observacoes)
                        <p class="mt-3 text-sm text-slate-600">{{ $medida->observacoes }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
@endsection
