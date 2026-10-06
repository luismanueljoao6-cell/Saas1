<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('titulo', 'Atelier')</title>

    @vite(['Modules/Atelier/resources/css/app.css'])
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

    @if ($errors->any())
        <div class="bg-red-50 border-b border-red-200 text-red-800 text-sm px-4 py-3 text-center">
            {{ $errors->first() }}
        </div>
    @endif

    <main class="flex min-h-screen flex-col items-center px-4 py-10">
        <div class="w-full @yield('largura', 'max-w-3xl')">
            @yield('conteudo')
        </div>
    </main>

</body>
</html>
