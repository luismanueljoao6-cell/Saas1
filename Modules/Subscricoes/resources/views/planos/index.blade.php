@extends('core::layouts.app')

@section('titulo', 'Planos')

@section('conteudo')
    <div class="w-full max-w-3xl">
        <h1 class="text-center text-2xl font-semibold text-slate-900">Escolhe o teu plano</h1>
        <p class="mt-1 text-center text-sm text-slate-500">Podes mudar de plano a qualquer momento.</p>

        @if (session('erro'))
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                {{ session('erro') }}
            </div>
        @endif

        <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
            @forelse ($planos as $plano)
                <div class="flex flex-col rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-900">{{ $plano->nome }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $plano->descricao }}</p>

                    <p class="mt-4 text-2xl font-bold text-slate-900">
                        {{ number_format((float) $plano->preco, 0, ',', ' ') }} {{ $plano->moeda }}
                        <span class="text-sm font-normal text-slate-500">/ {{ $plano->periodo_dias }} dias</span>
                    </p>

                    <form method="POST" action="{{ route('subscricoes.iniciar') }}" class="mt-6">
                        @csrf
                        <input type="hidden" name="plano_id" value="{{ $plano->id }}">
                        <button type="submit"
                                class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                            Subscrever
                        </button>
                    </form>
                </div>
            @empty
                <p class="col-span-3 text-center text-sm text-slate-500">
                    Ainda não há planos configurados — corre o PlanosSeeder.
                </p>
            @endforelse
        </div>
    </div>
@endsection
