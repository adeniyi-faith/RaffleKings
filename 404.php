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

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #F3F4F6;
            overscroll-behavior-y: none;
        }
        
        .wiggle {
            animation: wiggle 2s linear infinite;
        }

        @keyframes wiggle {
            0%, 7% { transform: rotateZ(0); }
            15% { transform: rotateZ(-15deg); }
            20% { transform: rotateZ(10deg); }
            25% { transform: rotateZ(-10deg); }
            30% { transform: rotateZ(6deg); }
            35% { transform: rotateZ(-4deg); }
            40%, 100% { transform: rotateZ(0); }
        }
    </style>

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

    <div class="bg-white rounded-3xl p-8 shadow-xl shadow-blue-900/5 max-w-sm w-full border border-white">
        
        <!-- Animated Icon -->
        <div class="w-24 h-24 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-6 relative">
            <div class="absolute inset-0 bg-blue-100 rounded-full animate-ping opacity-20"></div>
            <span class="text-5xl wiggle">🚧</span>
        </div>

        <h1 class="text-6xl font-bold text-gray-900 mb-2 tracking-tighter">404</h1>
        
        <div class="bg-orange-50 border border-orange-100 rounded-xl p-4 mb-6">
            <p class="text-sm font-bold text-orange-800 leading-relaxed">
                "Calm down little bro..<br>not all pages are ready yet. 😉"
            </p>
        </div>

        <p class="text-xs text-gray-400 mb-8">
            Once we reach a conclusion on the design, I will fire on. For now, let me go and finish my ogi abeg.
        </p>

        <a href="index.php" class="w-full bg-app-primary text-white py-3.5 rounded-xl font-bold shadow-lg shadow-blue-500/30 active:scale-[0.98] transition-transform flex items-center justify-center gap-2 group">
            <i data-lucide="arrow-left" class="w-4 h-4 group-hover:-translate-x-1 transition-transform"></i>
            Back to Home
        </a>
        
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>