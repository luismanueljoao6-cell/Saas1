@extends('core::layouts.app')

@section('titulo', 'Equipa do Estúdio')

@section('conteudo')
    <h1 class="mb-1 text-lg font-semibold text-slate-900">Equipa do Estúdio</h1>
    <p class="mb-6 text-sm text-slate-500">
        Atribui os papéis de Receção e Engenheiro a utilizadores ativos da tua empresa.
        Para criar um novo utilizador, usa a página Utilizadores.
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
                                        @if (in_array($papel->name, $papeis, true))
                                            <form method="POST" action="{{ route('estudiomusica.equipa.remover') }}" class="inline">
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
                            <form method="POST" action="{{ route('estudiomusica.equipa.atribuir') }}" class="flex gap-2">
                                @csrf
                                <input type="hidden" name="utilizador_id" value="{{ $utilizador->id }}">
                                <select name="papel" class="rounded-md border-slate-300 text-xs">
                                    @foreach ($papeis as $papelDisponivel)
                                        <option value="{{ $papelDisponivel }}">{{ $papelDisponivel }}</option>
                                    @endforeach
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
