@extends('core::layouts.app')

@section('titulo', 'Novo Projeto Musical')

@section('conteudo')
<div class="max-w-2xl space-y-6">
    <h1 class="text-lg font-semibold text-slate-900">Novo Projeto Musical</h1>

    <form method="POST" action="{{ route('estudiomusica.projetos.store') }}" class="space-y-4 rounded-lg border border-slate-200 bg-white p-6">
        @csrf

        <div>
            <label class="block text-sm font-medium text-slate-700">Artista/Cliente</label>
            <select name="cliente_id" required class="mt-1 w-full rounded-md border-slate-300 text-sm">
                @foreach ($clientes as $cliente)
                    <option value="{{ $cliente->id }}">{{ $cliente->nome_artistico ?: $cliente->nome }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">
                Cliente novo? Cria-o primeiro em <a href="{{ route('faturacao.clientes.index') }}" class="underline">Faturação &rsaquo; Clientes</a> — a ficha de artista (género musical, integrantes, redes sociais) edita-se depois, a partir da página do projeto.
            </p>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700">Nome do projeto</label>
            <input type="text" name="nome" required placeholder='Ex.: "Álbum Raízes"' class="mt-1 w-full rounded-md border-slate-300 text-sm">
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700">Estilo</label>
                <input type="text" name="estilo" class="mt-1 w-full rounded-md border-slate-300 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Prazo de entrega</label>
                <input type="date" name="prazo_entrega" class="mt-1 w-full rounded-md border-slate-300 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">BPM</label>
                <input type="number" name="bpm" min="1" max="400" class="mt-1 w-full rounded-md border-slate-300 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Tom base</label>
                <input type="text" name="tom_base" placeholder="Ex.: Sol maior" class="mt-1 w-full rounded-md border-slate-300 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Produtor</label>
                <select name="produtor_user_id" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                    <option value="">—</option>
                    @foreach ($membrosEquipa as $membro)
                        <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Engenheiro de som</label>
                <select name="engenheiro_user_id" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                    <option value="">—</option>
                    @foreach ($membrosEquipa as $membro)
                        <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div x-data="{ cobranca: 'hora' }" class="space-y-3 border-t border-slate-100 pt-4">
            <label class="block text-sm font-medium text-slate-700">Modelo de cobrança</label>
            <div class="flex gap-4 text-sm">
                <label class="flex items-center gap-1.5"><input type="radio" name="tipo_cobranca" value="hora" x-model="cobranca" checked> Por hora</label>
                <label class="flex items-center gap-1.5"><input type="radio" name="tipo_cobranca" value="pacote" x-model="cobranca"> Pacote fechado</label>
            </div>
            <div x-show="cobranca === 'pacote'" x-cloak>
                <label class="block text-sm font-medium text-slate-700">Valor do pacote (Kz)</label>
                <input type="number" step="0.01" min="0" name="valor_pacote" class="mt-1 w-full rounded-md border-slate-300 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Sinal (%) — em branco usa a percentagem por omissão</label>
                <input type="number" step="0.01" min="0" max="100" name="percentual_sinal" class="mt-1 w-full rounded-md border-slate-300 text-sm">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700">Observações</label>
            <textarea name="observacoes" rows="3" class="mt-1 w-full rounded-md border-slate-300 text-sm"></textarea>
        </div>

        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            Criar projeto
        </button>
    </form>
</div>
@endsection
