@extends('core::layouts.app')

@section('titulo', $projeto->nome)

@section('conteudo')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-slate-900">{{ $projeto->nome }}</h1>
            <p class="text-sm text-slate-500">{{ $projeto->cliente->nome_artistico ?: $projeto->cliente->nome }} &middot; {{ $projeto->tipo_cobranca === 'pacote' ? 'Pacote fechado' : 'Cobrança por hora' }}</p>
        </div>
        <form method="POST" action="{{ route('estudiomusica.projetos.link-portal', $projeto) }}">
            @csrf
            <button type="submit" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                Gerar link do Portal do Cliente
            </button>
        </form>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        {{-- Estado do projeto --}}
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="text-sm font-semibold text-slate-900">Estado</h2>
            <form method="POST" action="{{ route('estudiomusica.projetos.estado', $projeto) }}" class="mt-2 flex gap-2">
                @csrf
                @method('PUT')
                <select name="estado" class="w-full rounded-md border-slate-300 text-sm">
                    @foreach ($estadosDisponiveis as $chave => $rotulo)
                        <option value="{{ $chave }}" @selected($projeto->estado === $chave)>{{ $rotulo }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Atualizar</button>
            </form>
        </div>

        {{-- Situação financeira --}}
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="text-sm font-semibold text-slate-900">Situação financeira</h2>
            <dl class="mt-2 space-y-1 text-sm text-slate-600">
                @if ($projeto->tipo_cobranca === 'pacote')
                    <div class="flex justify-between"><dt>Valor do pacote (sem IVA)</dt><dd>{{ number_format((float) $projeto->valor_pacote, 2) }} Kz</dd></div>
                    <div class="flex justify-between"><dt>Pacote com IVA</dt><dd>{{ number_format((float) $projeto->valor_pacote * (1 + (float) config('faturacao.taxa_iva_geral') / 100), 2) }} Kz</dd></div>
                @endif
                <div class="flex justify-between"><dt>Faturado</dt><dd>{{ number_format($projeto->valorTotalFaturado(), 2) }} Kz</dd></div>
                <div class="flex justify-between"><dt>Recebido</dt><dd>{{ number_format($projeto->valorTotalRecebido(), 2) }} Kz</dd></div>
                <div class="flex justify-between font-medium text-slate-900"><dt>Saldo devedor</dt><dd>{{ number_format($projeto->saldoDevedor(), 2) }} Kz</dd></div>
            </dl>
            <p class="mt-2 text-xs {{ $projeto->financeiramenteQuitado() ? 'text-emerald-700' : 'text-amber-700' }}">
                {{ $projeto->financeiramenteQuitado() ? 'Quitado — o cliente já pode descarregar os ficheiros finais.' : 'Ainda por quitar — o download fica bloqueado no Portal até aqui.' }}
            </p>
            @if ($projeto->tipo_cobranca === 'pacote')
                <div class="mt-3 flex gap-2">
                    <form method="POST" action="{{ route('estudiomusica.projetos.sinal', $projeto) }}">
                        @csrf
                        <button type="submit" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50">Emitir sinal</button>
                    </form>
                    <form method="POST" action="{{ route('estudiomusica.projetos.saldo-final', $projeto) }}">
                        @csrf
                        <button type="submit" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs hover:bg-slate-50">Emitir saldo final</button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    {{-- Faturas e pagamentos --}}
    <div class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="text-sm font-semibold text-slate-900">Faturas e pagamentos</h2>
        <div class="mt-2 divide-y divide-slate-100 text-sm">
            @forelse ($projeto->faturas->where('estado', 'emitida') as $fatura)
                @php
                    $recebido = (float) $projeto->recibos->where('estado', 'emitido')->where('fatura_id', $fatura->id)->sum('valor');
                    $emFalta = round((float) $fatura->valor_total - $recebido, 2);
                @endphp
                <div class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <div>
                        <p class="font-medium text-slate-900">{{ $fatura->numero_documento }}</p>
                        <p class="text-xs text-slate-500">{{ number_format((float) $fatura->valor_total, 2) }} Kz &middot; recebido {{ number_format($recebido, 2) }} Kz</p>
                    </div>
                    @if ($emFalta > 0)
                        <form method="POST" action="{{ route('estudiomusica.projetos.recibos', $projeto) }}" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <input type="hidden" name="fatura_id" value="{{ $fatura->id }}">
                            <select name="meio_pagamento" class="rounded-md border-slate-300 text-xs">
                                <option value="multicaixa">Multicaixa</option>
                                <option value="transferencia">Transferência</option>
                                <option value="dinheiro">Dinheiro</option>
                                <option value="outro">Outro</option>
                            </select>
                            <input type="number" step="0.01" min="0.01" max="{{ $emFalta }}" name="valor" placeholder="{{ number_format($emFalta, 2, '.', '') }}" class="w-28 rounded-md border-slate-300 text-xs" title="Deixa vazio para receber o que falta">
                            <button type="submit" class="rounded-md bg-slate-900 px-2.5 py-1 text-xs text-white hover:bg-slate-700">Registar pagamento</button>
                        </form>
                    @else
                        <span class="text-xs text-emerald-700">Paga</span>
                    @endif
                </div>
            @empty
                <p class="py-2 text-slate-500">Ainda sem faturas emitidas.</p>
            @endforelse
        </div>
    </div>

    {{-- Ficha do artista (e consentimento de marketing) --}}
    <div class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="text-sm font-semibold text-slate-900">Ficha do artista</h2>
        <form method="POST" action="{{ route('estudiomusica.clientes.perfil-artista', $projeto->cliente_id) }}" class="mt-2 grid gap-3 sm:grid-cols-3">
            @csrf
            @method('PUT')
            <input type="text" name="nome_artistico" value="{{ $projeto->cliente->nome_artistico }}" placeholder="Nome artístico" class="rounded-md border-slate-300 text-sm">
            <input type="text" name="genero_musical" value="{{ $projeto->cliente->genero_musical }}" placeholder="Género musical" class="rounded-md border-slate-300 text-sm">
            <input type="date" name="data_nascimento" value="{{ $projeto->cliente->data_nascimento ? \Illuminate\Support\Carbon::parse($projeto->cliente->data_nascimento)->format('Y-m-d') : '' }}" max="{{ now()->subDay()->toDateString() }}" class="rounded-md border-slate-300 text-sm" title="Data de nascimento (usada só na mensagem de aniversário)">
            <label class="flex items-start gap-2 text-xs text-slate-600 sm:col-span-3">
                <input type="hidden" name="aceita_marketing" value="0">
                <input type="checkbox" name="aceita_marketing" value="1" @checked($projeto->cliente->aceita_marketing) class="mt-0.5 rounded border-slate-300">
                <span>
                    O artista aceitou receber mensagens promocionais (aniversário, novidades, campanhas).
                    @if ($projeto->cliente->aceita_marketing && $projeto->cliente->aceita_marketing_em)
                        <span class="text-slate-400">Registado em {{ \Illuminate\Support\Carbon::parse($projeto->cliente->aceita_marketing_em)->format('d/m/Y') }}.</span>
                    @endif
                </span>
            </label>
            <div class="sm:col-span-3">
                <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Guardar ficha</button>
            </div>
        </form>
    </div>

    {{-- Faixas e versões --}}
    <div class="rounded-lg border border-slate-200 bg-white p-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-900">Faixas</h2>
            <form method="POST" action="{{ route('estudiomusica.projetos.faixas.store', $projeto) }}" class="flex gap-2">
                @csrf
                <input type="text" name="nome" placeholder="Nome da faixa" required class="rounded-md border-slate-300 text-xs">
                <button type="submit" class="rounded-md bg-slate-900 px-2.5 py-1 text-xs text-white hover:bg-slate-700">Adicionar faixa</button>
            </form>
        </div>
        <div class="mt-3 space-y-4">
            @forelse ($projeto->faixas as $faixa)
                <div class="rounded-md border border-slate-100 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-medium text-slate-900">{{ $faixa->nome }}</p>
                        <form method="POST" action="{{ route('estudiomusica.faixas.estado', $faixa) }}">
                            @csrf
                            @method('PUT')
                            <select name="estado" onchange="this.form.submit()" class="rounded-md border-slate-300 text-xs">
                                @foreach ($estadosDisponiveis as $chave => $rotulo)
                                    <option value="{{ $chave }}" @selected($faixa->estado === $chave)>{{ $rotulo }}</option>
                                @endforeach
                            </select>
                        </form>
                    </div>
                    <ul class="mt-2 space-y-1 text-xs text-slate-600">
                        @forelse ($faixa->versoes as $versao)
                            <li class="flex items-center justify-between">
                                <span>{{ $versao->rotulo }} — {{ $versao->estado }} ({{ $versao->marcadores->count() }} comentário(s))</span>
                                <a href="{{ route('estudiomusica.versoes.reproduzir', $versao) }}" class="underline">ouvir</a>
                            </li>
                        @empty
                            <li class="text-slate-400">Sem versões enviadas.</li>
                        @endforelse
                    </ul>
                    <form method="POST" action="{{ route('estudiomusica.faixas.versoes.store', $faixa) }}" enctype="multipart/form-data" class="mt-2 flex flex-wrap gap-2">
                        @csrf
                        <input type="text" name="rotulo" placeholder="Ex.: Mix v1" required class="rounded-md border-slate-300 text-xs">
                        <input type="file" name="ficheiro" required class="text-xs">
                        <button type="submit" class="rounded-md bg-slate-900 px-2.5 py-1 text-xs text-white hover:bg-slate-700">Enviar versão</button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-slate-500">Ainda sem faixas.</p>
            @endforelse
        </div>
    </div>

    {{-- Sessões deste projeto --}}
    <div class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="text-sm font-semibold text-slate-900">Sessões</h2>
        <ul class="mt-2 divide-y divide-slate-100 text-sm">
            @forelse ($projeto->sessoes as $sessao)
                <li class="flex items-center justify-between py-2">
                    <span>{{ $sessao->salaEstudio->nome }} &middot; {{ $sessao->inicio_previsto->format('d/m/Y H:i') }} &middot; {{ $sessao->rotuloTipoServico() }}</span>
                    <span class="text-xs text-slate-500">{{ $sessao->estado }}</span>
                </li>
            @empty
                <li class="py-2 text-slate-500">Ainda sem sessões agendadas para este projeto.</li>
            @endforelse
        </ul>
        <a href="{{ route('estudiomusica.sessoes.index') }}" class="mt-2 inline-block text-xs text-indigo-600 underline">Agendar nova sessão &rarr;</a>
    </div>
</div>
@endsection
