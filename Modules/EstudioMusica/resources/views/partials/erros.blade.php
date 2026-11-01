{{-- Erros de validação: o layout do Core só mostra os avisos 'sucesso'/'erro', por isso sem isto um formulário inválido falhava em silêncio. --}}
@if ($errors->any())
    <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
        <ul class="list-disc space-y-0.5 pl-5">
            @foreach ($errors->all() as $mensagem)
                <li>{{ $mensagem }}</li>
            @endforeach
        </ul>
    </div>
@endif
