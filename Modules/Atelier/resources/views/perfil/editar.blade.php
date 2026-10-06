@extends('core::layouts.app')

@push('estilos')
    @vite(['Modules/Atelier/resources/css/app.css'])
@endpush

@section('titulo', 'Perfil Público')

@section('conteudo')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900">Perfil Público do Atelier</h1>
        @if ($perfil->exists && $perfil->publicado && $empresa->slug)
            <a href="{{ route('atelier.landing.mostrar', $empresa) }}" target="_blank" class="text-sm text-indigo-600">
                Ver página pública →
            </a>
        @endif
    </div>

    <form method="POST" action="{{ route('atelier.perfil.atualizar') }}" class="space-y-6 rounded-md border border-slate-200 bg-white p-6"
          x-data="perfilForm({{ json_encode($perfil->equipa ?? []) }})">
        @csrf
        @method('PUT')

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">História do atelier</label>
            <textarea name="historia" rows="4" class="w-full rounded-md border-slate-300 text-sm">{{ old('historia', $perfil->historia) }}</textarea>
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium text-slate-600">Especialidades</label>
            <textarea name="especialidades" rows="2" class="w-full rounded-md border-slate-300 text-sm"
                      placeholder="Ex.: Vestidos de noiva, fatos à medida, ajustes rápidos...">{{ old('especialidades', $perfil->especialidades) }}</textarea>
        </div>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <label class="block text-xs font-medium text-slate-600">Equipa</label>
                <button type="button" @click="membros.push({nome: '', funcao: ''})" class="text-xs font-medium text-indigo-600">+ Adicionar membro</button>
            </div>
            <template x-for="(membro, indice) in membros" :key="indice">
                <div class="mb-2 grid grid-cols-12 gap-2">
                    <input type="text" :name="`equipa[${indice}][nome]`" x-model="membro.nome" placeholder="Nome" class="col-span-5 rounded-md border-slate-300 text-sm">
                    <input type="text" :name="`equipa[${indice}][funcao]`" x-model="membro.funcao" placeholder="Função" class="col-span-6 rounded-md border-slate-300 text-sm">
                    <button type="button" @click="membros.splice(indice, 1)" class="col-span-1 text-red-500">✕</button>
                </div>
            </template>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Instagram</label>
                <input type="text" name="redes_sociais[instagram]" value="{{ old('redes_sociais.instagram', $perfil->redes_sociais['instagram'] ?? '') }}" class="w-full rounded-md border-slate-300 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Facebook</label>
                <input type="text" name="redes_sociais[facebook]" value="{{ old('redes_sociais.facebook', $perfil->redes_sociais['facebook'] ?? '') }}" class="w-full rounded-md border-slate-300 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">WhatsApp</label>
                <input type="text" name="redes_sociais[whatsapp]" value="{{ old('redes_sociais.whatsapp', $perfil->redes_sociais['whatsapp'] ?? '') }}" class="w-full rounded-md border-slate-300 text-sm">
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="publicado" value="1" @checked(old('publicado', $perfil->publicado)) class="rounded border-slate-300">
            Publicar página pública
            @if ($empresa->slug)
                (visível em /loja/{{ $empresa->slug }})
            @else
                (o endereço é gerado ao guardar)
            @endif
        </label>

        <div class="flex justify-end">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Guardar perfil
            </button>
        </div>
    </form>

    <p class="mt-4 text-xs text-slate-400">
        Para adicionar fotos ao portfólio, usa a página <a href="{{ route('atelier.portfolio.index') }}" class="text-indigo-600">Portfólio</a>.
    </p>

    <script>
        function perfilForm(equipaInicial) {
            return { membros: equipaInicial && equipaInicial.length ? equipaInicial : [] };
        }
    </script>
@endsection
