@php
    use Carbon\Carbon;

    $start = Carbon::parse($ad->create_date ?? now());
    $period = (int) ($ad->period ?? 0);
    $end = (clone $start)->addDays($period);
    $now = now();
    $isActive = $now->between($start, $end);

    $pageTitle = $ad->ads_name . ' — Zo Stream Ads';
    $preview = $ad->feature_img ?: ($ad->img1 ?: $ad->img2 ?: $ad->img3 ?: $ad->img4);
    $imgs = collect([$ad->img1, $ad->img2, $ad->img3, $ad->img4])->filter()->values();
@endphp
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>{{ $pageTitle }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {{-- SEO / Social --}}
    <meta name="description" content="{{ Str::limit(strip_tags($ad->description ?? ''), 160) }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ Str::limit(strip_tags($ad->description ?? ''), 200) }}">
    @if ($preview)
        <meta property="og:image" content="{{ $preview }}">
        <meta name="twitter:image" content="{{ $preview }}">
    @endif
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $ad->ads_url }}">

    {{-- Fonts + Tailwind --}}
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] }
                }
            }
        }
    </script>

    <style>
        :root {
            color-scheme: dark;
        }

        body {
            background: #0b0b0b;
            color: #eaeaea;
        }

        .card {
            background: #121212;
            border-radius: 16px;
            box-shadow: 0 10px 24px rgba(0, 0, 0, .35);
        }

        .badge {
            background: #ffffff14;
            border: 1px solid #ffffff2a;
        }

        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(1200px 500px at 20% -10%, #ffffff1a, transparent 60%);
            pointer-events: none;
        }
    </style>
</head>

