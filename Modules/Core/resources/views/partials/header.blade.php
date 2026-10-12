<header class="flex min-h-16 items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 sm:px-6 dark:border-zinc-800 dark:bg-zinc-900">
    <div class="flex min-w-0 items-center gap-3">
        <button type="button" @click="menuAberto = !menuAberto" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden dark:text-zinc-300 dark:hover:bg-zinc-800" aria-label="Abrir ou fechar menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-5" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <div class="min-w-0">
            <p class="truncate text-xs text-slate-500 dark:text-zinc-400">{{ config('app.name', 'Saas1') }}</p>
            <p class="truncate text-sm font-semibold text-slate-900 dark:text-zinc-100">@yield('titulo', 'Painel')</p>
        </div>
    </div>
    <div class="flex shrink-0 items-center gap-2 sm:gap-4">
        <button type="button" onclick="alternarTema()" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800" aria-label="Alternar tema">Tema</button>
        <div class="hidden text-right sm:block">
            <p class="text-sm font-medium text-slate-800 dark:text-zinc-100">{{ auth()->user()?->name }}</p>
            <p class="text-xs text-slate-500 dark:text-zinc-400">{{ auth()->user()?->email }}</p>
        </div>
        @if (Route::has('core.logout'))
            <form method="POST" action="{{ route('core.logout') }}">
                @csrf
                <button type="submit" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100 dark:text-zinc-300 dark:hover:bg-zinc-800">Sair</button>
            </form>
        @endif
    </div>
</header>
