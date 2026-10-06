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
                <div class="rounded-lg border border-slate-200 bg-white p-4 space-y-2">
                    <h3 class="font-medium text-slate-900">{{ $destaque->nome }}</h3>
                    @if (str_contains($destaque->link_publico, 'spotify.com'))
                        <iframe src="{{ str_replace('open.spotify.com/', 'open.spotify.com/embed/', $destaque->link_publico) }}"
                                class="w-full h-20 rounded" frameborder="0" allow="encrypted-media"></iframe>
                    @elseif (str_contains($destaque->link_publico, 'youtube.com') || str_contains($destaque->link_publico, 'youtu.be'))
                        <div class="aspect-video">
                            <iframe src="{{ $destaque->link_publico }}" class="h-full w-full rounded" frameborder="0" allowfullscreen></iframe>
                        </div>
                    @else
                        <a href="{{ $destaque->link_publico }}" target="_blank" class="text-sm text-indigo-600 underline">Ouvir &rarr;</a>
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
            <input type="text" name="nome" placeholder="O teu nome" required class="rounded-md border-slate-300 text-sm sm:col-span-2">
            <input type="email" name="email" placeholder="E-mail" class="rounded-md border-slate-300 text-sm">
            <input type="tel" name="telefone" placeholder="Telefone" class="rounded-md border-slate-300 text-sm">
            <select name="tipo_servico_desejado" class="rounded-md border-slate-300 text-sm sm:col-span-2">
                <option value="">O que precisas?</option>
                @foreach ($tiposServico as $chave => $rotulo)
                    <option value="{{ $chave }}">{{ $rotulo }}</option>
                @endforeach
            </select>
            <input type="date" name="data_preferida" class="rounded-md border-slate-300 text-sm sm:col-span-2">
            <textarea name="mensagem" placeholder="Conta-nos mais sobre o teu projeto" rows="3" class="rounded-md border-slate-300 text-sm sm:col-span-2"></textarea>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 sm:col-span-2">
                Enviar pedido
            </button>
        </form>
    </section>
</main>
@endsection
