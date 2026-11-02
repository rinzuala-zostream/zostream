<!doctype html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="description" content="Zo Stream — Mizo entertainment experience leh Zo Stream WiFi internet service." />
        <meta name="theme-color" content="#030816" />
        <meta name="referrer" content="no-referrer" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        @if (request()->is('analytics/nexframe'))
            <meta name="nexframe-partnership-start" content="{{ config('services.nexframe_dashboard.partnership_started_at') }}" />
        @endif
        <title>{{ request()->is('admin', 'admin/*') ? 'Zo Stream Admin' : 'Zo Stream — All in Mizo' }}</title>
        <link rel="icon" href="/images/zostream-logo.jpg" />
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet" />
        @if (request()->is('admin', 'admin/*'))
            <script>
                window.__ZOSTREAM_ADMIN__ = {
                    firebaseDatabaseUrl: @json(config('firebase.database_url')),
                };
            </script>
        @endif
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div id="app">
            @if (request()->is('account/stats'))
                <main style="max-width:600px;margin:80px auto;padding:24px;font-family:system-ui;color:#eaf0f7;background:#101318;border-radius:16px">
                    <h1>My Zo Stream</h1>
                    <p id="stats-load-status" role="status">Loading your account… If this takes too long, reload to try again.</p>
                    <a href="/account/stats{{ request()->query('app') === '1' ? '?app=1' : '' }}" style="color:#82dcf7">Reload My Zo Stream</a>
                    <noscript><p>Enable JavaScript in your browser to view your account.</p></noscript>
                </main>
            @endif
        </div>
    </body>
</html>
