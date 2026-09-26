@extends('core::layouts.app')

@section('titulo', 'Exportar SAF-T (AO)')

@section('conteudo')
    <div class="rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Exportar SAF-T (AO)</h1>
        <p class="mt-1 text-sm text-slate-500">Gera o ficheiro para o período indicado. Corre em segundo plano — não precisas de esperar nesta página.</p>

        @if (session('sucesso'))
            <div class="mt-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">{{ session('sucesso') }}</div>
        @endif
        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('faturacao.saft.despachar') }}" class="mt-6 space-y-4">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="data_inicio" class="block text-sm font-medium text-slate-700">De</label>
                    <input type="date" name="data_inicio" id="data_inicio" required
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="data_fim" class="block text-sm font-medium text-slate-700">Até</label>
                    <input type="date" name="data_fim" id="data_fim" required
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>

            <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                Gerar SAF-T (AO)
            </button>
        </form>

        <p class="mt-6 text-xs text-slate-400">
            Lê o README deste módulo antes de submeteres um ficheiro real à AGT — a estrutura gerada
            precisa de ser validada contra a especificação técnica atual.
        </p>
    </div>
@endsection
