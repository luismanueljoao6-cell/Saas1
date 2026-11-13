@extends('core::layouts.app')

@section('titulo', 'Definições da empresa')

@section('conteudo')
    <div class="w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Definições da empresa</h1>
            <a href="{{ route('core.painel') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">&larr; Painel</a>
        </div>
        <p class="mt-1 text-sm text-slate-500">Estes dados são usados nos documentos fiscais emitidos pela plataforma — confirma-os com atenção.</p>

        @if ($errors->any())
            <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $erro)
                        <li>{{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('core.empresa.atualizar', $empresa) }}" class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            @method('PUT')

            <div class="sm:col-span-2">
                <label for="nome_comercial" class="block text-sm font-medium text-slate-700">Nome comercial</label>
                <input id="nome_comercial" name="nome_comercial" value="{{ old('nome_comercial', $empresa->nome_comercial) }}" required
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div class="sm:col-span-2">
                <label for="nome_legal" class="block text-sm font-medium text-slate-700">Nome legal / razão social</label>
                <input id="nome_legal" name="nome_legal" value="{{ old('nome_legal', $empresa->nome_legal) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="nif" class="block text-sm font-medium text-slate-700">NIF</label>
                <input id="nif" name="nif" value="{{ old('nif', $empresa->nif) }}" required
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="regime_fiscal" class="block text-sm font-medium text-slate-700">Regime fiscal</label>
                <input id="regime_fiscal" name="regime_fiscal" value="{{ old('regime_fiscal', $empresa->regime_fiscal) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div class="sm:col-span-2">
                <label for="morada" class="block text-sm font-medium text-slate-700">Morada</label>
                <input id="morada" name="morada" value="{{ old('morada', $empresa->morada) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="municipio" class="block text-sm font-medium text-slate-700">Município</label>
                <input id="municipio" name="municipio" value="{{ old('municipio', $empresa->municipio) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="provincia" class="block text-sm font-medium text-slate-700">Província</label>
                <input id="provincia" name="provincia" value="{{ old('provincia', $empresa->provincia) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="telefone" class="block text-sm font-medium text-slate-700">Telefone</label>
                <input id="telefone" name="telefone" value="{{ old('telefone', $empresa->telefone) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-slate-700">E-mail</label>
                <input id="email" type="email" name="email" value="{{ old('email', $empresa->email) }}"
                       class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            </div>

            @php
                $fiscalEmpresa = (array) data_get($empresa->configuracoes, 'faturacao', []);
                $catalogoMotivos = collect((array) config('faturacao.motivos_isencao', []))
                    ->filter(fn ($motivo) => in_array($motivo['tipo'] ?? null, ['ISE', 'NS'], true));
                $motivosAtivos = old('configuracoes_fiscais.motivos_isencao_ativos', $fiscalEmpresa['motivos_isencao_ativos'] ?? array_keys($catalogoMotivos->all()));
            @endphp
            <section class="sm:col-span-2 mt-4 rounded-xl border border-indigo-200 bg-indigo-50/50 p-5">
                <h2 class="text-base font-semibold text-slate-900">Configuração fiscal da empresa</h2>
                <p class="mt-1 text-sm text-slate-600">Estas preferências são independentes das outras empresas. Confirma o enquadramento com o contabilista antes de emitir documentos fiscais.</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="regime_iva" class="block text-sm font-medium text-slate-700">Regime de IVA</label>
                        <select id="regime_iva" name="configuracoes_fiscais[regime_iva]" class="mt-1 w-full rounded-md border-slate-300">
                            @foreach(['geral' => 'Regime Geral', 'simplificado' => 'Regime Simplificado', 'exclusao' => 'Regime de Exclusão', 'transitorio' => 'Regime Transitório'] as $codigo => $rotulo)
                                <option value="{{ $codigo }}" @selected(old('configuracoes_fiscais.regime_iva', $fiscalEmpresa['regime_iva'] ?? 'geral') === $codigo)>{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="taxa_iva_padrao" class="block text-sm font-medium text-slate-700">Taxa padrão de IVA (%)</label>
                        <input id="taxa_iva_padrao" name="configuracoes_fiscais[taxa_iva_padrao]" type="number" min="0" max="30" step="0.01" required value="{{ old('configuracoes_fiscais.taxa_iva_padrao', $fiscalEmpresa['taxa_iva_padrao'] ?? config('faturacao.taxa_iva_geral')) }}" class="mt-1 w-full rounded-md border-slate-300">
                        <p class="mt-1 text-xs text-slate-500">A taxa é uma preferência inicial; as taxas permitidas continuam limitadas pela configuração validada do sistema.</p>
                    </div>
                </div>
                <fieldset class="mt-4">
                    <legend class="text-sm font-medium text-slate-700">Motivos de isenção/não sujeição disponíveis</legend>
                    <p class="mb-2 text-xs text-slate-500">A seleção apenas controla o que aparece nos formulários desta empresa; não altera a tabela legal central.</p>
                    <div class="grid max-h-64 gap-2 overflow-y-auto sm:grid-cols-2">
                        @foreach($catalogoMotivos as $codigo => $motivo)
                            <label class="flex items-start gap-2 rounded-md bg-white p-2 text-xs text-slate-700">
                                <input type="checkbox" name="configuracoes_fiscais[motivos_isencao_ativos][]" value="{{ $codigo }}" @checked(in_array($codigo, $motivosAtivos, true)) class="mt-0.5 rounded border-slate-300">
                                <span><strong>{{ $codigo }}</strong> — {{ $motivo['mencao'] ?? $codigo }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            </section>

            <div class="sm:col-span-2 pt-2">
                <button type="submit"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Guardar alterações
                </button>
            </div>
        </form>
    </div>
@endsection
