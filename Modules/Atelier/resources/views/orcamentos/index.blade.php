@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Pedidos de Orçamento')

@section('conteudo')
    <h1 class="mb-1 text-lg font-semibold text-slate-900 dark:text-zinc-100">Pedidos de Orçamento</h1>
    <p class="mb-6 text-sm text-slate-500 dark:text-zinc-400">Leads recebidos através do formulário da página pública.</p>

    <div class="space-y-3">
        @forelse ($orcamentos as $orcamento)
            <div class="rounded-md border border-slate-200 bg-white p-4 dark:bg-zinc-900 dark:border-zinc-800">
                <div class="mb-2 flex items-center justify-between">
                    <div>
                        <p class="font-medium text-slate-800 dark:text-zinc-100">{{ $orcamento->nome }}</p>
                        <p class="text-xs text-slate-500 dark:text-zinc-400">{{ $orcamento->contacto }} @if($orcamento->categoria_interesse) · {{ $orcamento->categoria_interesse }} @endif</p>
                    </div>
                    <form method="POST" action="{{ route('atelier.orcamentos.atualizar-estado', $orcamento) }}">
                        @csrf @method('PUT')
                        <select name="estado" onchange="this.form.submit()" class="rounded-md border-slate-300 text-xs dark:border-zinc-700">
                            <option value="novo" @selected($orcamento->estado === 'novo')>Novo</option>
                            <option value="contactado" @selected($orcamento->estado === 'contactado')>Contactado</option>
                            <option value="convertido" @selected($orcamento->estado === 'convertido')>Convertido</option>
                            <option value="descartado" @selected($orcamento->estado === 'descartado')>Descartado</option>
                        </select>
                    </form>
                </div>
                @if ($orcamento->mensagem)
                    <p class="text-sm text-slate-600 dark:text-zinc-300">{{ $orcamento->mensagem }}</p>
                @endif
                <p class="mt-2 text-xs text-slate-400">{{ $orcamento->created_at->format('d/m/Y H:i') }}</p>
            </div>
        @empty
            <p class="rounded-md border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-zinc-700 dark:text-zinc-400">
                Ainda não há nenhum pedido de orçamento.
            </p>
        @endforelse
    </div>

    <div class="mt-4">{{ $orcamentos->links() }}</div>
@endsection
