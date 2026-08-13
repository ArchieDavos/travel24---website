<?php
define('APP_ACCESS', true);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
http_response_code(404);
require_once 'db.php';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>TRAVEL24.me | Strona nie znaleziona</title>
    <meta name="robots" content="noindex">

    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#f59e0b">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="/fonts/fonts.css" rel="stylesheet">
    <style>
        body, h1, h2, h3, h4, p, a, button { font-family: 'Montserrat', sans-serif !important; }
        .playfair { font-family: 'Playfair Display', serif !important; }
        body { background-color: #f8fafc; color: #18181b; }
    </style>
</head>
<body class="flex flex-col min-h-screen">
    <?php include 'header.php'; ?>

    <main class="flex-grow flex items-center justify-center px-4 py-24 mt-16">
        <div class="text-center max-w-lg">
            <div class="text-amber-500 font-black playfair text-8xl md:text-9xl mb-4 select-none">404</div>
            <h1 class="text-2xl md:text-3xl font-bold mb-4 text-zinc-900">Ups, ta ścieżka nigdzie nie prowadzi</h1>
            <p class="text-zinc-500 mb-8 leading-relaxed">
                Strona, której szukasz, mogła zostać przeniesiona, usunięta, albo nigdy nie istniała.
                Ale mamy mnóstwo innych wypraw wartych zobaczenia.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="/index.php" class="bg-amber-500 hover:bg-amber-600 text-white font-bold uppercase tracking-widest py-3 px-8 rounded-lg transition-colors shadow-md">
                    Wróć na stronę główną
                </a>
                <a href="/gallery.php" class="border border-zinc-300 hover:bg-amber-500 hover:text-white hover:border-amber-500 text-zinc-700 font-bold uppercase tracking-widest py-3 px-8 rounded-lg transition-colors">
                    Zobacz galerię
                </a>
            </div>
        </div>
    </main>

    <?php include 'footer.php'; ?>
</body>
</html>
