<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('titulo', 'Plataforma de Gestão')</title>

    @vite(['Modules/Core/resources/css/app.css'])

    {{-- Alpine.js via CDN para os pequenos toques de interatividade deste
         módulo (ex.: mostrar/ocultar password). Numa fase seguinte, mover
         para um pacote npm importado e compilado pelo Vite. --}}
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
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

    <main class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
        <div class="w-full max-w-md">
            @yield('conteudo')
        </div>
    </main>

</body>
</html>
