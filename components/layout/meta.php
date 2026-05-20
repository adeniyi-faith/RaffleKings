<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">

    <!-- SEO & Social Media Metadata -->
    <title>RaffleKings - The ultimate community raffle platform</title>
    <meta name="description" content="Join RaffleKings, the ultimate community raffle platform. Participate in daily and weekly draws, win cash prizes, and enjoy secure, instant payouts.">
    <meta name="keywords" content="raffle, lottery, win money, nigeria raffle, daily draw, jackpot, rafflekings, gaming">
    <meta name="author" content="RaffleKings">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://rafflekings.com.ng/">
    <meta property="og:title" content="RaffleKings - Win Big Daily & Weekly">
    <meta property="og:description" content="The ultimate community raffle platform. Secure tickets, instant verification, and massive payouts. Play now!">
    <meta property="og:image" content="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/iOS-1-1.png">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary">
    <meta property="twitter:url" content="https://rafflekings.com.ng/">
    <meta property="twitter:title" content="RaffleKings - Win Big Daily & Weekly">
    <meta property="twitter:description" content="The ultimate community raffle platform. Secure tickets, instant verification, and massive payouts. Play now!">
    <meta property="twitter:image" content="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/iOS-1-1.png">

    <!-- PWA & Mobile Meta Tags -->
    <meta name="theme-color" content="#ffffff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="RaffleKings">
    <link rel="manifest" href="manifest.json">

    <!-- Brand Assets / Favicons -->
    <link rel="apple-touch-icon" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/iOS-1-1.png">
    <link rel="icon" type="image/png" sizes="32x32" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/@32-px.png">
    <link rel="icon" type="image/png" sizes="16x16" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/@16-px.png">
    <link rel="shortcut icon" href="https://getonlinestudio.com/insights/wp-content/uploads/2026/01/@32-px.png">

    <!-- DARK MODE INIT (Smart System Preference) -->
    <script>
        function applyTheme() {
            const localTheme = localStorage.getItem('theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            if (localTheme === 'dark' || (!localTheme && systemDark)) {
                document.documentElement.classList.add('dark');
                document.querySelector('meta[name="theme-color"]').setAttribute('content', '#0f172a');
            } else {
                document.documentElement.classList.remove('dark');
                document.querySelector('meta[name="theme-color"]').setAttribute('content', '#ffffff');
            }
        }
        applyTheme();
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
            if (!localStorage.getItem('theme')) applyTheme();
        });
    </script>

    <!-- Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-XMBB4JFPQ1"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', 'G-XMBB4JFPQ1');
    </script>

    <script src="analytics-tracker.js" data-cfasync="false"></script>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com" data-cfasync="false"></script>
    <script data-cfasync="false">
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        'app-primary': '#2563eb',
                        'app-secondary': '#1e40af',
                        'app-bg': '#f8fafc',
                        'dark-bg': '#0f172a',
                        'dark-card': '#1e293b',
                        'dark-border': '#334155'
                    },
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>

    <!-- Lucide Icons & Fonts -->
    <script src="https://unpkg.com/lucide@latest" data-cfasync="false"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Alpine.js -->
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer data-cfasync="false"></script>

    <!-- Configuration -->
    <script src="config.js" data-cfasync="false"></script>
    <script src="watchdog.js" defer data-cfasync="false"></script>

    <!-- Service Worker -->
    <script data-cfasync="false">
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js')
                    .then(reg => console.log('Service Worker registered!'))
                    .catch(err => console.log('Service Worker registration failed:', err));
            });
        }
    </script>

    <link rel="stylesheet" href="assets/css/header.css">

    <script data-cfasync="false">
        function showSite() {
            document.documentElement.classList.add('js-ready');
            if(typeof lucide !== 'undefined') lucide.createIcons();
        }
        window.addEventListener('load', showSite);
        setTimeout(showSite, 1000);
    </script>
