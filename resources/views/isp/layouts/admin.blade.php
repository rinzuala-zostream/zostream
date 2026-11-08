<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/jpeg" href="{{ asset('isp-assets/images/favicon.jpeg') }}">
    <link rel="apple-touch-icon" href="{{ asset('isp-assets/images/zostream-logo.jpeg') }}">
    <script>try{if(localStorage.getItem('zostream.sidebar.collapsed')==='1')document.documentElement.classList.add('nav-collapsed')}catch(e){}</script>
    <link rel="stylesheet" href="{{ asset('isp-assets/css/admin.css') }}?v={{ filemtime(public_path('isp-assets/css/admin.css')) }}">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="{{ route('isp.dashboard') }}">
            <img class="brand-logo" src="{{ asset('isp-assets/images/zostream-logo.jpeg') }}" alt="ZoStream logo">
            <span class="brand-copy"><strong>ZoStream</strong><small>ISP CONTROL</small></span>
        </a>
        <nav>
            <a class="{{ request()->routeIs('isp.dashboard') ? 'active' : '' }}" href="{{ route('isp.dashboard') }}" title="Overview"><span class="nav-icon">⌂</span><span class="nav-label">Overview</span></a>
            <a class="{{ request()->routeIs('isp.customers.*') ? 'active' : '' }}" href="{{ route('isp.customers.index') }}" title="Customers"><span class="nav-icon">♙</span><span class="nav-label">Customers</span></a>
            @if(auth()->user()->isAdmin())
            <a class="{{ request()->routeIs('isp.branches.*') ? 'active' : '' }}" href="{{ route('isp.branches.index') }}" title="Branches"><span class="nav-icon">⌖</span><span class="nav-label">Branches</span></a>
            <a class="{{ request()->routeIs('isp.packages.*') ? 'active' : '' }}" href="{{ route('isp.packages.index') }}" title="Packages"><span class="nav-icon">◇</span><span class="nav-label">Packages</span></a>
            @endif
            <a class="{{ request()->routeIs('isp.payments.*') ? 'active' : '' }}" href="{{ route('isp.payments.index') }}" title="Payments"><span class="nav-icon">₹</span><span class="nav-label">Payments</span></a>
            @if(auth()->user()->isAdmin())
            <a class="{{ request()->routeIs('isp.routers.*') ? 'active' : '' }}" href="{{ route('isp.routers.index') }}" title="Routers"><span class="nav-icon">⌁</span><span class="nav-label">Routers</span></a>
            <a class="{{ request()->routeIs('isp.users.*') ? 'active' : '' }}" href="{{ route('isp.users.index') }}" title="Admin users"><span class="nav-icon">♚</span><span class="nav-label">Admin users</span></a>
            @endif
        </nav>
        <div class="sidebar-foot">
            <div class="user-chip"><span>{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><div class="user-details"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->isAdmin() ? 'Administrator' : (auth()->user()->branch?->name.' operator') }}</small></div></div>
            <form method="POST" action="{{ route('isp.logout') }}">@csrf<button class="logout-button" type="submit" title="Sign out"><span class="logout-icon" aria-hidden="true">⏻</span><span class="logout-label">Sign out</span></button></form>
        </div>
    </aside>
    <div class="backdrop" id="backdrop"></div>
    <main class="main">
        <header class="topbar">
            <button class="menu-button" id="menuButton" type="button" aria-label="Collapse navigation" aria-controls="sidebar" aria-expanded="true">←</button>
            <div><p>@yield('eyebrow', 'ISP Operations')</p><h1>@yield('title', 'Dashboard')</h1></div>
            <div class="live-pill"><i></i> System ready</div>
        </header>
        <div class="content">
            @foreach (['success', 'warning', 'error'] as $type)
                @if (session($type)) <div class="alert {{ $type }}">{{ session($type) }}</div> @endif
            @endforeach
            @if ($errors->any())
                <div class="alert error"><strong>Please check the form:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            @yield('content')
        </div>
    </main>
</div>
<script src="{{ asset('isp-assets/js/admin.js') }}?v={{ filemtime(public_path('isp-assets/js/admin.js')) }}"></script>
@stack('scripts')
</body>
</html>
