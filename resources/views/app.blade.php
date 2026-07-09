<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>TicketLens Console</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link id="tl-font-preload" rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,300..700;1,14..32,300..700&family=JetBrains+Mono:wght@400;500;700&display=swap" as="style">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,300..700;1,14..32,300..700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet"></noscript>
    <script src="{{ asset('js/theme-init.js') }}?v={{ file_exists(public_path('js/theme-init.js')) ? filemtime(public_path('js/theme-init.js')) : config('app.version') }}"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="bg-gray-950 text-gray-100 antialiased">
    @inertia
</body>
</html>
