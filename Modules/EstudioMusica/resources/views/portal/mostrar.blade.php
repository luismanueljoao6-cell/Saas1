@extends('estudiomusica::layouts.publico')

@section('titulo', 'Portal do Cliente')

@section('conteudo')
<main class="mx-auto max-w-3xl px-4 py-10 space-y-8">
    <header>
        <h1 class="text-xl font-semibold text-slate-900">Olá, {{ $cliente->nome_artistico ?: $cliente->nome }}!</h1>
        <p class="text-sm text-slate-500">Os teus projetos, versões para audição e aprovação.</p>
    </header>

    @forelse ($projetos as $projeto)
        <section class="rounded-lg border border-slate-200 bg-white p-5 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="font-medium text-slate-900">{{ $projeto->nome }}</h2>
                <span class="text-xs text-slate-500">{{ $projeto->rotuloEstado() }}</span>
            </div>

            @forelse ($projeto->faixas as $faixa)
                <div class="border-t border-slate-100 pt-3 space-y-3">
                    <p class="text-sm font-medium text-slate-800">{{ $faixa->nome }}</p>

                    @forelse ($faixa->versoes as $versao)
                        <div x-data="{ tempoAtual: 0 }" class="rounded-md bg-slate-50 p-3 space-y-2">
                            <div class="flex items-center justify-between text-xs text-slate-500">
                                <span>{{ $versao->rotulo }} &middot; {{ ucfirst(str_replace('_', ' ', $versao->estado)) }}</span>
                                @if ($projeto->financeiramenteQuitado())
                                    <a href="{{ route('estudiomusica.portal.versoes.descarregar', ['token' => request()->route('token'), 'versao' => $versao->id]) }}" class="underline">descarregar</a>
                                @else
                                    <span title="Disponível depois de quitado o saldo do projeto">descarregar (bloqueado)</span>
                                @endif
                            </div>

                            <audio controls preload="none" class="w-full"
                                   @timeupdate="tempoAtual = $event.target.currentTime"
                                   src="{{ route('estudiomusica.portal.versoes.reproduzir', ['token' => request()->route('token'), 'versao' => $versao->id]) }}"></audio>

                            {{-- Marcadores de tempo já deixados --}}
                            <ul class="space-y-1 text-xs text-slate-600">
                                @foreach ($versao->marcadores as $marcador)
                                    <li><span class="font-mono text-slate-400">{{ $marcador->tempoFormatado() }}</span> — {{ $marcador->comentario }}</li>
                                @endforeach
                            </ul>

                            <form method="POST" action="{{ route('estudiomusica.portal.versoes.comentar', ['token' => request()->route('token'), 'versao' => $versao->id]) }}" class="flex gap-2">
                                @csrf
                                <input type="hidden" name="tempo_segundos" :value="Math.floor(tempoAtual)">
                                <input type="text" name="comentario" placeholder="Comentário neste ponto do áudio" required class="flex-1 rounded-md border-slate-300 text-xs">
                                <button type="submit" class="rounded-md bg-slate-900 px-2.5 py-1 text-xs text-white">Comentar</button>
                            </form>

                            <div class="flex gap-2">
                                <form method="POST" action="{{ route('estudiomusica.portal.versoes.aprovar', ['token' => request()->route('token'), 'versao' => $versao->id]) }}">
                                    @csrf
                                    <button type="submit" class="rounded-md bg-emerald-600 px-2.5 py-1 text-xs text-white hover:bg-emerald-500">Aprovar versão final</button>
                                </form>
                                <form method="POST" action="{{ route('estudiomusica.portal.versoes.ajustes', ['token' => request()->route('token'), 'versao' => $versao->id]) }}">
                                    @csrf
                                    <button type="submit" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-100">Solicitar ajustes</button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-slate-400">Ainda sem versões enviadas para esta faixa.</p>
                    @endforelse
                </div>
            @empty
                <p class="text-sm text-slate-500">Ainda sem faixas neste projeto.</p>
            @endforelse
        </section>
    @empty
        <p class="text-sm text-slate-500">Ainda não há projetos associados à tua conta.</p>
    @endforelse
</main>
@endsection
