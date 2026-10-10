@extends('core::layouts.app')

@section('titulo', 'Serviços')

@section('conteudo')
    @php($ativos = $empresa->servicosAderidos()->map(fn ($servico) => $servico->value)->all())

    <div class="w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Os meus serviços</h1>
        <p class="mt-1 text-sm text-slate-500">
            Escolhe os serviços que a tua empresa usa. A Faturação está sempre incluída,
            porque todos os outros serviços dependem dela.
        </p>
        <p class="mt-1 text-sm text-slate-500">
            Se removeres um serviço, deixas de lhe aceder na área de gestão, mas os dados
            ficam guardados — se voltares a aderir, recuperas tudo.
        </p>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('core.servicos.atualizar') }}" class="mt-6 space-y-3"
              x-data="{ sel: @js(array_values((array) old('servicos', $ativos))) }">
            @csrf
            @method('PUT')

            @foreach (\Modules\Core\Support\Servico::cases() as $servico)
                @if ($servico === \Modules\Core\Support\Servico::Faturacao)
                    <div class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                        <input type="checkbox" checked disabled class="mt-0.5 rounded border-slate-300">
                        <input type="hidden" name="servicos[]" value="{{ $servico->value }}">
                        <span>
                            <span class="block font-medium text-slate-800">{{ $servico->rotulo() }}</span>
                            <span class="block text-slate-500">{{ $servico->descricao() }}</span>
                            <span class="mt-1 block text-xs text-slate-500">Sempre incluída.</span>
                        </span>
                    </div>
                @else
                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 text-sm hover:border-indigo-300">
                        <input type="checkbox" name="servicos[]" value="{{ $servico->value }}" x-model="sel"
                               class="mt-0.5 rounded border-slate-300">
                        <span>
                            <span class="block font-medium text-slate-800">{{ $servico->rotulo() }}</span>
                            <span class="block text-slate-500">{{ $servico->descricao() }}</span>
                        </span>
                    </label>
                @endif
            @endforeach

            <button type="submit"
                    class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                Guardar serviços
            </button>
        </form>
    </div>
@endsection
