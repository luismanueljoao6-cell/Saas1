@extends('estudiomusica::layouts.publico')

@section('titulo', 'Política de Privacidade — '.$empresa->nome_comercial)

@section('conteudo')
@php
    $responsavel = $empresa->nome_legal ?: $empresa->nome_comercial;
    $mesesPedidos = (int) config('estudiomusica.retencao_solicitacoes_meses', 12);
    $mesesReclamacoes = (int) config('estudiomusica.retencao_reclamacoes_meses', 24);
    $autorizacaoApd = config('estudiomusica.apd_autorizacao_transferencia');
    $registoApd = config('estudiomusica.apd_registo');
@endphp
<main class="mx-auto max-w-3xl space-y-6 px-4 py-10 text-sm leading-relaxed text-slate-700">
    <header>
        <h1 class="text-xl font-semibold text-slate-900">Política de Privacidade</h1>
        <p class="text-slate-500">{{ $empresa->nome_comercial }} &middot; ao abrigo da Lei n.º 22/11, de 17 de junho (Proteção de Dados Pessoais)</p>
    </header>

    <section class="space-y-1">
        <h2 class="font-semibold text-slate-900">1. Quem é o responsável</h2>
        <p>
            {{ $responsavel }}@if ($empresa->nif), NIF {{ $empresa->nif }}@endif
            @if ($empresa->morada), {{ $empresa->morada }}@endif
            @if ($empresa->municipio), {{ $empresa->municipio }}@endif
            @if ($empresa->provincia), {{ $empresa->provincia }}@endif.
            @if ($empresa->email) E-mail: {{ $empresa->email }}.@endif
            @if ($empresa->telefone) Telefone: {{ $empresa->telefone }}.@endif
        </p>
        @if ($registoApd)
            <p>Tratamento notificado à Agência de Proteção de Dados (APD) com o n.º {{ $registoApd }}.</p>
        @endif
        <p>O programa informático que usamos para gerir o estúdio trata os dados por nossa conta, como subcontratante.</p>
    </section>

    <section class="space-y-2">
        <h2 class="font-semibold text-slate-900">2. Que dados tratamos e para quê</h2>
        <ul class="list-disc space-y-1 pl-5">
            <li><strong>Pedidos de orçamento e reservas</strong> (nome, e-mail/telefone, mensagem): para responder ao teu pedido. Conservamos até {{ $mesesPedidos }} meses.</li>
            <li><strong>Serviços de estúdio</strong> (ficha do artista, projetos, sessões, ficheiros de áudio, comentários e aprovações no portal): para prestar o serviço contratado, durante a relação com o estúdio.</li>
            <li><strong>Faturação</strong> (nome, NIF, valores): obrigação legal fiscal, pelos prazos que a lei impõe.</li>
            <li><strong>Avisos do serviço</strong> (lembretes de sessão, novas versões para ouvir): para executar o serviço.</li>
            <li><strong>Mensagens promocionais</strong> (novidades, aniversário, campanhas): só se tiveres dado o teu consentimento. Podes retirá-lo a qualquer momento — cada mensagem traz uma ligação para deixares de as receber.</li>
            <li><strong>Reclamações</strong> (nome, contactos, descrição): para as tratar e responder. Conservamos até {{ $mesesReclamacoes }} meses.</li>
        </ul>
    </section>

    <section class="space-y-1">
        <h2 class="font-semibold text-slate-900">3. Com quem partilhamos</h2>
        <p>
            Com os prestadores que nos permitem comunicar contigo (e-mail, WhatsApp, SMS) e alojar o sistema.
            Alguns podem estar fora de Angola. As comunicações por WhatsApp e SMS só são enviadas quando
            existe autorização para a transferência internacional de dados
            @if ($autorizacaoApd) (autorização da APD n.º {{ $autorizacaoApd }}) @endif
            — caso contrário contactamos-te por e-mail.
            Não vendemos os teus dados.
        </p>
    </section>

    <section class="space-y-1">
        <h2 class="font-semibold text-slate-900">4. Os teus direitos</h2>
        <p>
            Podes pedir o acesso, a retificação, a eliminação e a oposição ao tratamento dos teus dados,
            contactando o estúdio pelos contactos acima. Podes ainda apresentar queixa à Agência de Proteção de
            Dados (APD). Os documentos fiscais têm de ser conservados pelo prazo legal, mesmo que peças a eliminação.
        </p>
    </section>

    <section class="space-y-1">
        <h2 class="font-semibold text-slate-900">5. Segurança</h2>
        <p>
            O acesso ao portal é feito por uma ligação pessoal temporária; os ficheiros de áudio não são públicos;
            o acesso da equipa depende do papel de cada pessoa; e as mensagens com ligações pessoais circulam cifradas
            dentro do sistema.
        </p>
    </section>

    <section class="space-y-1">
        <h2 class="font-semibold text-slate-900">6. Cookies</h2>
        <p>Usamos apenas cookies técnicos, necessários ao funcionamento das páginas. Não usamos cookies de publicidade.</p>
    </section>

    <p class="text-xs text-slate-400">Última atualização: {{ now()->format('d/m/Y') }}.</p>
    <p><a href="{{ route('estudiomusica.landing.mostrar', $empresa->id) }}" class="underline">&larr; Voltar</a></p>
</main>
@endsection
