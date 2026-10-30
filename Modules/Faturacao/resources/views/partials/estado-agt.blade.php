{{-- Estado do documento na AGT. Só aparece com a comunicação AGT ativa e para documentos emitidos.
     "Enviado" não é confirmação fiscal: só «Válido» o é. Uso: @include('faturacao::partials.estado-agt', ['documento' => $x]) --}}
@if (config('faturacao.agt.ativo') && $documento->estaEmitido())
    @php
        $submissao = $documento->agtSubmissao;
        $estadoAgt = $submissao?->estado;
        $coresAgt = [
            'valido' => 'bg-emerald-100 text-emerald-700',
            'invalido' => 'bg-red-100 text-red-700',
            'rejeitado' => 'bg-red-100 text-red-700',
            'erro' => 'bg-amber-100 text-amber-700',
            'aguarda_intervencao' => 'bg-amber-100 text-amber-700',
        ];
    @endphp
    <div class="mt-4 rounded-lg border border-slate-200 p-4 text-sm">
        <div class="flex items-center justify-between gap-3">
            <span class="font-medium text-slate-700">Estado na AGT</span>
            <span class="rounded-full px-3 py-1 text-xs font-medium {{ $coresAgt[$estadoAgt] ?? 'bg-slate-100 text-slate-700' }}">
                {{ $submissao?->rotulo() ?? 'Sem registo de envio' }}
            </span>
        </div>
        @if (! $submissao?->confirmadoFiscalmente())
            <p class="mt-2 text-xs text-slate-500">Enquanto o estado não for «Válido», a AGT ainda não confirmou este documento.</p>
        @endif
        @if ($submissao && ! empty($submissao->erros) && $estadoAgt !== 'valido')
            <ul class="mt-2 space-y-1 text-xs text-red-700">
                @foreach (array_slice((array) $submissao->erros, 0, 5) as $erroAgt)
                    <li>{{ is_array($erroAgt) ? trim(($erroAgt['codigo'] ?? '').' '.($erroAgt['descricao'] ?? '')) : $erroAgt }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
