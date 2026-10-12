@php
    $classe = $classe ?? 'size-5';
    $paths = [
        'fechar' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'mais' => '<path d="M12 5v14M5 12h14"/>',
        'dinheiro' => '<path d="M12 2v20m5-16H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'pedidos' => '<path d="M4 5h16v14H4zM8 9h8M8 13h5"/>',
        'calendario' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'medidor' => '<path d="M4 19a9 9 0 1 1 16 0M12 13l4-4M12 19v.01"/>',
        'utilizadores' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 8v6M17 11h6"/>',
        'empresa' => '<path d="M3 21h18M5 21V5l7-3 7 3v16M9 9h1m4 0h1m-6 4h1m4 0h1m-5 8v-4h4v4"/>',
    ];
@endphp
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="{{ $classe }}" aria-hidden="true">
    {!! $paths[$nome ?? ''] ?? '<circle cx="12" cy="12" r="8"/>' !!}
</svg>
