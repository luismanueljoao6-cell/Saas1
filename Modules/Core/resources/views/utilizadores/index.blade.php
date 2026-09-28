@extends('core::layouts.app')

@section('titulo', 'Utilizadores')

@section('conteudo')
    <div class="mx-auto w-full max-w-2xl">
        <h1 class="text-xl font-semibold text-slate-900">Utilizadores</h1>
        <p class="mt-1 text-sm text-slate-500">Quem tem acesso à conta da tua empresa.</p>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div x-data="{ aFormulario: {{ $errors->any() ? 'true' : 'false' }} }" class="mt-4">
            <button type="button" @click="aFormulario = !aFormulario"
                    class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                <span x-text="aFormulario ? '− Fechar' : '+ Novo utilizador'"></span>
            </button>

            <form x-show="aFormulario" x-cloak method="POST" action="{{ route('core.utilizadores.store') }}"
                  class="mt-3 grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-4">
                @csrf
                <input name="name" value="{{ old('name') }}" placeholder="Nome" required
                       class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input name="email" type="email" value="{{ old('email') }}" placeholder="E-mail" required
                       class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input name="password" type="password" placeholder="Palavra-passe temporária" required autocomplete="new-password"
                       class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <input name="password_confirmation" type="password" placeholder="Confirmar palavra-passe" required autocomplete="new-password"
                       class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="administrador" value="1" class="rounded border-slate-300">
                    Também é administrador
                </label>
                <p class="text-xs text-slate-400">Comunica a palavra-passe ao colega por fora da aplicação (ex.: WhatsApp).</p>
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    Criar utilizador
                </button>
            </form>
        </div>

        <div class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
            @foreach ($utilizadores as $utilizador)
                <div class="flex items-center justify-between px-4 py-3">
                    <div>
                        <p class="text-sm font-medium {{ $utilizador->ativo ? 'text-slate-900' : 'text-slate-400 line-through' }}">
                            {{ $utilizador->name }}
                            @if ($utilizador->id === auth()->id())
                                <span class="text-xs font-normal text-slate-400">(tu)</span>
                            @endif
                        </p>
                        <p class="text-xs text-slate-500">{{ $utilizador->email }}</p>
                    </div>

                    @if ($utilizador->id !== auth()->id())
                        <form method="POST" action="{{ route('core.utilizadores.alternar-ativo', $utilizador) }}">
                            @csrf
                            <button type="submit" class="text-xs font-medium {{ $utilizador->ativo ? 'text-red-600 hover:text-red-500' : 'text-emerald-600 hover:text-emerald-500' }}">
                                {{ $utilizador->ativo ? 'Desativar' : 'Reativar' }}
                            </button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endsection
