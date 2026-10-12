<header class="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6 dark:border-zinc-800 dark:bg-zinc-900/90 lg:px-8">
    <div class="flex items-center gap-3">
        <button type="button" class="rounded-lg border border-slate-200 p-2 text-slate-600 hover:bg-slate-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 lg:hidden" @click="menuAberto = true" aria-label="Abrir menu">☰</button>
        <span class="text-sm font-medium text-slate-500 dark:text-zinc-400">Painel de gestão</span>
    </div>
    <div class="flex items-center gap-3">
        <button type="button" onclick="alternarTema()" class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:bg-slate-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800" aria-label="Alternar tema">Tema</button>
        @auth
            <span class="hidden text-sm font-medium text-slate-700 dark:text-zinc-200 sm:inline">{{ auth()->user()->name }}</span>
            @if (Route::has('logout'))
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-zinc-100 dark:text-zinc-900" type="submit">Sair</button></form>
            @endif
        @endauth
    </div>
</header>
