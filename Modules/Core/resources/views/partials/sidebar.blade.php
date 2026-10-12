<aside class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform lg:translate-x-0 dark:border-zinc-800 dark:bg-zinc-900" :class="menuAberto ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
    <div class="flex h-16 items-center justify-between border-b border-slate-200 px-5 dark:border-zinc-800">
        <a href="{{ Route::has('core.painel') ? route('core.painel') : url('/') }}" class="font-semibold text-slate-900 dark:text-white">Saascompleto</a>
        <button type="button" class="rounded p-2 text-slate-500 lg:hidden" @click="menuAberto = false" aria-label="Fechar menu">✕</button>
    </div>
    <nav class="flex-1 space-y-5 overflow-y-auto p-3" aria-label="Navegação principal">
        @foreach (($nav ?? []) as $grupo)
            <section>
                <h2 class="px-3 pb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $grupo['rotulo'] }}</h2>
                <ul class="space-y-1">
                    @foreach ($grupo['itens'] as $item)
                        <li>
                            @if ($item['disponivel'] && $item['url'])
                                <a href="{{ $item['url'] }}" class="block rounded-lg px-3 py-2 text-sm {{ $item['ativo'] ? 'bg-indigo-50 font-semibold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' : 'text-slate-600 hover:bg-slate-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">{{ $item['rotulo'] }}</a>
                            @else
                                <span class="block cursor-not-allowed rounded-lg px-3 py-2 text-sm text-slate-400" title="Disponível em breve">{{ $item['rotulo'] }} <span class="text-xs">(em breve)</span></span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </nav>
    <div class="border-t border-slate-200 p-4 text-xs text-slate-500 dark:border-zinc-800 dark:text-zinc-400">{{ auth()->user()?->name }}</div>
</aside>
