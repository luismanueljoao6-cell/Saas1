@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Marketing — ' . $cliente->nome)

@section('conteudo')
    <div class="mb-6">
        <h1 class="text-lg font-semibold text-slate-900">Marketing e consentimento — {{ $cliente->nome }}</h1>
        <p class="text-sm text-slate-500">
            Só enviamos campanhas e mensagens de aniversário a clientes que deram consentimento.
            Regista aqui como e quando o cliente o deu.
        </p>
    </div>

    @if (session('sucesso'))
        <div class="mb-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
            @foreach ($errors->all() as $erro)
                <p>{{ $erro }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('atelier.crm.atualizar', $cliente->id) }}"
          class="max-w-lg space-y-4 rounded-md border border-slate-200 bg-white p-5">
        @csrf
        @method('PUT')

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Data de nascimento</label>
            <input type="date" name="data_nascimento" max="{{ now()->subDay()->format('Y-m-d') }}"
                   value="{{ old('data_nascimento', $crm?->data_nascimento?->format('Y-m-d')) }}"
                   class="w-full rounded-md border-slate-300 text-sm">
        </div>

        <div class="space-y-2">
            <input type="hidden" name="aceita_marketing" value="0">
            <label class="flex items-start gap-2 text-sm text-slate-700">
                <input type="checkbox" name="aceita_marketing" value="1" class="mt-0.5 rounded border-slate-300"
                       @checked((string) old('aceita_marketing', $crm?->temConsentimentoValido() ? '1' : '0') === '1')>
                <span>O cliente consente em receber comunicações de marketing (campanhas e mensagens de aniversário).</span>
            </label>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Como foi obtido o consentimento? *</label>
                <input type="text" name="consentimento_origem" maxlength="255"
                       placeholder="Ex.: ficha em papel assinada, mensagem de WhatsApp, presencialmente"
                       value="{{ old('consentimento_origem', $crm?->consentimento_origem) }}"
                       class="w-full rounded-md border-slate-300 text-sm">
            </div>

            @if ($crm?->consentimento_em)
                <p class="text-xs text-slate-400">Consentimento registado em {{ $crm->consentimento_em->format('d/m/Y H:i') }}.</p>
            @endif
            @if ($crm?->consentimento_revogado_em)
                <p class="text-xs text-amber-600">Consentimento revogado em {{ $crm->consentimento_revogado_em->format('d/m/Y H:i') }}.</p>
            @endif
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Notas</label>
            <textarea name="notas" rows="3" maxlength="2000" class="w-full rounded-md border-slate-300 text-sm">{{ old('notas', $crm?->notas) }}</textarea>
        </div>

        <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            Guardar
        </button>
    </form>
@endsection
