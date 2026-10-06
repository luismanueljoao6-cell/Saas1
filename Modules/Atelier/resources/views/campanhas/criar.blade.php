@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Nova Campanha')

@section('conteudo')
    <h1 class="mb-6 text-lg font-semibold text-slate-900">Nova Campanha</h1>

    <form method="POST" action="{{ route('atelier.campanhas.guardar') }}" class="space-y-4 rounded-md border border-slate-200 bg-white p-6">
        @csrf

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Nome interno *</label>
            <input type="text" name="nome" required value="{{ old('nome') }}" class="w-full rounded-md border-slate-300 text-sm">
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Canal *</label>
                <select name="canal" required class="w-full rounded-md border-slate-300 text-sm">
                    <option value="mail">E-mail</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="sms">SMS</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Segmento *</label>
                <select name="segmento" required class="w-full rounded-md border-slate-300 text-sm">
                    <option value="todos">Todos os clientes</option>
                    <option value="aniversariantes_mes">Aniversariantes do mês</option>
                    <option value="inativos">Clientes inativos (sem pedidos há {{ config('atelier.meses_inatividade_reativacao') }}+ meses)</option>
                </select>
            </div>
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Assunto (usado só no canal E-mail)</label>
            <input type="text" name="assunto" value="{{ old('assunto') }}" class="w-full rounded-md border-slate-300 text-sm">
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Mensagem *</label>
            <textarea name="mensagem" required rows="4" class="w-full rounded-md border-slate-300 text-sm">{{ old('mensagem') }}</textarea>
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Agendar para (opcional — vazio envia manualmente depois)</label>
            <input type="datetime-local" name="agendada_para" value="{{ old('agendada_para') }}" class="w-full rounded-md border-slate-300 text-sm">
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('atelier.campanhas.index') }}" class="text-sm text-slate-500">Cancelar</a>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Criar campanha
            </button>
        </div>
    </form>
@endsection
