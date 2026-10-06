@extends('core::layouts.app')

@section('titulo', 'Subscrição inativa')

@section('conteudo')
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-8 text-center shadow-sm">
        <h1 class="text-xl font-semibold text-amber-900">Subscrição inativa</h1>
        <p class="mt-2 text-sm text-amber-800">
            A subscrição da tua empresa expirou e o período de tolerância já
            terminou. Regulariza o pagamento para recuperares o acesso.
        </p>

        {{-- Só aparece se o módulo Subscrições estiver instalado. --}}
        @if (\Illuminate\Support\Facades\Route::has('subscricoes.planos'))
            <a href="{{ route('subscricoes.planos') }}"
               class="mt-6 inline-block rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-500">
                Ver planos e regularizar
            </a>
        @endif

        <form method="POST" action="{{ route('core.logout') }}" class="mt-4">
            @csrf
            <button type="submit" class="text-sm font-medium text-amber-700 underline hover:text-amber-900">
                Terminar sessão
            </button>
        </form>
    </div>
@endsection
