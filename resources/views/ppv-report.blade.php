<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $content['title'] }} · PPV report · Zo Stream</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#0b1220;color:#eef4ff;font:16px system-ui,sans-serif}main{max-width:1040px;margin:auto;padding:36px 20px}header{display:flex;gap:24px;align-items:center;margin:32px 0}img{width:110px;max-height:165px;object-fit:cover;border-radius:14px}h1{font-size:clamp(26px,5vw,40px);margin:8px 0}h2{font-size:20px}p,small,dt{color:#a8b7cc}a{color:#72e0d4}nav{display:flex;gap:8px;flex-wrap:wrap;margin:24px 0}nav a,.refresh{display:inline-block;padding:10px 16px;border:1px solid #33445c;border-radius:24px;text-decoration:none}nav a[aria-current=page]{background:#72e0d4;color:#0b1220;border-color:#72e0d4}.metrics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}article,.titles{padding:22px;background:#131f32;border:1px solid #293a52;border-radius:16px}dt{font-size:14px}dd{font-size:30px;font-weight:750;margin:12px 0 0;overflow-wrap:anywhere}dd small{font-size:16px}.titles{margin-top:24px}ul{list-style:none;padding:0}li{display:flex;gap:16px;justify-content:space-between;padding:14px 0;border-bottom:1px solid #293a52}footer{margin-top:24px;line-height:1.7}.brand{font-weight:800;letter-spacing:.15em;color:#72e0d4}.status{font-size:14px;color:#a8b7cc}@media(max-width:520px){.metrics{grid-template-columns:1fr}header{gap:14px}img{width:80px}li{flex-wrap:wrap}}
    </style>
</head>
<body>
<main>
    <div class="brand">ZO STREAM</div>
    <header>
        @if($content['poster'])<img src="{{ $content['poster'] }}" alt="{{ $content['title'] }} poster">@endif
        <div><small>{{ ucfirst($content['type']) }} · PPV sales report</small><h1>{{ $content['title'] }}</h1><span class="status">{{ $content['status'] }}</span></div>
    </header>
    <nav aria-label="Report period">
        @foreach(['all' => 'All time', 'today' => 'Today', 'month' => 'This month'] as $key => $label)
            <a href="?period={{ $key }}" @if($period === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <dl class="metrics">
        <article><dt>Current price</dt><dd>{{ $content['price'] === null ? 'See title prices below' : 'INR '.number_format($content['price'], 2) }}</dd></article>
        <article><dt>Completed purchases · Lei tawh zat</dt><dd>{{ number_format($summary['purchases']) }}</dd></article>
        <article><dt>Successful payment total</dt><dd>
            @forelse($summary['currencies'] as $currency => $amount)
                <div><small>{{ $currency }}</small> {{ number_format($amount, 2) }}</div>
            @empty INR 0.00 @endforelse
        </dd></article>
        <article><dt>Refunded / Cancelled payments</dt><dd>{{ number_format($summary['refunds']) }} / {{ number_format($summary['cancelled']) }}</dd></article>
    </dl>
    @if($children->isNotEmpty())
        <section class="titles"><h2>Season & episode prices</h2><ul>
            @foreach($children as $child)
                <li><a href="{{ $child['url'] }}">{{ ucfirst($child['type']) }} · {{ $child['title'] }}</a><span>INR {{ number_format($child['price'], 2) }}</span></li>
            @endforeach
        </ul></section>
    @endif
    <footer><p>Purchase counts represent successful payments, including repeat purchases. Amounts show recorded successful payments, before fees; refunds and cancellations are shown separately when recorded. Each currency is reported separately.</p>
        <p>Period boundaries use India time (IST). Updated {{ $updatedAt->format('d M Y, h:i:s A') }} IST.</p>
        <a class="refresh" href="?period={{ $period }}">Refresh report</a>
    </footer>
</main>
</body>
</html>
