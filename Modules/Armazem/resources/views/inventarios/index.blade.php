@extends('core::layouts.app')

@section('titulo', 'Inventários físicos')

@section('conteudo')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Inventários físicos</h1>
            <p class="text-muted mb-0">Conta produtos por armazém e reconcilia diferenças com movimentos auditáveis.</p>
        </div>
        <span class="badge bg-info text-dark">Stock multi-armazém</span>
    </div>

    @if (session('sucesso'))
        <div class="alert alert-success">{{ session('sucesso') }}</div>
    @endif
    @if (session('erro'))
        <div class="alert alert-danger">{{ session('erro') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Abrir novo inventário</strong></div>
        <div class="card-body">
            <form method="POST" action="{{ route('armazem.inventarios.store') }}">
                @csrf
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="armazem_id">Armazém</label>
                        <select class="form-select" name="armazem_id" id="armazem_id" required>
                            <option value="">Selecionar armazém</option>
                            @foreach ($armazens as $armazem)
                                <option value="{{ $armazem->id }}">{{ $armazem->codigo }} — {{ $armazem->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="produto_ids">Produtos a contar</label>
                        <select class="form-select" name="produto_ids[]" id="produto_ids" multiple size="4" required>
                            @foreach ($produtos as $produto)
                                <option value="{{ $produto->id }}">{{ $produto->nome }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Seleciona os produtos desta sessão. Só estes ficam bloqueados durante a contagem.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="observacoes">Observações (opcional)</label>
                        <input class="form-control" name="observacoes" id="observacoes" maxlength="2000">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary" type="submit" {{ $armazens->isEmpty() || $produtos->isEmpty() ? 'disabled' : '' }}>Abrir inventário</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @forelse ($inventarios as $inventario)
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong>INV-{{ str_pad((string) $inventario->id, 5, '0', STR_PAD_LEFT) }}</strong>
                    <span class="text-muted ms-2">{{ $inventario->armazem?->nome }}</span>
                    <div class="small text-muted">Aberto em {{ $inventario->aberto_em?->format('d/m/Y H:i') }} · {{ $inventario->utilizadorAbertura?->name ?? 'Sistema' }}</div>
                </div>
                @php
                    $estadoCor = ['em_contagem' => 'warning', 'a_finalizar' => 'info', 'concluido' => 'success', 'cancelado' => 'secondary'][$inventario->estado] ?? 'secondary';
                @endphp
                <span class="badge bg-{{ $estadoCor }}">{{ str_replace('_', ' ', ucfirst($inventario->estado)) }}</span>
            </div>
            <div class="card-body">
                @if ($inventario->observacoes)
                    <p class="text-muted">{{ $inventario->observacoes }}</p>
                @endif
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Produto</th><th class="text-end">Sistema</th><th>Contagem física</th><th class="text-end">Diferença</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($inventario->linhas as $linha)
                            <tr>
                                <td>{{ $linha->produto?->nome ?? 'Produto indisponível' }}</td>
                                <td class="text-end">{{ number_format((float) $linha->quantidade_sistema, 3, ',', '.') }}</td>
                                <td>
                                    @if ($inventario->estado === 'em_contagem')
                                        <form class="d-flex gap-2" method="POST" action="{{ route('armazem.inventarios.contar', [$inventario->id, $linha->id]) }}">
                                            @csrf
                                            <input class="form-control form-control-sm" style="max-width:130px" type="number" name="quantidade_contada" min="0" step="0.001" value="{{ $linha->quantidade_contada }}" required>
                                            <button class="btn btn-sm btn-outline-primary" type="submit">Guardar</button>
                                        </form>
                                    @else
                                        {{ $linha->quantidade_contada === null ? '—' : number_format((float) $linha->quantidade_contada, 3, ',', '.') }}
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($linha->diferenca !== null)
                                        <span class="{{ (float) $linha->diferenca < 0 ? 'text-danger' : ((float) $linha->diferenca > 0 ? 'text-success' : 'text-muted') }}">{{ number_format((float) $linha->diferenca, 3, ',', '.') }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($inventario->estado === 'em_contagem')
                    <div class="d-flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('armazem.inventarios.concluir', $inventario->id) }}">
                            @csrf
                            <button class="btn btn-success btn-sm" type="submit">Concluir e aplicar ajustes</button>
                        </form>
                        <form method="POST" action="{{ route('armazem.inventarios.cancelar', $inventario->id) }}" onsubmit="return confirm('Cancelar este inventário? Nenhum ajuste será aplicado.')">
                            @csrf
                            <button class="btn btn-outline-danger btn-sm" type="submit">Cancelar</button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    @empty
        <div class="alert alert-light border">Ainda não existem inventários físicos.</div>
    @endforelse

    {{ $inventarios->links() }}
</div>
@endsection
