@extends('core::layouts.app')

@section('titulo', 'Sessões de Estúdio')

@section('conteudo')
@include('estudiomusica::partials.erros')
<div x-data="{ aFormar: {{ $projetoPreSelecionado || $errors->any() || old('sala_estudio_id') ? 'true' : 'false' }} }" class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900 dark:text-zinc-100">Sessões de Estúdio</h1>
        <button type="button" @click="aFormar = !aFormar" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
            Agendar sessão
        </button>
    </div>

    <form x-show="aFormar" x-cloak method="POST" action="{{ route('estudiomusica.sessoes.store') }}"
          class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-3 dark:bg-zinc-900 dark:border-zinc-800">
        @csrf
        <select name="sala_estudio_id" required class="rounded-md border-slate-300 text-sm dark:border-zinc-700">
            <option value="">Sala...</option>
            @foreach ($salas as $sala)
                <option value="{{ $sala->id }}" @selected((int) old('sala_estudio_id') === $sala->id)>{{ $sala->nome }}</option>
            @endforeach
        </select>
        <select name="cliente_id" required class="rounded-md border-slate-300 text-sm dark:border-zinc-700">
            <option value="">Artista/Cliente...</option>
            @foreach ($clientes as $cliente)
                <option value="{{ $cliente->id }}" @selected((int) old('cliente_id', $projetoPreSelecionado?->cliente_id) === $cliente->id)>{{ $cliente->nome_artistico ?: $cliente->nome }}</option>
            @endforeach
        </select>
        <select name="tipo_servico" required class="rounded-md border-slate-300 text-sm dark:border-zinc-700">
            <option value="">Tipo de serviço...</option>
            @foreach (config('estudiomusica.tipos_servico') as $chave => $rotulo)
                <option value="{{ $chave }}" @selected(old('tipo_servico') === $chave)>{{ $rotulo }}</option>
            @endforeach
        </select>
        {{-- Sem esta ligação sessão→projeto, as horas nunca chegavam ao projeto (nem à sua quitação). --}}
        <select name="projeto_musical_id" class="rounded-md border-slate-300 text-sm dark:border-zinc-700" title="Tem de ser um projeto do cliente escolhido">
            <option value="">Sem projeto (sessão avulsa)</option>
            @foreach ($projetos as $projeto)
                <option value="{{ $projeto->id }}" @selected((int) old('projeto_musical_id', $projetoPreSelecionado?->id) === $projeto->id)>
                    {{ $projeto->cliente->nome_artistico ?: $projeto->cliente->nome }} — {{ $projeto->nome }}
                </option>
            @endforeach
        </select>
        <select name="engenheiro_user_id" class="rounded-md border-slate-300 text-sm dark:border-zinc-700">
            <option value="">Engenheiro (opcional)...</option>
            @foreach ($membrosEquipa as $membro)
                <option value="{{ $membro->id }}" @selected((int) old('engenheiro_user_id') === $membro->id)>{{ $membro->name }}</option>
            @endforeach
        </select>
        <div></div>
        <input type="datetime-local" name="inicio_previsto" value="{{ old('inicio_previsto') }}" required class="rounded-md border-slate-300 text-sm dark:border-zinc-700">
        <input type="datetime-local" name="fim_previsto" value="{{ old('fim_previsto') }}" required class="rounded-md border-slate-300 text-sm dark:border-zinc-700">
        <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
            Confirmar
        </button>
    </form>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:bg-zinc-900 dark:border-zinc-800">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-zinc-800">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500 dark:bg-zinc-950 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-2">Quando</th>
                    <th class="px-4 py-2">Sala</th>
                    <th class="px-4 py-2">Cliente / Projeto</th>
                    <th class="px-4 py-2">Serviço</th>
                    <th class="px-4 py-2">Estado</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                @forelse ($sessoes as $sessao)
                    @php
                        $porPacote = $sessao->projetoMusical && $sessao->projetoMusical->tipo_cobranca === 'pacote';
                        $faturavel = $sessao->estado === 'concluida' && ! $sessao->fatura_id && ! $porPacote && (float) $sessao->salaEstudio->preco_hora > 0;
                    @endphp
                    <tr class="align-top">
                        <td class="px-4 py-2 text-slate-600 dark:text-zinc-300">{{ $sessao->inicio_previsto->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2 text-slate-600 dark:text-zinc-300">{{ $sessao->salaEstudio->nome }}</td>
                        <td class="px-4 py-2 text-slate-600 dark:text-zinc-300">
                            {{ $sessao->cliente->nome_artistico ?: $sessao->cliente->nome }}
                            @if ($sessao->projetoMusical)
                                <span class="block text-xs text-slate-400">{{ $sessao->projetoMusical->nome }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-slate-600 dark:text-zinc-300">
                            {{ $sessao->rotuloTipoServico() }}
                            @if ($sessao->engenheiro)
                                <span class="block text-xs text-slate-400">{{ $sessao->engenheiro->name }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-slate-600 dark:text-zinc-300">
                            {{ $sessao->rotuloEstado() }}
                            @if ($faturavel)
                                <span class="block text-xs text-amber-700">Sem fatura</span>
                            @endif
                        </td>
                        <td class="space-y-1 px-4 py-2 text-right">
                            @if ($sessao->estado === 'agendada' || $sessao->estado === 'confirmada')
                                <form method="POST" action="{{ route('estudiomusica.sessoes.check-in', $sessao) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-xs text-indigo-600 underline">check-in</button>
                                </form>
                                <form method="POST" action="{{ route('estudiomusica.sessoes.cancelar', $sessao) }}" class="inline"
                                      onsubmit="return confirm('Cancelar esta sessão?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ml-2 text-xs text-red-600 underline">Cancelar</button>
                                </form>
                                <details class="text-left">
                                    <summary class="cursor-pointer text-right text-xs text-indigo-600 underline">Reagendar</summary>
                                    <form method="POST" action="{{ route('estudiomusica.sessoes.reagendar', $sessao) }}" class="mt-1 flex flex-wrap justify-end gap-1">
                                        @csrf
                                        @method('PUT')
                                        <input type="datetime-local" name="inicio_previsto" value="{{ $sessao->inicio_previsto->format('Y-m-d\TH:i') }}" required class="rounded-md border-slate-300 text-xs dark:border-zinc-700">
                                        <input type="datetime-local" name="fim_previsto" value="{{ $sessao->fim_previsto->format('Y-m-d\TH:i') }}" required class="rounded-md border-slate-300 text-xs dark:border-zinc-700">
                                        <button type="submit" class="rounded-md bg-slate-900 px-2 py-1 text-xs text-white hover:bg-slate-700">Guardar</button>
                                    </form>
                                </details>
                            @elseif ($sessao->estado === 'em_curso')
                                <form method="POST" action="{{ route('estudiomusica.sessoes.check-out', $sessao) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-xs text-indigo-600 underline">check-out</button>
                                </form>
                            @elseif ($faturavel)
                                <form method="POST" action="{{ route('estudiomusica.sessoes.faturar', $sessao) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-xs text-indigo-600 underline">Faturar</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500 dark:text-zinc-400">Ainda sem sessões agendadas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $sessoes->links() }}
</div>
@endsection