<body class="min-h-screen font-sans antialiased">

    {{-- HERO --}}
    <section class="relative hero">
        <div class="absolute inset-0 overflow-hidden">
            @if($preview)
                <img src="{{ $preview }}" class="w-full h-full object-cover opacity-30 blur-lg scale-110" alt="Backdrop">
            @else
                <div class="w-full h-full bg-gradient-to-br from-neutral-900 to-neutral-800"></div>
            @endif
            <div class="absolute inset-0 bg-gradient-to-b from-black/70 via-black/60 to-[#0b0b0b]"></div>
        </div>

        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 pt-12 pb-10">
            <div class="flex flex-col md:flex-row gap-6 items-start">
                {{-- Cover --}}
                <img src="{{ $preview }}" onerror="this.style.display='none';"
                    class="w-full md:w-[320px] h-[220px] md:h-[260px] object-cover rounded-2xl shadow-2xl ring-1 ring-white/10"
                    alt="Cover">

                {{-- Title + Meta (with keyword parsing) --}}
                @php
                    $rawDesc = $ad->description ?? '';

                    $links = [];
                    $phones = [];

                    // Regex to match "link: url - display_name: text"
                    if (preg_match_all('/link\s*:\s*(https?:\/\/\S+)\s*-\s*display_name\s*:\s*([^\n]+)/i', $rawDesc, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $m) {
                            $links[] = ['url' => trim($m[1]), 'name' => trim($m[2])];
                        }
                    }

                    // Regex to match "phone: number - display_name: text"
                    if (preg_match_all('/phone\s*:\s*([+()\- \d]{6,20})\s*-\s*display_name\s*:\s*([^\n]+)/i', $rawDesc, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $m) {
                            $phones[] = ['num' => trim($m[1]), 'name' => trim($m[2])];
                        }
                    }

                    // Clean description (remove keyword patterns)
                    $cleanDesc = preg_replace([
                        '/link\s*:\s*https?:\/\/\S+\s*-\s*display_name\s*:[^\n]+/i',
                        '/phone\s*:\s*[+()\- \d]{6,20}\s*-\s*display_name\s*:[^\n]+/i'
                    ], '', $rawDesc);

                    $cleanDesc = trim(preg_replace('/\s{2,}/', ' ', $cleanDesc));
                @endphp

                <div class="flex-1">
                    <div class="flex flex-wrap items-center gap-3 mb-3">
                        <h1 class="text-3xl md:text-5xl font-extrabold tracking-tight">{{ $ad->ads_name }}</h1>
                        <span
                            class="text-xs md:text-sm px-2.5 py-1 rounded-md badge {{ $isActive ? 'text-emerald-300' : 'text-rose-300' }}">
                            {{ $isActive ? 'Active' : 'Expired' }}
                        </span>
                    </div>

                    {{-- Description (keywords removed) --}}
                    @if ($cleanDesc !== '')
                        <p class="text-base md:text-lg/relaxed text-white/85 max-w-3xl">
                            {{ $cleanDesc }}
                        </p>
                    @endif

                    {{-- Render extracted buttons --}}
                    @if ($links || $phones)
                        <div class="mt-4 flex flex-wrap gap-3">
                            @foreach ($links as $l)
                                <a href="{{ $l['url'] }}" target="_blank" rel="noopener"
                                    class="inline-flex items-center px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 transition shadow-lg shadow-indigo-900/30">
                                    🔗 {{ $l['name'] }}
                                </a>
                            @endforeach

                            @foreach ($phones as $p)
                                <a href="tel:{{ preg_replace('/[^\d+]/', '', $p['num']) }}"
                                    class="inline-flex items-center px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 transition shadow-lg">
                                    📞 {{ $p['name'] }}
                                </a>
                            @endforeach
                        </div>
                    @endif

                    {{-- Actions (hidden on mobile) --}}
                    <div class="mt-6 flex flex-wrap gap-3">
                        <button id="copyBtn"
                            class="hidden sm:inline-flex px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 transition shadow-lg shadow-indigo-900/30"
                            data-link="{{ $ad->ads_url }}">
                            Copy Link
                        </button>
                        <button id="shareBtn"
                            class="hidden sm:inline-flex px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition ring-1 ring-white/10">
                            Share
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- MAIN CONTENT --}}
    <main class="relative z-10 max-w-6xl mx-auto px-4 sm:px-6 -mt-6 pb-14">
        <div class="card p-5 sm:p-8">
            {{-- Gallery --}}
            @if($imgs->count())
                <h2 class="text-xl font-semibold mb-4">Photos</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    @foreach($imgs as $i => $img)
                        <button class="group relative" onclick="openLightbox({{ $i }})" aria-label="Open image {{ $i + 1 }}">
                            <img src="{{ $img }}"
                                class="w-full h-36 md:h-44 object-cover rounded-lg ring-1 ring-white/10 group-hover:ring-indigo-400 transition"
                                alt="Ad Image" onerror="this.style.display='none';">
                            <div class="absolute inset-0 rounded-lg bg-black/0 group-hover:bg-black/20 transition"></div>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="text-center opacity-80 text-sm mt-10">
            <p class="font-semibold text-white/90">
                ❤️ Ads you’ll love — powered by <span class="text-indigo-400">Zo Stream</span>
        </div>

    </main>

    {{-- LIGHTBOX MODAL --}}
    @if($imgs->count())
        <div id="lightbox" class="fixed inset-0 hidden items-center justify-center bg-black/80 z-50 p-4">
            <button class="absolute top-4 right-4 px-3 py-1.5 rounded-md bg-white/10 hover:bg-white/20"
                onclick="closeLightbox()" aria-label="Close">Close</button>
            <div class="max-w-5xl w-full">
                <img id="lightboxImg" src="" class="w-full max-h-[80vh] object-contain rounded-xl ring-1 ring-white/10"
                    alt="Preview">
                <div class="flex justify-between mt-3">
                    <button class="px-3 py-1.5 rounded-md bg-white/10 hover:bg-white/20" onclick="prevImg()">Prev</button>
                    <button class="px-3 py-1.5 rounded-md bg-white/10 hover:bg-white/20" onclick="nextImg()">Next</button>
                </div>
            </div>
        </div>
    @endif

    <script>
        // Copy
        document.getElementById('copyBtn')?.addEventListener('click', async (e) => {
            const link = e.currentTarget.getAttribute('data-link');
            try {
                await navigator.clipboard.writeText(link);
                e.currentTarget.textContent = 'Copied!';
                setTimeout(() => e.currentTarget.textContent = 'Copy Link', 1200);
            } catch (err) { alert('Copy failed: ' + err); }
        });

        // Share
        document.getElementById('shareBtn')?.addEventListener('click', async () => {
            const data = {
                title: @json($pageTitle),
                text: 'Check out this ad on Zo Stream',
                url: @json($ad->ads_url)
            };
            if (navigator.share) { try { await navigator.share(data); } catch (e) { } }
            else { alert('Sharing is not supported on this device.'); }
        });

        // Lightbox
        @if($imgs->count())
            const images = @json($imgs->all());
            let current = 0;
            function openLightbox(idx) { current = idx; updateLightbox(); document.getElementById('lightbox').classList.remove('hidden'); document.getElementById('lightbox').classList.add('flex'); }
            function closeLightbox() { document.getElementById('lightbox').classList.add('hidden'); document.getElementById('lightbox').classList.remove('flex'); }
            function updateLightbox() { document.getElementById('lightboxImg').src = images[current]; }
            function nextImg() { current = (current + 1) % images.length; updateLightbox(); }
            function prevImg() { current = (current - 1 + images.length) % images.length; updateLightbox(); }
            document.addEventListener('keydown', (e) => {
                const lb = document.getElementById('lightbox');
                if (lb.classList.contains('hidden')) return;
                if (e.key === 'Escape') closeLightbox();
                if (e.key === 'ArrowRight') nextImg();
                if (e.key === 'ArrowLeft') prevImg();
            });
        @endif
    </script>
</body>

</html>