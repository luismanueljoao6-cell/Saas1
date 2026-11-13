@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-5xl space-y-5 px-4 py-6">
    <div><h1 class="text-2xl font-bold">Registar compra</h1><p class="text-sm text-slate-500">A compra fica em rascunho. O stock só é atualizado quando confirmares a receção.</p></div>
    @if ($errors->any())<div class="rounded-md bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('faturacao.compras.guardar') }}" class="space-y-5 rounded-lg border bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">@csrf
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2"><label class="grid gap-1 text-sm">Fornecedor<select name="fornecedor_id" required class="rounded-md border px-3 py-2">@foreach ($fornecedores as $fornecedor)<option value="{{ $fornecedor->id }}">{{ $fornecedor->nome }}</option>@endforeach</select></label><label class="grid gap-1 text-sm">Data do documento<input type="date" name="data_documento" value="{{ today()->toDateString() }}" required class="rounded-md border px-3 py-2"></label><label class="grid gap-1 text-sm">Vencimento<input type="date" name="data_vencimento" class="rounded-md border px-3 py-2"></label><label class="grid gap-1 text-sm">Referência do fornecedor<input name="referencia_fornecedor" maxlength="80" class="rounded-md border px-3 py-2"></label></div>
        <div><h2 class="mb-2 font-semibold">Artigos recebidos</h2><div class="space-y-2">
        @foreach ($produtos as $produto)<div class="grid grid-cols-1 items-center gap-2 rounded-md border p-3 sm:grid-cols-4 dark:border-zinc-700"><label class="flex items-center gap-2 sm:col-span-2"><input type="checkbox" name="linhas[{{ $loop->index }}][selecionado]" value="1"><span>{{ $produto->nome }}</span></label><input type="hidden" name="linhas[{{ $loop->index }}][produto_id]" value="{{ $produto->id }}"><label class="text-xs">Quantidade<input name="linhas[{{ $loop->index }}][quantidade]" value="1" type="number" min="0.001" step="0.001" class="mt-1 w-full rounded-md border px-2 py-2"></label><label class="text-xs">Preço unitário<input name="linhas[{{ $loop->index }}][preco_unitario]" value="{{ $produto->preco_unitario }}" type="number" min="0" step="0.01" class="mt-1 w-full rounded-md border px-2 py-2"></label><label class="text-xs">IVA (%)<input name="linhas[{{ $loop->index }}][taxa_iva]" value="{{ $produto->taxa_iva ?? 0 }}" type="number" min="0" max="100" step="0.01" class="mt-1 w-full rounded-md border px-2 py-2"></label></div>@endforeach
        </div><p class="mt-2 text-xs text-slate-500">Seleciona apenas os artigos que constam no documento do fornecedor.</p></div>
        <label class="grid gap-1 text-sm">Observações<textarea name="observacoes" maxlength="2000" class="rounded-md border px-3 py-2"></textarea></label>
        <div class="flex flex-wrap gap-2"><button class="rounded-md bg-slate-900 px-5 py-2 font-semibold text-white">Guardar rascunho</button><a href="{{ route('faturacao.compras.index') }}" class="rounded-md border px-5 py-2">Cancelar</a></div>
    </form>
</div>
@endsection
