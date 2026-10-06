@extends('atelier::layouts.publico')

@section('titulo', 'O meu pedido')

@section('conteudo')
    <div class="mb-8">
        <h1 class="text-xl font-semibold text-slate-900">Olá, {{ $cliente->nome }}</h1>
        <p class="text-sm text-slate-500">Aqui podes acompanhar o estado das tuas peças e as próximas provas.</p>
    </div>

    @php $ativos = $pedidos->reject(fn ($p) => in_array($p->status, ['entregue', 'cancelado'], true)); @endphp
    @php $historico = $pedidos->filter(fn ($p) => in_array($p->status, ['entregue', 'cancelado'], true)); @endphp
    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Em curso</h2>

    @forelse ($ativos as $pedido)
        @php $indiceAtual = array_search($pedido->status, $fluxoEstados, true); @endphp
        <div class="mb-6 rounded-md border border-slate-200 bg-white p-5">
            <div class="mb-1 flex items-center justify-between">
                <p class="font-medium text-slate-800">{{ $pedido->tipoServicoRotulo() }}</p>
                @include('atelier::partials.estado-badge', ['estado' => $pedido->status, 'rotulo' => $pedido->statusRotulo()])
            </div>
            <p class="mb-4 text-sm text-slate-600">{{ $pedido->descricao }}</p>

            {{-- Linha do tempo visual (requisito E.2) --}}
            <ol class="mb-4 flex flex-wrap items-center gap-y-2 text-xs">
                @foreach ($fluxoEstados as $indice => $estado)
                    @php $concluido = $indiceAtual !== false && $indice <= $indiceAtual; @endphp
                    <li class="flex items-center">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-semibold
                            {{ $concluido ? 'bg-indigo-600 text-white' : 'bg-slate-200 text-slate-500' }}">
                            {{ $indice + 1 }}
                        </span>
                        <span class="ml-1 mr-3 {{ $concluido ? 'font-medium text-slate-800' : 'text-slate-400' }}">
                            {{ \Modules\Atelier\Models\Pedido::rotuloParaStatus($estado) }}
                        </span>
                    </li>
                @endforeach
            </ol>

            @if ($pedido->data_prevista_entrega)
                <p class="mb-3 text-xs text-slate-500">Entrega prevista: {{ $pedido->data_prevista_entrega->format('d/m/Y') }}</p>
            @endif

            {{-- Provas + reagendamento (requisito E.4) --}}
            @php $provasFuturas = $pedido->provas->filter(fn ($p) => $p->estado !== 'concluida'); @endphp
            @if ($provasFuturas->isNotEmpty())
                <div class="space-y-2 border-t border-slate-100 pt-3">
                    @foreach ($provasFuturas as $prova)
                        <div class="flex items-center justify-between text-sm">
                            <div>
                                <span class="font-medium text-slate-700">{{ $prova->tipoRotulo() }}</span>
                                <span class="text-slate-500"> — {{ $prova->data_hora_agendada->format('d/m/Y \à\s H:i') }}</span>
                                @if ($prova->estado === 'reagendada' && $prova->data_hora_proposta_cliente)
                                    <p class="text-xs text-amber-600">
                                        Pediste {{ $prova->data_hora_proposta_cliente->format('d/m/Y \à\s H:i') }} — a aguardar confirmação.
                                    </p>
                                @endif
                            </div>
                            <a href="{{ $linkService->gerarLinkReagendamento($cliente, $prova->id) }}" class="text-xs font-medium text-indigo-600">
                                Pedir outro horário
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <p class="mb-6 rounded-md border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
            Não tens nenhuma peça em curso neste momento.
        </p>
    @endforelse

    {{-- Medidas atuais (requisito E.3) --}}
    @if ($medidaAtual)
        <h2 class="mb-3 mt-8 text-sm font-semibold uppercase tracking-wide text-slate-500">As tuas medidas atuais</h2>
        <div class="mb-6 rounded-md border border-slate-200 bg-white p-5">
            <p class="mb-3 text-xs text-slate-400">Registadas em {{ $medidaAtual->created_at->format('d/m/Y') }}</p>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-3">
                @foreach (\Modules\Atelier\Models\Medida::camposMedida() as $campo)
                    @if ($medidaAtual->{$campo} !== null)
                        <div>
                            <dt class="text-xs text-slate-400">{{ ucfirst(str_replace('_', ' ', $campo)) }}</dt>
                            <dd class="text-sm font-medium text-slate-800">{{ $medidaAtual->{$campo} }} cm</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        </div>
    @endif

    {{-- Histórico de peças (requisito E.3) --}}
    @if ($historico->isNotEmpty())
        <h2 class="mb-3 mt-8 text-sm font-semibold uppercase tracking-wide text-slate-500">Histórico de peças</h2>
        <div class="space-y-2">
            @foreach ($historico as $pedido)
                <div class="flex items-center justify-between rounded-md border border-slate-200 bg-white px-4 py-3 text-sm">
                    <div>
                        <p class="font-medium text-slate-700">{{ $pedido->tipoServicoRotulo() }}</p>
                        <p class="text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($pedido->descricao, 70) }}</p>
                    </div>
                    @include('atelier::partials.estado-badge', ['estado' => $pedido->status, 'rotulo' => $pedido->statusRotulo()])
                </div>
            @endforeach
        </div>
    @endif
@endsection
