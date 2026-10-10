@extends('core::layouts.app')

@section('titulo', 'Planos')

@section('conteudo')
    <div class="mx-auto w-full max-w-3xl">
        <h1 class="text-center text-2xl font-semibold text-slate-900">Escolhe o teu plano</h1>
        <p class="mt-1 text-center text-sm text-slate-500">Podes mudar de plano a qualquer momento.</p>

        @if (session('erro'))
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                {{ session('erro') }}
            </div>
        @endif

        @if ($subscricaoAtual)
            <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-900">
                <p>
                    Plano <strong>{{ $subscricaoAtual->plano->nome }}</strong> ativo até
                    <strong>{{ $subscricaoAtual->termina_em?->format('d/m/Y') }}</strong>.
                </p>
                <p class="mt-1 text-emerald-800">
                    @if ($subscricaoAtual->renovacao_automatica)
                        Renovação ativa: {{ config('subscricoes.aviso_renovacao_dias') }} dias antes do fim enviamos-te
                        por e-mail a referência do período seguinte. Os dias que restarem somam-se.
                    @else
                        Renovação cancelada: manténs o acesso até ao fim do período pago e não enviamos nova referência.
                    @endif
                </p>

                @if (auth()->user()->hasRole('Administrador'))
                    <form method="POST" action="{{ route('subscricoes.renovacao', $subscricaoAtual) }}" class="mt-3">
                        @csrf
                        <button type="submit" class="text-xs font-medium underline hover:text-emerald-700">
                            {{ $subscricaoAtual->renovacao_automatica ? 'Cancelar renovação' : 'Retomar renovação' }}
                        </button>
                    </form>
                @endif
            </div>
        @endif

        <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @forelse ($planos as $plano)
                <div class="flex flex-col rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-900">{{ $plano->nome }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $plano->descricao }}</p>

                    <p class="mt-4 text-2xl font-bold text-slate-900">
                        {{ number_format((float) $plano->preco, 0, ',', ' ') }} {{ $plano->moeda }}
                        <span class="text-sm font-normal text-slate-500">/ {{ $plano->periodo_dias }} dias</span>
                    </p>

                    @if (! empty($plano->servicos))
                        <ul class="mt-4 space-y-1 text-sm text-slate-600">
                            @foreach ($plano->servicos as $codigo)
                                @if ($servicoPlano = \Modules\Core\Support\Servico::tryFrom($codigo))
                                    <li>✓ {{ $servicoPlano->rotulo() }}</li>
                                @endif
                            @endforeach
                        </ul>
                        <p class="mt-3 text-xs text-slate-500">
                            Ao confirmar o pagamento, os serviços da empresa passam a ser os deste pacote.
                        </p>
                    @endif

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
