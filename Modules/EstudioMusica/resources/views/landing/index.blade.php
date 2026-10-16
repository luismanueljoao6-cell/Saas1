@extends('estudiomusica::layouts.publico')

@section('titulo', ($empresa->nome_comercial ?? 'Estúdio') . ' — Estúdio de Música')

@section('conteudo')
<main class="mx-auto max-w-4xl px-4 py-12 space-y-16">

    {{-- Hero --}}
    <section class="text-center space-y-3">
        <h1 class="text-3xl font-bold text-slate-900">{{ $empresa->nome_comercial }}</h1>
        <p class="text-slate-600">Gravação, mixagem e masterização profissional.</p>
        <a href="#orcamento" class="inline-block rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-500">
            Pedir orçamento / reservar horário
        </a>
    </section>

    {{-- Salas --}}
    @if ($salas->isNotEmpty())
    <section class="space-y-4">
        <h2 class="text-xl font-semibold text-slate-900">As nossas salas</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($salas as $sala)
                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <h3 class="font-medium text-slate-900">{{ $sala->nome }}</h3>
                    <p class="mt-1 text-sm text-slate-600">{{ $sala->descricao ?: 'Equipamento profissional de gravação e produção.' }}</p>
                    @if (config('estudiomusica.landing_mostrar_precos', true) && (float) $sala->preco_hora > 0)
                        {{-- Indicação de preço (Lei de Defesa do Consumidor). O preço da sala é sem IVA. --}}
                        <p class="mt-2 text-sm font-medium text-slate-900">
                            {{ number_format((float) $sala->preco_hora, 2, ',', '.') }} Kz/hora
                            <span class="text-xs font-normal text-slate-500">(+ IVA)</span>
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
    @endif

    {{-- Portfólio --}}
    @if ($destaques->isNotEmpty())
    <section class="space-y-4">
        <h2 class="text-xl font-semibold text-slate-900">Trabalhos recentes</h2>
        <div class="grid gap-6 sm:grid-cols-2">
            @foreach ($destaques as $destaque)
                @php $incorporacao = $destaque->incorporacaoPublica(); @endphp
                @continue($incorporacao === null)
                <div class="rounded-lg border border-slate-200 bg-white p-4 space-y-2">
                    <h3 class="font-medium text-slate-900">{{ $destaque->nome }}</h3>
                    @if ($incorporacao['tipo'] === 'spotify')
                        <iframe src="{{ $incorporacao['url'] }}" class="w-full h-20 rounded" frameborder="0"
                                allow="encrypted-media" loading="lazy" referrerpolicy="no-referrer"></iframe>
                    @elseif ($incorporacao['tipo'] === 'youtube')
                        <div class="aspect-video">
                            <iframe src="{{ $incorporacao['url'] }}" class="h-full w-full rounded" frameborder="0"
                                    allowfullscreen loading="lazy" referrerpolicy="no-referrer"></iframe>
                        </div>
                    @else
                        <a href="{{ $incorporacao['url'] }}" target="_blank" rel="noopener noreferrer" class="text-sm text-indigo-600 underline">Ouvir &rarr;</a>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
    @endif

    {{-- Formulário de orçamento --}}
    <section id="orcamento" class="space-y-4">
        <h2 class="text-xl font-semibold text-slate-900">Pedir orçamento / reservar horário</h2>
        <form method="POST" action="{{ route('estudiomusica.landing.orcamento', $empresa) }}"
              class="grid gap-3 rounded-lg border border-slate-200 bg-white p-6 sm:grid-cols-2">
            @csrf
            @if ($errors->any())
                <div class="sm:col-span-2 rounded-md bg-red-50 p-3 text-sm text-red-700">
                    @foreach ($errors->all() as $erro)
                        <p>{{ $erro }}</p>
                    @endforeach
                </div>
            @endif
            {{-- Armadilha anti-robôs: escondida, nunca preenchida por pessoas. --}}
            <div class="hidden" aria-hidden="true">
                <input type="text" name="website" tabindex="-1" autocomplete="off">
            </div>
            <input type="text" name="nome" value="{{ old('nome') }}" placeholder="O teu nome" required class="rounded-md border-slate-300 text-sm sm:col-span-2">
            <input type="email" name="email" value="{{ old('email') }}" placeholder="E-mail" class="rounded-md border-slate-300 text-sm">
            <input type="tel" name="telefone" value="{{ old('telefone') }}" placeholder="Telefone (ex.: 923 456 789)" class="rounded-md border-slate-300 text-sm">
            <p class="text-xs text-slate-500 sm:col-span-2">Indica pelo menos um meio de contacto (e-mail ou telefone).</p>
            <select name="tipo_servico_desejado" class="rounded-md border-slate-300 text-sm sm:col-span-2">
                <option value="">O que precisas?</option>
                @foreach ($tiposServico as $chave => $rotulo)
                    <option value="{{ $chave }}" @selected(old('tipo_servico_desejado') === $chave)>{{ $rotulo }}</option>
                @endforeach
            </select>
            <input type="date" name="data_preferida" value="{{ old('data_preferida') }}" min="{{ now()->toDateString() }}" class="rounded-md border-slate-300 text-sm sm:col-span-2">
            <textarea name="mensagem" placeholder="Conta-nos mais sobre o teu projeto" rows="3" class="rounded-md border-slate-300 text-sm sm:col-span-2">{{ old('mensagem') }}</textarea>
            <div class="space-y-2 text-xs text-slate-600 sm:col-span-2">
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="aceita_politica" value="1" required class="mt-0.5 rounded border-slate-300">
                    <span>
                        Autorizo que {{ $empresa->nome_comercial }} trate o meu nome e contactos para responder a este
                        pedido (Lei n.º 22/11, da Proteção de Dados Pessoais). Posso pedir o acesso, a retificação ou
                        a eliminação dos meus dados a qualquer momento, contactando o estúdio.
                        Ver a <a href="{{ route('estudiomusica.landing.privacidade', $empresa->id) }}" target="_blank" rel="noopener" class="underline">Política de Privacidade</a>.
                    </span>
                </label>
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="aceita_marketing" value="1" class="mt-0.5 rounded border-slate-300">
                    <span>Aceito receber novidades e promoções do estúdio (opcional; posso cancelar quando quiser).</span>
                </label>
            </div>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 sm:col-span-2">
                Enviar pedido
            </button>
        </form>
    </section>

    {{-- Reclamações: canal digital; o Livro de Reclamações físico continua a ser obrigatório no estabelecimento. --}}
    <section class="space-y-3 rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="text-lg font-semibold text-slate-900">Reclamações</h2>
        <p class="text-sm text-slate-600">
            Tens à tua disposição o Livro de Reclamações no nosso estabelecimento (Decreto Presidencial n.º 234/16).
            Se preferires, regista aqui a tua reclamação e respondemos-te.
        </p>
        <form method="POST" action="{{ route('estudiomusica.landing.reclamacao', $empresa->id) }}" class="grid gap-3 sm:grid-cols-2">
            @csrf
            @if ($errors->reclamacao->any())
                <div class="sm:col-span-2 rounded-md bg-red-50 p-3 text-sm text-red-700">
                    @foreach ($errors->reclamacao->all() as $erro)
                        <p>{{ $erro }}</p>
                    @endforeach
                </div>
            @endif
            <div class="hidden" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
            <input type="text" name="nome" value="{{ old('nome') }}" placeholder="O teu nome" required class="rounded-md border-slate-300 text-sm sm:col-span-2">
            <input type="email" name="email" placeholder="E-mail" class="rounded-md border-slate-300 text-sm">
            <input type="tel" name="telefone" placeholder="Telefone" class="rounded-md border-slate-300 text-sm">
            <input type="text" name="assunto" placeholder="Assunto" required maxlength="150" class="rounded-md border-slate-300 text-sm sm:col-span-2">
            <textarea name="descricao" rows="4" placeholder="Descreve o que aconteceu" required minlength="10" class="rounded-md border-slate-300 text-sm sm:col-span-2"></textarea>
            <label class="flex items-start gap-2 text-xs text-slate-600 sm:col-span-2">
                <input type="checkbox" name="aceita_politica" value="1" required class="mt-0.5 rounded border-slate-300">
                <span>Autorizo o tratamento dos meus dados para tratar esta reclamação (<a href="{{ route('estudiomusica.landing.privacidade', $empresa->id) }}" target="_blank" rel="noopener" class="underline">Política de Privacidade</a>).</span>
            </label>
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 sm:col-span-2">Registar reclamação</button>
        </form>
    </section>

    <footer class="pb-6 text-center text-xs text-slate-500">
        <a href="{{ route('estudiomusica.landing.privacidade', $empresa->id) }}" class="underline">Política de Privacidade</a>
    </footer>
</main>
@endsection
