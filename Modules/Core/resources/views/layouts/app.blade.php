<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('titulo', 'Plataforma de Gestão')</title>

    @vite(['Modules/Core/resources/css/app.css'])

    {{-- Alpine.js via CDN para os pequenos toques de interatividade deste
         módulo (ex.: mostrar/ocultar password). Numa fase seguinte, mover
         para um pacote npm importado e compilado pelo Vite. --}}
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

    @auth
        @php($itensMenu = app(\Modules\Core\Services\MenuRegistry::class)->itens())
        <nav x-data="{ aberto: false }" class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
                <a href="{{ route('core.painel') }}" class="text-sm font-semibold text-slate-900">
                    {{ auth()->user()->empresa->nome_comercial ?? 'Plataforma de Gestão' }}
                </a>

                {{-- Desktop: lista horizontal --}}
                <div class="hidden items-center gap-5 sm:flex">
                    @foreach ($itensMenu as $item)
                        <a href="{{ $item['url'] }}"
                           class="text-sm {{ request()->routeIs($item['rota']) ? 'font-semibold text-indigo-600' : 'text-slate-600 hover:text-slate-900' }}">
                            {{ $item['rotulo'] }}
                        </a>
                    @endforeach
                    <form method="POST" action="{{ route('core.logout') }}">
                        @csrf
                        <button type="submit" class="text-sm text-slate-500 hover:text-slate-900">Sair</button>
                    </form>
                </div>

                {{-- Mobile: botão hambúrguer --}}
                <button type="button" @click="aberto = !aberto" class="sm:hidden" aria-label="Abrir menu">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path x-show="!aberto" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path x-show="aberto" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Mobile: lista em coluna, só aparece quando aberto --}}
            <div x-show="aberto" x-cloak class="border-t border-slate-200 sm:hidden">
                <div class="flex flex-col divide-y divide-slate-100">
                    @foreach ($itensMenu as $item)
                        <a href="{{ $item['url'] }}"
                           class="px-4 py-3 text-sm {{ request()->routeIs($item['rota']) ? 'font-semibold text-indigo-600' : 'text-slate-700' }}">
                            {{ $item['rotulo'] }}
                        </a>
                    @endforeach
                    <form method="POST" action="{{ route('core.logout') }}" class="px-4 py-3">
                        @csrf
                        <button type="submit" class="text-sm text-slate-500">Sair</button>
                    </form>
                </div>
            </div>
        </nav>
    @endauth

    @if (session('sucesso'))
        <div class="bg-emerald-50 border-b border-emerald-200 text-emerald-800 text-sm px-4 py-3 text-center">
            {{ session('sucesso') }}
        </div>
    @endif

    @if (session('erro'))
        <div class="bg-red-50 border-b border-red-200 text-red-800 text-sm px-4 py-3 text-center">
            {{ session('erro') }}
        </div>
    @endif

    <main class="flex min-h-screen flex-col items-center px-4 py-10 @guest justify-center @endguest">
        <div class="w-full max-w-5xl">
            @yield('conteudo')
        </div>
    </main>

</body>
</html>
