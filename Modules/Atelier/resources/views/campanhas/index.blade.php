@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Campanhas')

@section('conteudo')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900 dark:text-zinc-100">Campanhas de Marketing</h1>
        <a href="{{ route('atelier.campanhas.criar') }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            + Nova campanha
        </a>
    </div>

    <div class="overflow-x-auto rounded-md border border-slate-200 bg-white dark:bg-zinc-900 dark:border-zinc-800">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-zinc-950 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3">Nome</th>
                    <th class="px-4 py-3">Canal</th>
                    <th class="px-4 py-3">Segmento</th>
                    <th class="px-4 py-3">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                @forelse ($campanhas as $campanha)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('atelier.campanhas.mostrar', $campanha) }}" class="font-medium text-indigo-600">{{ $campanha->nome }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-600 dark:text-zinc-300">{{ strtoupper($campanha->canal) }}</td>
                        <td class="px-4 py-3 text-slate-600 dark:text-zinc-300">{{ $campanha->segmentoRotulo() }}</td>
                        <td class="px-4 py-3 text-slate-600 dark:text-zinc-300">{{ ucfirst($campanha->estado) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500 dark:text-zinc-400">Ainda não há nenhuma campanha.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $campanhas->links() }}</div>
@endsection
