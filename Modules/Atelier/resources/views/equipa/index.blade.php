@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Equipa do Atelier')

@section('conteudo')
    <h1 class="mb-1 text-lg font-semibold text-slate-900">Equipa do Atelier</h1>
    <p class="mb-6 text-sm text-slate-500">
        Atribui os papéis de Secretária/Costureira a utilizadores já existentes da tua empresa.
        Para criar um novo utilizador, contacta o suporte — a gestão de convites de equipa ainda não está disponível no Core.
    </p>

    <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">Utilizador</th>
                    <th class="px-4 py-3">Papéis atuais</th>
                    <th class="px-4 py-3">Atribuir</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($utilizadores as $utilizador)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-800">{{ $utilizador->name }}</div>
                            <div class="text-xs text-slate-500">{{ $utilizador->email }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @forelse ($utilizador->roles as $papel)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                                        {{ $papel->name }}
                                        @if (in_array($papel->name, [$papelSecretaria, $papelCostureira]))
                                            <form method="POST" action="{{ route('atelier.equipa.remover') }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="utilizador_id" value="{{ $utilizador->id }}">
                                                <input type="hidden" name="papel" value="{{ $papel->name }}">
                                                <button type="submit" class="text-slate-400 hover:text-red-600">✕</button>
                                            </form>
                                        @endif
                                    </span>
                                @empty
                                    <span class="text-xs text-slate-400">Sem papel atribuído</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('atelier.equipa.atribuir') }}" class="flex gap-2">
                                @csrf
                                <input type="hidden" name="utilizador_id" value="{{ $utilizador->id }}">
                                <select name="papel" class="rounded-md border-slate-300 text-xs">
                                    <option value="{{ $papelSecretaria }}">{{ $papelSecretaria }}</option>
                                    <option value="{{ $papelCostureira }}">{{ $papelCostureira }}</option>
                                </select>
                                <button type="submit" class="rounded-md bg-indigo-600 px-2 py-1 text-xs font-medium text-white hover:bg-indigo-500">
                                    Atribuir
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
