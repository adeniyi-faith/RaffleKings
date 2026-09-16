<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'RaffleKings') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @inertiaHead
    </head>
    <body class="bg-app-bg text-gray-900 antialiased dark:bg-dark-bg dark:text-gray-100">
        @inertia
    </body>
</html>
