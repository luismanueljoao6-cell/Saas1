<!DOCTYPE html>
<html lang="pt" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Plataforma de Gestão')</title>

    {{-- Aplica o tema guardado ANTES de pintar a página (evita o "flash" claro->escuro). --}}
    <script>
        (function () {
            try {
                var guardado = localStorage.getItem('tema');
                var escuro = guardado ? guardado === 'escuro' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', escuro);
            } catch (e) {}
        })();

        function alternarTema() {
            var escuro = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('tema', escuro ? 'escuro' : 'claro'); } catch (e) {}
        }
    </script>

    {{-- Inter (servida pelo Bunny Fonts, sem rastreio). Se preferires self-host, remove estas 3 linhas
         e instala @fontsource-variable/inter; o CSS já tem fallback para a fonte do sistema. --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">

    @vite(['Modules/Core/resources/css/app.css'])
    @stack('estilos')

    {{-- Alpine.js via CDN (já usado neste módulo). Numa fase seguinte, mover para npm + Vite. --}}
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body class="h-full bg-slate-50 font-sans text-slate-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">

    @auth
        @php($nav = \Modules\Core\Support\Navegacao::grupos())

        <div x-data="{ menuAberto: false }" @keydown.escape.window="menuAberto = false" class="min-h-screen">
            {{-- Fundo escurecido do menu em ecrãs pequenos --}}
            <div x-show="menuAberto" x-cloak x-transition.opacity @click="menuAberto = false"
                 class="fixed inset-0 z-40 bg-zinc-900/50 lg:hidden"></div>

            @include('core::partials.sidebar')

            <div class="flex min-h-screen flex-col lg:pl-64">
                @include('core::partials.header')

                <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    <div class="mx-auto w-full max-w-7xl">
                        @if (session('sucesso'))
                            <div x-data="{ visivel: true }" x-show="visivel" role="status"
                                 class="mb-4 flex items-start justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
                                <span>{{ session('sucesso') }}</span>
                                <button type="button" @click="visivel = false" class="shrink-0 opacity-70 hover:opacity-100" aria-label="Fechar aviso">
                                    @include('core::partials.icone', ['nome' => 'fechar', 'classe' => 'size-4'])
                                </button>
                            </div>
                        @endif

                        @if (session('erro'))
                            <div x-data="{ visivel: true }" x-show="visivel" role="alert"
                                 class="mb-4 flex items-start justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
                                <span>{{ session('erro') }}</span>
                                <button type="button" @click="visivel = false" class="shrink-0 opacity-70 hover:opacity-100" aria-label="Fechar aviso">
                                    @include('core::partials.icone', ['nome' => 'fechar', 'classe' => 'size-4'])
                                </button>
                            </div>
                        @endif

                        @yield('conteudo')
                    </div>
                </main>
            </div>
        </div>
    @else
        {{-- Visitantes (login, registo): mantém o ecrã simples e centrado. --}}
        @if (session('sucesso'))
            <div class="border-b border-emerald-200 bg-emerald-50 px-4 py-3 text-center text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
                {{ session('sucesso') }}
            </div>
        @endif

        @if (session('erro'))
            <div class="border-b border-red-200 bg-red-50 px-4 py-3 text-center text-sm text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
                {{ session('erro') }}
            </div>
        @endif

        <main class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
            <div class="w-full max-w-5xl">
                @yield('conteudo')
            </div>
        </main>
    @endauth

    @stack('scripts')
</body>
</html>
