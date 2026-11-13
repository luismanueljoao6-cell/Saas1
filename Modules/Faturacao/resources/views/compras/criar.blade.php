@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-5xl space-y-5 px-4 py-6">
    <div><h1 class="text-2xl font-bold">Registar compra</h1><p class="text-sm text-slate-500">A compra fica em rascunho. O stock só é atualizado quando confirmares a receção.</p></div>
    @if ($errors->any())<div class="rounded-md bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>@endif
    <form method="POST" id="form-compra" action="{{ route('faturacao.compras.guardar') }}" class="space-y-5 rounded-lg border bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">@csrf
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2"><label class="grid gap-1 text-sm">Fornecedor<select name="fornecedor_id" required class="rounded-md border px-3 py-2">@if ($fornecedores->isNotEmpty())@foreach ($fornecedores as $fornecedor)<option value="{{ $fornecedor->id }}">{{ $fornecedor->nome }}</option>@endforeach@else<option value="" selected disabled>Regista primeiro um fornecedor</option>@endif</select></label><label class="grid gap-1 text-sm">Data do documento<input type="date" name="data_documento" value="{{ today()->toDateString() }}" required class="rounded-md border px-3 py-2"></label><label class="grid gap-1 text-sm">Vencimento<input type="date" name="data_vencimento" class="rounded-md border px-3 py-2"></label><label class="grid gap-1 text-sm">Referência do fornecedor<input name="referencia_fornecedor" maxlength="80" class="rounded-md border px-3 py-2"></label></div>
        <div><h2 class="mb-2 font-semibold">Artigos recebidos</h2><div class="space-y-2">
        @if ($produtos->isNotEmpty())
        @foreach ($produtos as $produto)<div data-compra-linha class="grid grid-cols-1 items-center gap-2 rounded-md border p-3 sm:grid-cols-4 dark:border-zinc-700"><label class="flex items-center gap-2 sm:col-span-2"><input type="checkbox" name="linhas[{{ $loop->index }}][selecionado]" value="1" data-linha-toggle><span>{{ $produto->nome }}</span></label><input type="hidden" name="linhas[{{ $loop->index }}][produto_id]" value="{{ $produto->id }}" disabled><label class="text-xs">Quantidade<input name="linhas[{{ $loop->index }}][quantidade]" value="1" disabled type="number" min="0.001" step="0.001" class="mt-1 w-full rounded-md border px-2 py-2"></label><label class="text-xs">Preço unitário<input name="linhas[{{ $loop->index }}][preco_unitario]" value="{{ $produto->preco_unitario }}" disabled type="number" min="0" step="0.01" class="mt-1 w-full rounded-md border px-2 py-2"></label><label class="text-xs">IVA (%)<input name="linhas[{{ $loop->index }}][taxa_iva]" value="{{ $produto->taxa_iva ?? 0 }}" disabled type="number" min="0" max="100" step="0.01" class="mt-1 w-full rounded-md border px-2 py-2"></label></div>@endforeach
        @else
        <div class="rounded-md border border-dashed p-4 text-sm text-slate-600 dark:border-zinc-700 dark:text-zinc-300">Ainda não existem artigos com controlo de stock disponíveis. Regista ou ativa produtos no catálogo antes de criar uma compra.</div>
        @endif
        </div><p class="mt-2 text-xs text-slate-500">Seleciona apenas os artigos que constam no documento do fornecedor.</p></div>
        <label class="grid gap-1 text-sm">Observações<textarea name="observacoes" maxlength="2000" class="rounded-md border px-3 py-2"></textarea></label>
        <div class="flex flex-wrap gap-2"><button @disabled($fornecedores->isEmpty() || $produtos->isEmpty()) class="rounded-md bg-slate-900 px-5 py-2 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Guardar rascunho</button><a href="{{ route('faturacao.compras.index') }}" class="rounded-md border px-5 py-2">Cancelar</a></div>
    </form>
</div>
<script>
    document.querySelectorAll('[data-linha-toggle]').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            checkbox.closest('[data-compra-linha]').querySelectorAll('input:not([type="checkbox"])').forEach((campo) => { campo.disabled = !checkbox.checked; });
        });
    });
</script>
@endsection
