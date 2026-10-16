@extends('core::layouts.app')

@section('titulo', 'Termos e Privacidade')

@section('conteudo')
    <h1 class="mb-1 text-lg font-semibold text-slate-900">Termos e Privacidade</h1>
    <p class="mb-6 text-sm text-slate-500">
        Os clientes aceitam os Termos de Serviço no Portal antes de aprovar ou descarregar ficheiros.
        A Política de Privacidade pública está em
        <a class="underline" href="{{ route('estudiomusica.landing.privacidade', $empresa->id) }}" target="_blank" rel="noopener">{{ route('estudiomusica.landing.privacidade', $empresa->id) }}</a>.
    </p>

    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
        O texto inicial é um <strong>modelo</strong>. Pede ao teu advogado para o validar e adaptar (direitos sobre os masters,
        cancelamentos, preços) antes de o publicares como versão definitiva.
    </div>

    <form method="POST" action="{{ route('estudiomusica.termos.publicar') }}" class="mb-8 space-y-3 rounded-md border border-slate-200 bg-white p-4">
        @csrf
        <h2 class="text-sm font-semibold text-slate-900">Publicar nova versão (a atual é a v{{ $atual->versao }})</h2>
        <input type="text" name="titulo" value="{{ old('titulo', $atual->titulo) }}" required maxlength="150" class="w-full rounded-md border-slate-300 text-sm">
        <textarea name="conteudo" rows="18" required minlength="100" class="w-full rounded-md border-slate-300 font-mono text-xs">{{ old('conteudo', $atual->conteudo) }}</textarea>
        @if ($errors->any())
            <p class="text-sm text-red-700">{{ $errors->first() }}</p>
        @endif
        <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Publicar nova versão</button>
        <p class="text-xs text-slate-500">Todos os clientes voltam a ter de aceitar.</p>
    </form>

    <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr><th class="px-4 py-3">Versão</th><th class="px-4 py-3">Título</th><th class="px-4 py-3">Publicada em</th><th class="px-4 py-3">Aceites</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($historico as $versao)
                    <tr>
                        <td class="px-4 py-2">v{{ $versao->versao }}</td>
                        <td class="px-4 py-2">{{ $versao->titulo }}</td>
                        <td class="px-4 py-2 text-slate-500">{{ $versao->publicado_em->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2">{{ $versao->aceites_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
