@extends('core::layouts.app')

@section('titulo', 'Sessões de Estúdio')

@section('conteudo')
<div x-data="{ aFormar: false }" class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900">Sessões de Estúdio</h1>
        <button type="button" @click="aFormar = !aFormar" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
            Agendar sessão
        </button>
    </div>

    <form x-show="aFormar" x-cloak method="POST" action="{{ route('estudiomusica.sessoes.store') }}"
          class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-3">
        @csrf
        <select name="sala_estudio_id" required class="rounded-md border-slate-300 text-sm">
            <option value="">Sala...</option>
            @foreach ($salas as $sala)
                <option value="{{ $sala->id }}">{{ $sala->nome }}</option>
            @endforeach
        </select>
        <select name="cliente_id" required class="rounded-md border-slate-300 text-sm">
            <option value="">Artista/Cliente...</option>
            @foreach ($clientes as $cliente)
                <option value="{{ $cliente->id }}">{{ $cliente->nome_artistico ?: $cliente->nome }}</option>
            @endforeach
        </select>
        <select name="tipo_servico" required class="rounded-md border-slate-300 text-sm">
            <option value="">Tipo de serviço...</option>
            @foreach (config('estudiomusica.tipos_servico') as $chave => $rotulo)
                <option value="{{ $chave }}">{{ $rotulo }}</option>
            @endforeach
        </select>
        <input type="datetime-local" name="inicio_previsto" required class="rounded-md border-slate-300 text-sm">
        <input type="datetime-local" name="fim_previsto" required class="rounded-md border-slate-300 text-sm">
        <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
            Confirmar
        </button>
    </form>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-2">Quando</th>
                    <th class="px-4 py-2">Sala</th>
                    <th class="px-4 py-2">Cliente</th>
                    <th class="px-4 py-2">Serviço</th>
                    <th class="px-4 py-2">Estado</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($sessoes as $sessao)
                    <tr>
                        <td class="px-4 py-2 text-slate-600">{{ $sessao->inicio_previsto->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ $sessao->salaEstudio->nome }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ $sessao->cliente->nome_artistico ?: $sessao->cliente->nome }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ $sessao->rotuloTipoServico() }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ $sessao->estado }}</td>
                        <td class="px-4 py-2 text-right">
                            @if ($sessao->estado === 'agendada' || $sessao->estado === 'confirmada')
                                <form method="POST" action="{{ route('estudiomusica.sessoes.check-in', $sessao) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-xs text-indigo-600 underline">check-in</button>
                                </form>
                            @elseif ($sessao->estado === 'em_curso')
                                <form method="POST" action="{{ route('estudiomusica.sessoes.check-out', $sessao) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-xs text-indigo-600 underline">check-out</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">Ainda sem sessões agendadas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $sessoes->links() }}
</div>
@endsection
