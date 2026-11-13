@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Pedidos — Atelier')

@section('conteudo')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900 dark:text-zinc-100">Pedidos de Costura</h1>
        <a href="{{ route('atelier.pedidos.criar') }}"
           class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            + Novo pedido
        </a>
    </div>

    <div class="overflow-x-auto rounded-md border border-slate-200 bg-white dark:bg-zinc-900 dark:border-zinc-800">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-zinc-950 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3">Cliente</th>
                    <th class="px-4 py-3">Serviço</th>
                    <th class="px-4 py-3">Estado</th>
                    <th class="px-4 py-3">Responsável</th>
                    <th class="px-4 py-3">Entrega prevista</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                @forelse ($pedidos as $pedido)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('atelier.pedidos.mostrar', $pedido) }}" class="font-medium text-indigo-600">
                                {{ $pedido->cliente?->nome ?? '—' }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-slate-600 dark:text-zinc-300">{{ $pedido->tipoServicoRotulo() }}</td>
                        <td class="px-4 py-3">
                            @include('atelier::partials.estado-badge', ['estado' => $pedido->status, 'rotulo' => $pedido->statusRotulo()])
                        </td>
                        <td class="px-4 py-3 text-slate-600 dark:text-zinc-300">{{ $pedido->responsavel?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600 dark:text-zinc-300">
                            {{ $pedido->data_prevista_entrega?->format('d/m/Y') ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-slate-500 dark:text-zinc-400">Ainda não há nenhum pedido.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $pedidos->links() }}</div>
@endsection
