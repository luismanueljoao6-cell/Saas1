@php
    $nome = $nome ?? 'circulo';
    $classe = $classe ?? 'size-5';
    $paths = [
        'fechar' => 'M6 6l12 12M18 6L6 18',
        'mais' => 'M12 5v14M5 12h14',
        'dinheiro' => 'M12 8c-2 0-3 1-3 2s1 2 3 2 3 1 3 2-1 2-3 2m0-10v12M21 12a9 9 0 11-18 0 9 9 0 0118 0',
        'pedidos' => 'M4 5h16v14H4zM8 9h8M8 13h8M8 17h4',
        'calendario' => 'M4 6h16v14H4zM8 3v6M16 3v6M4 10h16',
        'medidor' => 'M4 18a8 8 0 1116 0M12 14l4-4',
        'circulo' => 'M12 12m-9 0a9 9 0 1018 0a9 9 0 10-18 0',
    ];
    $d = $paths[$nome] ?? $paths['circulo'];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" class="{{ $classe }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $d }}"/></svg>
