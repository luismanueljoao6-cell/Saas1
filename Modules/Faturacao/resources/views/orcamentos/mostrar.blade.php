@extends('core::layouts.app')
@section('titulo', 'Proforma '.$orcamento->numero)
@section('conteudo')
<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <a href="{{ route('faturacao.orcamentos.index') }}" class="text-sm text-indigo-600">← Voltar aos orçamentos</a>
        @if($orcamento->estado === 'rascunho')
            <a href="{{ route('faturacao.orcamentos.editar', $orcamento->id) }}" class="rounded-lg border px-4 py-2 text-sm">Editar orçamento</a>
        @endif
        <button type="button" onclick="window.print()" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Imprimir / Guardar PDF</button>
    </div>
    <article class="rounded-xl border border-slate-200 bg-white p-6 text-slate-900 shadow-sm sm:p-10">
        <header class="flex flex-wrap justify-between gap-6 border-b pb-6">
            <div>
                <h1 class="text-3xl font-bold">PROFORMA</h1>
                <p class="mt-2 text-sm text-slate-600">Documento comercial — não fiscal</p>
                <p class="mt-3 font-semibold">{{ $orcamento->numero }}</p>
                <p class="text-sm text-slate-600">Data: {{ $orcamento->created_at?->format('d/m/Y') }}</p>
                <p class="text-sm text-slate-600">Validade: {{ $orcamento->data_validade?->format('d/m/Y') ?? 'Não definida' }}</p>
            </div>
            <div class="text-sm sm:text-right">
                <h2 class="font-bold">{{ $empresa->nome_comercial ?? 'Empresa' }}</h2>
                @if($empresa->nif)<p>NIF: {{ $empresa->nif }}</p>@endif
            </div>
        </header>
        <section class="py-6">
            <h2 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Cliente</h2>
            <p class="font-semibold">{{ $orcamento->cliente?->nome ?? '—' }}</p>
            @if($orcamento->cliente?->nif)<p class="text-sm text-slate-600">NIF: {{ $orcamento->cliente->nif }}</p>@endif
        </section>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-y text-left"><th class="py-3 pr-3">Descrição</th><th class="px-2 py-3 text-right">Qtd.</th><th class="px-2 py-3 text-right">Preço unit.</th><th class="px-2 py-3 text-right">IVA</th><th class="py-3 pl-2 text-right">Total</th></tr></thead>
                <tbody>
                @foreach($linhas as $linha)
                    @php $taxa = (float) ($linha['taxa_iva'] ?? 0); @endphp
                    <tr class="border-b"><td class="py-3 pr-3">{{ $linha['descricao'] ?? '' }}</td><td class="px-2 py-3 text-right">{{ number_format((float)($linha['quantidade'] ?? 0), 3, ',', ' ') }}</td><td class="px-2 py-3 text-right">{{ number_format((float)($linha['preco_unitario'] ?? 0), 2, ',', ' ') }}</td><td class="px-2 py-3 text-right">{{ number_format($taxa, 2, ',', ' ') }}%</td><td class="py-3 pl-2 text-right">{{ number_format((float) ($linha['total_linha'] ?? 0), 2, ',', ' ') }} AOA</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="ml-auto mt-6 max-w-sm space-y-2 text-sm">
            <div class="flex justify-between"><span>Subtotal</span><strong>{{ number_format((float)$orcamento->valor_sem_iva, 2, ',', ' ') }} AOA</strong></div>
            <div class="flex justify-between"><span>IVA</span><strong>{{ number_format((float)$orcamento->valor_iva, 2, ',', ' ') }} AOA</strong></div>
            <div class="flex justify-between border-t pt-3 text-lg"><span>Total</span><strong>{{ number_format((float)$orcamento->valor_total, 2, ',', ' ') }} AOA</strong></div>
        </div>
        @if($orcamento->observacoes)
            <section class="mt-8 border-t pt-5"><h2 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Observações</h2><p class="whitespace-pre-line text-sm">{{ $orcamento->observacoes }}</p></section>
        @endif
        <footer class="mt-10 border-t pt-4 text-center text-xs text-slate-500">Proforma sem valor fiscal. A emissão de uma fatura é realizada separadamente após aceitação.</footer>
    </article>
</div>
@push('scripts')
<style>
@media print {
    @page { size: A4; margin: 15mm; }
    body { background: #fff !important; }
    .print\:hidden { display: none !important; }
    article { border: 0 !important; box-shadow: none !important; padding: 0 !important; }
}
</style>
@endpush
@endsection
