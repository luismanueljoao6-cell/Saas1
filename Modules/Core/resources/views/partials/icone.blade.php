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
        'fatura' => 'M7 3h7l5 5v13H7a2 2 0 01-2-2V5a2 2 0 012-2zM14 3v5h5M8 13h8M8 17h8',
        'orcamento' => 'M8 4h8M9 3h6v3H9zM6 5H5v16h14V5h-1M8 11h8M8 15h8',
        'pacote' => 'M3 7l9-4 9 4-9 4-9-4zM3 7v10l9 4 9-4V7M12 11v10',
        'recibo' => 'M6 3h12v18l-3-2-3 2-3-2-3 2V3zM9 8h6M9 12h6',
        'cliente' => 'M20 21a8 8 0 00-16 0M12 13a4 4 0 100-8 4 4 0 000 8',
        'exportar' => 'M12 3v12m-5-5l5 5 5-5M4 17v4h16v-4',
        'empresa' => 'M3 21h18M5 21V5l7-2v18M12 8h7v13M8 7h1M8 11h1M8 15h1M15 11h1M15 15h1',
        'utilizadores' => 'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8M20 8v6M23 11h-6',
        'plano' => 'M12 3l2.5 5.1 5.6.8-4.1 4 .97 5.6L12 15.8l-4.97 2.7L8 12.9l-4.1-4 5.6-.8L12 3z',
        'cartao' => 'M3 5h18v14H3zM3 10h18M7 15h4',
        'imagem' => 'M4 4h16v16H4zM8 9h.01M20 16l-5-5-7 7',
        'musica' => 'M9 18V5l12-2v13M9 18a3 3 0 11-3-3c1.66 0 3 1.34 3 3zM21 16a3 3 0 11-3-3c1.66 0 3 1.34 3 3z',
        'microfone' => 'M12 2a3 3 0 00-3 3v7a3 3 0 006 0V5a3 3 0 00-3-3zM5 11a7 7 0 0014 0M12 18v4M8 22h8',
        'audio' => 'M3 10v4M7 6v12M11 3v18M15 7v10M19 10v4M23 8v8',
        'prova' => 'M9 12l2 2 4-4M21 12a9 9 0 11-18 0 9 9 0 0118 0',
        'campanha' => 'M3 11v2h3l12 6V5L6 11H3zM6 13l2 7h4l-3-6M18 9a3 3 0 010 6',
        'documento' => 'M7 3h7l5 5v13H7a2 2 0 01-2-2V5a2 2 0 012-2zM14 3v5h5M8 13h8M8 17h6',
        'aviso' => 'M12 9v4m0 4h.01M10 3h4l8 15H2L10 3z',
        'circulo' => 'M12 12m-9 0a9 9 0 1018 0a9 9 0 10-18 0',
    ];
    $d = $paths[$nome] ?? $paths['circulo'];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" class="{{ $classe }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $d }}"/></svg>
