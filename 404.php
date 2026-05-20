<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Page Not Found - The Community Draw</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/404.css">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        app: {
                            bg: '#F3F4F6',
                            primary: '#2563EB',
                            primaryDark: '#1d4ed8',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-app-bg text-gray-800 h-screen flex flex-col items-center justify-center p-6 text-center">

<?php require_once 'components/pages/404-content.php'; ?>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
