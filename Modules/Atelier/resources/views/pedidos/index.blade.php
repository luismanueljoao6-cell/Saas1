@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Pedidos — Atelier')

@section('conteudo')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900">Pedidos de Costura</h1>
        <a href="{{ route('atelier.pedidos.criar') }}"
           class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            + Novo pedido
        </a>
    </div>

    <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">Cliente</th>
                    <th class="px-4 py-3">Serviço</th>
                    <th class="px-4 py-3">Estado</th>
                    <th class="px-4 py-3">Responsável</th>
                    <th class="px-4 py-3">Entrega prevista</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($pedidos as $pedido)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('atelier.pedidos.mostrar', $pedido) }}" class="font-medium text-indigo-600">
                                {{ $pedido->cliente?->nome ?? '—' }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $pedido->tipoServicoRotulo() }}</td>
                        <td class="px-4 py-3">
                            @include('atelier::partials.estado-badge', ['estado' => $pedido->status, 'rotulo' => $pedido->statusRotulo()])
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $pedido->responsavel?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ $pedido->data_prevista_entrega?->format('d/m/Y') ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-slate-500">Ainda não há nenhum pedido.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $pedidos->links() }}</div>
@endsection
