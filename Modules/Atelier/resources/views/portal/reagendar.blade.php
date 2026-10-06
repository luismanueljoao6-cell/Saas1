@extends('atelier::layouts.publico')

@section('titulo', 'Pedir outro horário')

@section('conteudo')
    <h1 class="mb-1 text-lg font-semibold text-slate-900">Pedir outro horário</h1>
    <p class="mb-6 text-sm text-slate-500">
        {{ $prova->tipoRotulo() }} marcada para {{ $prova->data_hora_agendada->format('d/m/Y \à\s H:i') }}.
        Indica o horário que te convém melhor — o atelier confirma contigo em breve.
    </p>

    {{-- A action reutiliza a MESMA URL assinada com que esta página foi aberta: a
         validação de assinatura do Laravel não depende do verbo HTTP, por isso o
         mesmo link serve para o GET (mostrar) e para o POST (submeter). --}}
    <form method="POST" action="{{ request()->fullUrl() }}" class="space-y-4 rounded-md border border-slate-200 bg-white p-6">
        @csrf

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Novo dia e hora *</label>
            <input type="datetime-local" name="nova_data_hora" required value="{{ old('nova_data_hora') }}"
                   class="w-full rounded-md border-slate-300 text-sm">
        </div>

        <button type="submit" class="w-full rounded-md bg-indigo-600 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            Enviar pedido
        </button>
    </form>
@endsection
