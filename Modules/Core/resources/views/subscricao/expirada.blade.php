@extends('core::layouts.app')

@section('titulo', 'Subscrição inativa')

@section('conteudo')
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-8 text-center shadow-sm">
        <h1 class="text-xl font-semibold text-amber-900">Subscrição inativa</h1>
        <p class="mt-2 text-sm text-amber-800">
            A subscrição da tua empresa expirou e o período de tolerância já
            terminou. Regulariza o pagamento para recuperares o acesso.
        </p>

        {{-- O Módulo de Subscrições e Pagamentos deve substituir este botão
             por um link real para o fluxo de pagamento (ex.: Multicaixa
             Express). --}}
        <button type="button" disabled
                class="mt-6 inline-block cursor-not-allowed rounded-md bg-amber-300 px-4 py-2 text-sm font-semibold text-amber-900">
            Regularizar pagamento (em breve)
        </button>

        <form method="POST" action="{{ route('core.logout') }}" class="mt-4">
            @csrf
            <button type="submit" class="text-sm font-medium text-amber-700 underline hover:text-amber-900">
                Terminar sessão
            </button>
        </form>
    </div>
@endsection
