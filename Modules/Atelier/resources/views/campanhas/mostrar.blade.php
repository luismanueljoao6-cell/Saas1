@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', $campanha->nome)

@section('conteudo')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold text-slate-900">{{ $campanha->nome }}</h1>
            <p class="text-sm text-slate-500">{{ strtoupper($campanha->canal) }} · {{ $campanha->segmentoRotulo() }} · {{ ucfirst($campanha->estado) }}</p>
        </div>
        @if ($campanha->estado !== 'enviada')
            <form method="POST" action="{{ route('atelier.campanhas.enviar-agora', $campanha) }}" onsubmit="return confirm('Enviar esta campanha agora?')">
                @csrf
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    Enviar agora
                </button>
            </form>
        @endif
    </div>

    <div class="mb-6 rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-700">
        {{ $campanha->mensagem }}
    </div>

    @if ($campanha->envios->isEmpty())
        <p class="text-sm text-slate-500">Ainda não há envios registados — clica em "Enviar agora" para processar esta campanha.</p>
    @else
        <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Cliente</th>
                        <th class="px-4 py-3">Estado</th>
                        <th class="px-4 py-3">Detalhe</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($campanha->envios as $envio)
                        <tr>
                            <td class="px-4 py-3">{{ $envio->cliente?->nome ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $envio->estado === 'enviado' ? 'bg-emerald-50 text-emerald-700' : ($envio->estado === 'falhou' ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600') }}">
                                    {{ ucfirst($envio->estado) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ $envio->erro }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
