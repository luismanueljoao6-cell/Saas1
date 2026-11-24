@extends('core::layouts.app')

@section('titulo', 'Definições de segurança')

@section('conteudo')
    <div class="mx-auto w-full max-w-2xl space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-slate-900 dark:text-zinc-100">Definições de segurança</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">Protege a tua conta com um segundo passo no início de sessão.</p>
        </div>

        @if ($errors->any())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-zinc-100">Verificação em dois passos</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-zinc-400">
                        Além da palavra-passe, pedimos um código de 6 dígitos gerado pela tua app autenticadora
                        (Google Authenticator, Microsoft Authenticator, Authy…).
                    </p>
                </div>
                @if ($ativo)
                    <span class="shrink-0 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">Ativa</span>
                @else
                    <span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 dark:bg-zinc-800 dark:text-zinc-300">Inativa</span>
                @endif
            </div>

            @if ($ativo)
                <p class="mt-4 text-sm text-slate-600 dark:text-zinc-300">
                    Ativa desde {{ $ativoDesde->format('d/m/Y H:i') }}.
                    Restam <strong>{{ $restantes }}</strong> código(s) de recuperação.
                </p>

                @if ($restantes <= 2)
                    <p class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        Tens poucos códigos de recuperação. Gera novos antes de ficares sem eles.
                    </p>
                @endif

                <div class="mt-6 grid gap-6 sm:grid-cols-2">
                    <form method="POST" action="{{ route('core.seguranca.2fa.codigos') }}" class="space-y-3">
                        @csrf
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-zinc-100">Novos códigos de recuperação</h3>
                        <p class="text-xs text-slate-500 dark:text-zinc-400">Os códigos anteriores deixam de funcionar.</p>
                        @include('core::conta._palavra-passe', ['id' => 'pw-codigos'])
                        <button type="submit"
                                class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-700 dark:bg-zinc-100 dark:text-zinc-900">
                            Gerar códigos novos
                        </button>
                    </form>

                    <form method="POST" action="{{ route('core.seguranca.2fa.desativar') }}" class="space-y-3"
                          onsubmit="return confirm('Desativar a verificação em dois passos?')">
                        @csrf
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-zinc-100">Desativar</h3>
                        <p class="text-xs text-slate-500 dark:text-zinc-400">A conta volta a entrar só com a palavra-passe.</p>
                        @include('core::conta._palavra-passe', ['id' => 'pw-desativar'])
                        <button type="submit"
                                class="w-full rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500">
                            Desativar
                        </button>
                    </form>
                </div>
            @elseif ($segredo)
                <div class="mt-4 space-y-4 text-sm text-slate-600 dark:text-zinc-300">
                    <p><strong>1.</strong> Na app autenticadora, adiciona uma conta nova. No telemóvel podes
                        <a href="{{ $uri }}" class="font-medium text-indigo-600 hover:text-indigo-500">abrir a ligação diretamente na app</a>;
                        ou escreve esta chave:</p>

                    <code class="block select-all break-all rounded-md bg-slate-100 px-3 py-2 font-mono text-sm tracking-wider text-slate-900 dark:bg-zinc-800 dark:text-zinc-100">{{ $segredoFormatado }}</code>

                    <p><strong>2.</strong> Escreve o código de 6 dígitos que a app mostra, para confirmar.</p>

                    <form method="POST" action="{{ route('core.seguranca.2fa.confirmar') }}" class="space-y-3">
                        @csrf
                        <div>
                            <label for="codigo" class="block text-sm font-medium text-slate-700 dark:text-zinc-300">Código</label>
                            <input id="codigo" type="text" name="codigo" required autocomplete="one-time-code"
                                   inputmode="numeric" maxlength="16" placeholder="123456"
                                   class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm tracking-widest shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                        </div>
                        <button type="submit"
                                class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                            Confirmar e ativar
                        </button>
                    </form>

                    <form method="POST" action="{{ route('core.seguranca.2fa.desativar') }}" class="space-y-3 border-t border-slate-200 pt-4 dark:border-zinc-800">
                        @csrf
                        <p class="text-xs text-slate-500 dark:text-zinc-400">Mudaste de ideias? Cancela a ativação (apaga esta chave).</p>
                        @include('core::conta._palavra-passe', ['id' => 'pw-cancelar'])
                        <button type="submit"
                                class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Cancelar ativação
                        </button>
                    </form>
                </div>
            @else
                <form method="POST" action="{{ route('core.seguranca.2fa.iniciar') }}" class="mt-6 space-y-3">
                    @csrf
                    @include('core::conta._palavra-passe', ['id' => 'pw-ativar'])
                    <button type="submit"
                            class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 sm:w-auto">
                        Ativar verificação em dois passos
                    </button>
                </form>
            @endif
        </section>

        @include('core::conta._palavra-passe-e-sessoes')
    </div>
@endsection
