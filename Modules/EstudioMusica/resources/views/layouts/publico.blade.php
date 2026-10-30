<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('titulo', 'Estúdio de Música')</title>
    @stack('head')

    {{-- Mesmo CSS compilado do Core — Tailwind v4 deteta automaticamente
         as classes usadas em qualquer parte do projeto, incluindo aqui. --}}
    @vite(['Modules/Core/resources/css/app.css'])
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js" integrity="sha384-9Ax3MmS9AClxJyd5/zafcXXjxmwFhZCdsT6HJoJjarvCaAkJlk5QDzjLJm+Wdx5F" crossorigin="anonymous" defer></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

    @if (session('sucesso'))
        <div class="bg-emerald-50 border-b border-emerald-200 text-emerald-800 text-sm px-4 py-3 text-center">
            {{ session('sucesso') }}
        </div>
    @endif

    @if (session('erro'))
        <div class="bg-red-50 border-b border-red-200 text-red-800 text-sm px-4 py-3 text-center">
            {{ session('erro') }}
        </div>
    @endif

    @yield('conteudo')

</body>
</html>
