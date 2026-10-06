@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Pedidos de Orçamento')

@section('conteudo')
    <h1 class="mb-1 text-lg font-semibold text-slate-900">Pedidos de Orçamento</h1>
    <p class="mb-6 text-sm text-slate-500">Leads recebidos através do formulário da página pública.</p>

    <div class="space-y-3">
        @forelse ($orcamentos as $orcamento)
            <div class="rounded-md border border-slate-200 bg-white p-4">
                <div class="mb-2 flex items-center justify-between">
                    <div>
                        <p class="font-medium text-slate-800">{{ $orcamento->nome }}</p>
                        <p class="text-xs text-slate-500">{{ $orcamento->contacto }} @if($orcamento->categoria_interesse) · {{ $orcamento->categoria_interesse }} @endif</p>
                    </div>
                    <form method="POST" action="{{ route('atelier.orcamentos.atualizar-estado', $orcamento) }}">
                        @csrf @method('PUT')
                        <select name="estado" onchange="this.form.submit()" class="rounded-md border-slate-300 text-xs">
                            <option value="novo" @selected($orcamento->estado === 'novo')>Novo</option>
                            <option value="contactado" @selected($orcamento->estado === 'contactado')>Contactado</option>
                            <option value="convertido" @selected($orcamento->estado === 'convertido')>Convertido</option>
                            <option value="descartado" @selected($orcamento->estado === 'descartado')>Descartado</option>
                        </select>
                    </form>
                </div>
                @if ($orcamento->mensagem)
                    <p class="text-sm text-slate-600">{{ $orcamento->mensagem }}</p>
                @endif
                <p class="mt-2 text-xs text-slate-400">{{ $orcamento->created_at->format('d/m/Y H:i') }}</p>
            </div>
        @empty
            <p class="rounded-md border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                Ainda não há nenhum pedido de orçamento.
            </p>
        @endforelse
    </div>

    <div class="mt-4">{{ $orcamentos->links() }}</div>
@endsection
