@extends('core::layouts.app')

@section('titulo', 'Pagamento pendente')

@section('conteudo')
    @php
        $entidade = $pagamento->entidade();
        $referencia = (string) $pagamento->referencia_externa;
        $rotulos = [
            'pendente' => 'A aguardar pagamento',
            'confirmado' => 'Pagamento confirmado',
            'expirado' => 'Referência expirada',
            'falhado' => 'Pagamento falhado',
        ];
        $montante = number_format((float) $pagamento->valor, 2, ',', ' ').' '.$pagamento->moeda;
    @endphp

    <div class="rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm" x-data="{ copiado: null }">
        <h1 class="text-xl font-semibold text-slate-900">
            {{ $pagamento->estado === 'confirmado' ? 'Pagamento confirmado' : 'Falta só o pagamento' }}
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            Plano <strong>{{ $pagamento->subscricao->plano->nome }}</strong>
        </p>

        @if (! $entidade && $pagamento->estado === 'pendente')
            <div class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800">
                Não foi possível obter a entidade Multicaixa para este pagamento.
                Contacte o suporte antes de pagar.
            </div>
        @endif

        <dl class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-slate-50 text-left">
            <div class="flex items-center justify-between gap-4 p-4">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Entidade</dt>
                <dd class="font-mono text-xl font-semibold text-slate-900"
                    @click="navigator.clipboard.writeText(@js((string) $entidade)); copiado = 'entidade'">
                    {{ $entidade ?? '—' }}
                    <span x-show="copiado === 'entidade'" class="ml-2 text-xs font-normal text-emerald-600">copiado</span>
                </dd>
            </div>
            <div class="flex items-center justify-between gap-4 p-4">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Referência</dt>
                <dd class="font-mono text-xl font-semibold text-slate-900"
                    @click="navigator.clipboard.writeText(@js($referencia)); copiado = 'referencia'">
                    {{ trim(chunk_split($referencia, 3, ' ')) }}
                    <span x-show="copiado === 'referencia'" class="ml-2 text-xs font-normal text-emerald-600">copiado</span>
                </dd>
            </div>
            <div class="flex items-center justify-between gap-4 p-4">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Montante exato</dt>
                <dd class="text-lg font-semibold text-slate-900">{{ $montante }}</dd>
            </div>
            <div class="flex items-center justify-between gap-4 p-4">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Válida até</dt>
                <dd class="text-sm text-slate-900">{{ $pagamento->expira_em?->format('d/m/Y H:i') ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-4 p-4">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Estado</dt>
                <dd class="text-sm font-medium text-slate-900">{{ $rotulos[$pagamento->estado] ?? $pagamento->estado }}</dd>
            </div>
        </dl>

        <p class="mt-2 text-xs text-slate-500">Toca num valor para o copiar. Paga o montante exato indicado.</p>

        @if ($pagamento->estado === 'pendente')
            <p class="mt-6 text-sm text-slate-500">
                Assim que o banco confirmar o pagamento, a subscrição fica ativa automaticamente.
                Se já pagaste, atualiza esta página dentro de instantes.
            </p>
        @elseif ($pagamento->estado === 'expirado' || $pagamento->estado === 'falhado')
            <p class="mt-6 text-sm text-slate-500">
                Esta referência já não pode ser usada.
                <a href="{{ route('subscricoes.planos') }}" class="font-medium text-indigo-600 hover:text-indigo-500">Gerar uma nova</a>.
                Se já pagaste esta referência, contacta o suporte — o pagamento será reconciliado.
            </p>
        @endif

        <a href="{{ route('core.painel') }}" class="mt-6 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500">
            &larr; Voltar ao painel
        </a>
    </div>
@endsection

@if ($pagamento->estado === 'pendente')
    @push('scripts')
        <script>
            // Verifica a confirmação sem obrigar a atualizar a página à mão.
            setTimeout(function () { window.location.reload(); }, 30000);
        </script>
    @endpush
@endif
