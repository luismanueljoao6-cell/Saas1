@extends('core::layouts.app')

@section('titulo', 'Dashboard')

@section('conteudo')
    @php
        $utilizador = auth()->user();
        $empresa = $utilizador->empresa;
        $servicos = $empresa ? $empresa->servicosAderidos() : collect();

        // Super admin / conta sem empresa vê tudo; os restantes só os serviços aderidos.
        $tem = fn (string $servico) => ! $empresa || $empresa->temServico($servico);

        /*
         * ATENÇÃO — DADOS DE DEMONSTRAÇÃO.
         * Os valores abaixo servem para ver o desenho final. Para ligar a dados reais, passa
         * $kpis, $faturasRecentes e $pedidosRecentes a partir de um controller (a rota atual
         * é Route::view, por isso trocá-la por uma rota com controller) e apaga estes defaults.
         */
        $kpis = $kpis ?? [
            'receita' => ['rotulo' => 'Receita do mês', 'valor' => '1 250 000 Kz', 'icone' => 'dinheiro', 'variacao' => '+12,4%', 'tendencia' => 'alta', 'nota' => 'vs. mês anterior'],
            'pedidos' => ['rotulo' => 'Pedidos em curso', 'valor' => '23', 'icone' => 'pedidos', 'variacao' => '+3', 'tendencia' => 'alta', 'nota' => 'esta semana'],
            'sessoes' => ['rotulo' => 'Sessões agendadas', 'valor' => '14', 'icone' => 'calendario', 'variacao' => '-2', 'tendencia' => 'baixa', 'nota' => 'próximos 7 dias'],
            'plano' => ['rotulo' => 'Utilização do plano', 'valor' => '38 / 50', 'icone' => 'medidor', 'progresso' => 76, 'limite' => '38 de 50 utilizadores'],
        ];

        $faturasRecentes = $faturasRecentes ?? [
            ['id' => 1, 'numero' => 'FT 2026/0142', 'cliente' => 'Hotel Baía Azul, Lda', 'nif' => '5417012345', 'emissao' => '2026-10-09', 'vencimento' => '2026-11-08', 'total' => 1250000, 'estado' => 'Paga'],
            ['id' => 2, 'numero' => 'FT 2026/0141', 'cliente' => 'Mercado Central, SA', 'nif' => '5000123456', 'emissao' => '2026-10-08', 'vencimento' => '2026-11-07', 'total' => 480500, 'estado' => 'Pendente'],
            ['id' => 3, 'numero' => 'FT 2026/0140', 'cliente' => 'Escola Nova Geração', 'nif' => '5401987654', 'emissao' => '2026-10-07', 'vencimento' => '2026-11-06', 'total' => 215000, 'estado' => 'Paga'],
            ['id' => 4, 'numero' => 'FT 2026/0139', 'cliente' => 'Transportes Kwanza', 'nif' => '5417765432', 'emissao' => '2026-10-05', 'vencimento' => '2026-11-04', 'total' => 960000, 'estado' => 'Cancelada'],
            ['id' => 5, 'numero' => 'FT 2026/0138', 'cliente' => 'Clínica Vida Plena', 'nif' => '5400456789', 'emissao' => '2026-10-04', 'vencimento' => '2026-11-03', 'total' => 330000, 'estado' => 'Pendente'],
            ['id' => 6, 'numero' => 'FT 2026/0137', 'cliente' => 'Padaria Sol Nascente', 'nif' => '5417234567', 'emissao' => '2026-10-02', 'vencimento' => '2026-11-01', 'total' => 78500, 'estado' => 'Paga'],
        ];

        $pedidosRecentes = $pedidosRecentes ?? [
            ['id' => 1, 'numero' => 'PED-0087', 'cliente' => 'Maria Fernandes', 'peca' => 'Vestido de noiva', 'entrega' => '2026-10-30', 'total' => 185000, 'estado' => 'Em corte'],
            ['id' => 2, 'numero' => 'PED-0086', 'cliente' => 'João Baptista', 'peca' => 'Fato completo', 'entrega' => '2026-10-22', 'total' => 142000, 'estado' => 'Em prova'],
            ['id' => 3, 'numero' => 'PED-0085', 'cliente' => 'Ana Paula Silva', 'peca' => 'Kimono tradicional', 'entrega' => '2026-10-15', 'total' => 64000, 'estado' => 'Concluído'],
            ['id' => 4, 'numero' => 'PED-0084', 'cliente' => 'Carlos Mendes', 'peca' => 'Camisa por medida', 'entrega' => '2026-10-18', 'total' => 38500, 'estado' => 'Em costura'],
            ['id' => 5, 'numero' => 'PED-0083', 'cliente' => 'Luzia Gomes', 'peca' => 'Conjunto de cerimónia', 'entrega' => '2026-11-05', 'total' => 210000, 'estado' => 'Pendente'],
        ];
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

    {{-- Tabelas de exemplo (substituir pelos dados reais — ver comentário no topo) --}}
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
                'estados' => ['Paga', 'Pendente', 'Cancelada'],
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
                'estados' => ['Pendente', 'Em corte', 'Em costura', 'Em prova', 'Concluído'],
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
