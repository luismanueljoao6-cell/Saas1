@extends('core::layouts.app')

@section('titulo', 'Reclamações')

@section('conteudo')
    <h1 class="mb-1 text-lg font-semibold text-slate-900">Reclamações</h1>
    <p class="mb-6 text-sm text-slate-500">
        Reclamações recebidas pela página pública. Não substituem o Livro de Reclamações físico, que deve estar
        sempre disponível no estabelecimento.
    </p>

    <div class="space-y-3">
        @forelse ($reclamacoes as $reclamacao)
            <div class="rounded-md border border-slate-200 bg-white p-4 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="font-medium text-slate-900">#{{ $reclamacao->id }} &middot; {{ $reclamacao->assunto }}</p>
                    <span class="text-xs text-slate-500">{{ $reclamacao->created_at->format('d/m/Y H:i') }}</span>
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ $reclamacao->nome }} &middot; {{ $reclamacao->email ?: '—' }} &middot; {{ $reclamacao->telefone ?: '—' }}</p>
                <p class="mt-2 whitespace-pre-line text-slate-700">{{ $reclamacao->descricao }}</p>

                <form method="POST" action="{{ route('estudiomusica.reclamacoes.atualizar', $reclamacao->id) }}" class="mt-3 space-y-2">
                    @csrf
                    @method('PUT')
                    <textarea name="resposta" rows="2" placeholder="Resposta ao cliente" maxlength="3000" class="w-full rounded-md border-slate-300 text-sm">{{ $reclamacao->resposta }}</textarea>
                    <div class="flex items-center gap-2">
                        <select name="estado" class="rounded-md border-slate-300 text-xs">
                            @foreach (['recebida' => 'Recebida', 'em_analise' => 'Em análise', 'resolvida' => 'Resolvida'] as $valor => $rotulo)
                                <option value="{{ $valor }}" @selected($reclamacao->estado === $valor)>{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="rounded-md bg-slate-900 px-3 py-1 text-xs text-white hover:bg-slate-700">Guardar / responder</button>
                    </div>
                </form>
            </div>
        @empty
            <p class="text-sm text-slate-500">Ainda sem reclamações.</p>
        @endforelse
    </div>

    <div class="mt-4">{{ $reclamacoes->links() }}</div>
@endsection
