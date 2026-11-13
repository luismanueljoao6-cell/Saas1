@extends('core::layouts.app')

@section('titulo', 'Dashboard')

@section('conteudo')
    @php
        $utilizador = auth()->user();
        $empresa = $utilizador->empresa;
        $servicos = $empresa ? $empresa->servicosAderidos() : collect();

        // Super admin / conta sem empresa vê tudo; os restantes só os serviços aderidos.
        $tem = fn (string $servico) => ! $empresa || $empresa->temServico($servico);

        // Os indicadores são fornecidos por um controller quando ligados a dados reais.
        // Nunca apresentar valores fictícios como se fossem métricas da empresa.
        $kpis = $kpis ?? [
            'receita' => ['rotulo' => 'Receita do mês', 'valor' => '—', 'icone' => 'dinheiro', 'nota' => 'A ligar a dados reais'],
            'pedidos' => ['rotulo' => 'Pedidos em curso', 'valor' => '—', 'icone' => 'pedidos', 'nota' => 'A ligar a dados reais'],
            'sessoes' => ['rotulo' => 'Sessões agendadas', 'valor' => '—', 'icone' => 'calendario', 'nota' => 'A ligar a dados reais'],
            'plano' => ['rotulo' => 'Utilização do plano', 'valor' => '—', 'icone' => 'medidor', 'nota' => 'Dados indisponíveis'],
        ];

        $faturasRecentes = $faturasRecentes ?? collect();
        $pedidosRecentes = $pedidosRecentes ?? collect();
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight text-slate-900 dark:text-zinc-50">
                Olá, {{ \Illuminate\Support\Str::before($utilizador->name, ' ') }}
            </h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">
                @if (! $empresa)
                    Conta de administração da plataforma (sem empresa associada).
                @elseif ($empresa->estado_subscricao === 'trial')
                    {{ $empresa->nome_comercial }} está em período experimental.
                @else
                    {{ $empresa->nome_comercial }} · subscrição {{ $empresa->estado_subscricao }}.
                @endif
            </p>
        </div>

        @if ($tem('faturacao') && Route::has('faturacao.faturas.criar'))
            <a href="{{ route('faturacao.faturas.criar') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500">
                @include('core::partials.icone', ['nome' => 'mais', 'classe' => 'size-4'])
                Nova fatura
            </a>
        @endif
    </div>

    {{-- Indicadores --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @if ($tem('faturacao'))
            @include('core::partials.kpi-card', ['kpi' => $kpis['receita']])
        @endif
        @if ($tem('atelier'))
            @include('core::partials.kpi-card', ['kpi' => $kpis['pedidos']])
        @endif
        @if ($tem('estudio'))
            @include('core::partials.kpi-card', ['kpi' => $kpis['sessoes']])
        @endif
        @include('core::partials.kpi-card', ['kpi' => $kpis['plano']])
    </div>

    {{-- Serviços ativos --}}
    @if ($empresa)
        <div class="mt-6">
            @if ($servicos->isEmpty())
                <div class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-zinc-700 dark:text-zinc-400">
                    Esta empresa ainda não tem serviços ativos.
                    @if (Route::has('core.servicos.index'))
                        <a href="{{ route('core.servicos.index') }}" class="font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">Escolher serviços</a>
                    @endif
                </div>
            @else
                <h2 class="mb-3 text-sm font-semibold text-slate-900 dark:text-zinc-100">Os teus serviços</h2>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($servicos as $servico)
                        @if (Route::has($servico->rotaInicial()))
                            <a href="{{ route($servico->rotaInicial()) }}"
                               class="block rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-indigo-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-indigo-500/50">
                                <span class="block text-sm font-semibold text-slate-900 dark:text-zinc-100">{{ $servico->rotulo() }}</span>
                                <span class="mt-1 block text-xs text-slate-500 dark:text-zinc-400">{{ $servico->descricao() }}</span>
                            </a>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- As listas só apresentam dados reais fornecidos pelo controller. --}}
    @if ($tem('faturacao'))
        <div class="mt-6">
            @include('core::partials.data-table', ['tabela' => [
                'titulo' => 'Faturas recentes',
                'colunas' => [
                    ['chave' => 'numero', 'rotulo' => 'Número', 'forte' => true],
                    ['chave' => 'cliente', 'rotulo' => 'Cliente'],
                    ['chave' => 'emissao', 'rotulo' => 'Emissão', 'tipo' => 'data'],
                    ['chave' => 'total', 'rotulo' => 'Total', 'tipo' => 'moeda', 'alinhar' => 'direita'],
                    ['chave' => 'estado', 'rotulo' => 'Estado', 'tipo' => 'badge'],
                ],
                'linhas' => $faturasRecentes,
                'filtroEstado' => 'estado',
                'estados' => ['Emitida', 'Rascunho', 'Anulada'],
                'tituloGaveta' => 'numero',
                'detalhes' => [
                    ['chave' => 'cliente', 'rotulo' => 'Cliente'],
                    ['chave' => 'nif', 'rotulo' => 'NIF'],
                    ['chave' => 'emissao', 'rotulo' => 'Emissão', 'tipo' => 'data'],
                    ['chave' => 'vencimento', 'rotulo' => 'Vencimento', 'tipo' => 'data'],
                    ['chave' => 'total', 'rotulo' => 'Total', 'tipo' => 'moeda'],
                    ['chave' => 'estado', 'rotulo' => 'Estado', 'tipo' => 'badge'],
                ],
                'acoes' => [
                    ['rotulo' => 'Abrir fatura', 'url' => url('/faturacao/faturas/:id')],
                    ['rotulo' => 'Emitir fatura', 'url' => url('/faturacao/faturas/:id/emitir'), 'metodo' => 'POST', 'confirmar' => 'Emitir esta fatura? Depois de emitida, já não pode ser alterada.'],
                ],
                'porPagina' => 5,
                'vazio' => 'Ainda não emitiste nenhuma fatura.',
            ]])
        </div>
    @endif

    @if ($tem('atelier'))
        <div class="mt-6">
            @include('core::partials.data-table', ['tabela' => [
                'titulo' => 'Pedidos do atelier',
                'colunas' => [
                    ['chave' => 'numero', 'rotulo' => 'Pedido', 'forte' => true],
                    ['chave' => 'cliente', 'rotulo' => 'Cliente'],
                    ['chave' => 'peca', 'rotulo' => 'Peça'],
                    ['chave' => 'entrega', 'rotulo' => 'Entrega', 'tipo' => 'data'],
                    ['chave' => 'total', 'rotulo' => 'Total', 'tipo' => 'moeda', 'alinhar' => 'direita'],
                    ['chave' => 'estado', 'rotulo' => 'Estado', 'tipo' => 'badge'],
                ],
                'linhas' => $pedidosRecentes,
                'filtroEstado' => 'estado',
                'estados' => ['Pendente', 'Em Corte', 'Em Costura', 'Primeira Prova', 'Ajustes', 'Pronto para Retirada'],
                'tituloGaveta' => 'numero',
                'acoes' => [
                    ['rotulo' => 'Abrir pedido', 'url' => url('/atelier/pedidos/:id')],
                ],
                'porPagina' => 5,
                'vazio' => 'Ainda não há pedidos no atelier.',
            ]])
        </div>
    @endif
@endsection
