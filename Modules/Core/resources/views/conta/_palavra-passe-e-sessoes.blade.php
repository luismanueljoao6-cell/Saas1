<section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <h2 class="text-base font-semibold text-slate-900 dark:text-zinc-100">Alterar palavra-passe</h2>
    <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">
        Ao alterar, terminamos as tuas outras sessões e revogamos os tokens de API. Esta sessão continua aberta.
    </p>

    <form method="POST" action="{{ route('core.seguranca.palavra-passe') }}" class="mt-4 space-y-3">
        @csrf
        @method('PUT')

        <div>
            <label for="pw-atual" class="block text-sm font-medium text-slate-700 dark:text-zinc-300">Palavra-passe atual</label>
            <input id="pw-atual" type="password" name="password_atual" required autocomplete="current-password"
                   class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
        </div>

        <div>
            <label for="pw-nova" class="block text-sm font-medium text-slate-700 dark:text-zinc-300">Palavra-passe nova</label>
            <input id="pw-nova" type="password" name="password" required autocomplete="new-password"
                   class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
        </div>

        <div>
            <label for="pw-nova-confirmacao" class="block text-sm font-medium text-slate-700 dark:text-zinc-300">Confirma a palavra-passe nova</label>
            <input id="pw-nova-confirmacao" type="password" name="password_confirmation" required autocomplete="new-password"
                   class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
        </div>

        <button type="submit"
                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
            Alterar palavra-passe
        </button>
    </form>
</section>

<section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <h2 class="text-base font-semibold text-slate-900 dark:text-zinc-100">Terminar outras sessões</h2>
    <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">
        Usa isto se deixaste a conta aberta noutro dispositivo. Termina todas as outras sessões e revoga os
        tokens de API; esta sessão continua aberta.
    </p>

    <form method="POST" action="{{ route('core.seguranca.sessoes.terminar') }}" class="mt-4 space-y-3">
        @csrf
        @include('core::conta._palavra-passe', ['id' => 'pw-sessoes'])
        <button type="submit"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-700 dark:bg-zinc-100 dark:text-zinc-900">
            Terminar outras sessões
        </button>
    </form>
</section>
