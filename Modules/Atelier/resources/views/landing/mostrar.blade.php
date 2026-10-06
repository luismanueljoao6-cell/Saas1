@extends('atelier::layouts.publico')

@section('titulo', $empresa->nome_comercial)
@section('largura', 'max-w-5xl')

@section('conteudo')
    {{-- Cabeçalho --}}
    <header class="mb-12 text-center">
        <h1 class="text-3xl font-semibold text-slate-900">{{ $empresa->nome_comercial }}</h1>
        @if ($perfil->especialidades)
            <p class="mx-auto mt-3 max-w-2xl text-slate-600">{{ $perfil->especialidades }}</p>
        @endif
        <a href="#orcamento" class="mt-6 inline-block rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-500">
            Agende uma consulta / Peça um orçamento
        </a>
    </header>

    {{-- História (requisito F.1) --}}
    @if ($perfil->historia)
        <section class="mb-12">
            <h2 class="mb-3 text-lg font-semibold text-slate-900">A nossa história</h2>
            <p class="whitespace-pre-line text-slate-600">{{ $perfil->historia }}</p>
        </section>
    @endif

    {{-- Equipa (requisito F.1) --}}
    @if (! empty($perfil->equipa))
        <section class="mb-12">
            <h2 class="mb-3 text-lg font-semibold text-slate-900">A equipa</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ($perfil->equipa as $membro)
                    <div class="rounded-md border border-slate-200 bg-white p-4">
                        <p class="font-medium text-slate-800">{{ $membro['nome'] ?? '' }}</p>
                        <p class="text-sm text-slate-500">{{ $membro['funcao'] ?? '' }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Portfólio com filtro por categoria (requisito F.2). Filtro em Alpine.js, sem
         recarregar a página nem pedidos extra ao servidor. --}}
    @if ($portfolioPorCategoria->isNotEmpty())
        <section class="mb-12" x-data="{ filtro: 'todas' }">
            <h2 class="mb-3 text-lg font-semibold text-slate-900">Portfólio</h2>

            <div class="mb-4 flex flex-wrap gap-2">
                <button type="button" @click="filtro = 'todas'"
                        :class="filtro === 'todas' ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 border border-slate-300'"
                        class="rounded-full px-3 py-1 text-xs font-medium">Todas</button>
                @foreach ($portfolioPorCategoria as $categoria => $itens)
                    <button type="button" @click="filtro = '{{ $categoria }}'"
                            :class="filtro === '{{ $categoria }}' ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 border border-slate-300'"
                            class="rounded-full px-3 py-1 text-xs font-medium">
                        {{ $categorias[$categoria] ?? $categoria }}
                    </button>
                @endforeach
            </div>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach ($portfolioPorCategoria as $categoria => $itens)
                    @foreach ($itens as $item)
                        <figure x-show="filtro === 'todas' || filtro === '{{ $categoria }}'"
                                class="overflow-hidden rounded-md border border-slate-200 bg-white">
                            <img src="{{ $item->url() }}" alt="{{ $item->titulo }}" class="h-56 w-full object-cover" loading="lazy">
                            <figcaption class="p-3">
                                <p class="text-sm font-medium text-slate-800">{{ $item->titulo }}</p>
                                @if ($item->descricao)
                                    <p class="text-xs text-slate-500">{{ $item->descricao }}</p>
                                @endif
                            </figcaption>
                        </figure>
                    @endforeach
                @endforeach
            </div>
        </section>
    @endif

    {{-- Formulário de orçamento (requisito F.3) --}}
    <section id="orcamento" class="mb-12 rounded-md border border-slate-200 bg-white p-6">
        <h2 class="mb-1 text-lg font-semibold text-slate-900">Agende uma consulta / Peça um orçamento</h2>
        <p class="mb-4 text-sm text-slate-500">Deixa os teus dados e entramos em contacto contigo.</p>

        <form method="POST" action="{{ route('atelier.landing.solicitar-orcamento', $empresa) }}" class="space-y-4">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Nome *</label>
                    <input type="text" name="nome" required value="{{ old('nome') }}" class="w-full rounded-md border-slate-300 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Telefone ou e-mail *</label>
                    <input type="text" name="contacto" required value="{{ old('contacto') }}" class="w-full rounded-md border-slate-300 text-sm">
                </div>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">O que procuras?</label>
                <select name="categoria_interesse" class="w-full rounded-md border-slate-300 text-sm">
                    <option value="">Seleciona...</option>
                    @foreach ($categorias as $rotulo)
                        <option value="{{ $rotulo }}" @selected(old('categoria_interesse') === $rotulo)>{{ $rotulo }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Mensagem</label>
                <textarea name="mensagem" rows="3" class="w-full rounded-md border-slate-300 text-sm">{{ old('mensagem') }}</textarea>
            </div>
            <button type="submit" class="rounded-md bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Enviar pedido
            </button>
        </form>
    </section>

    {{-- Redes sociais --}}
    @if (! empty(array_filter($perfil->redes_sociais ?? [])))
        <footer class="flex justify-center gap-6 text-sm text-slate-500">
            @foreach (array_filter($perfil->redes_sociais) as $rede => $valor)
                <span>{{ ucfirst($rede) }}: {{ $valor }}</span>
            @endforeach
        </footer>
    @endif

    {{-- Alpine.js só é necessário para o filtro do portfólio; o layout público não o
         carrega por omissão (ao contrário de core::layouts.app), por isso vem aqui. --}}
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
@endsection
