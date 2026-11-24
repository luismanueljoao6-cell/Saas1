@extends('core::layouts.app')

@section('titulo', 'Verifica o teu e-mail')

@section('conteudo')
    <div class="mx-auto max-w-xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Verifica o teu e-mail</h1>
        <p class="mt-1 text-sm text-slate-500">
            Enviámos uma ligação de confirmação para <strong>{{ auth()->user()->email }}</strong>.
            Abre-a para ativar o acesso completo à plataforma.
        </p>

        @if (session('status'))
            <div role="status" class="mt-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
                {{ session('status') }}
            </div>
        @endif

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Reenviar e-mail de verificação
                </button>
            </form>

            <form method="POST" action="{{ route('core.logout') }}">
                @csrf
                <button type="submit" class="text-sm font-medium text-slate-600 hover:text-slate-900">
                    Terminar sessão
                </button>
            </form>
        </div>
    </div>
@endsection
