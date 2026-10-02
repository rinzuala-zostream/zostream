@extends('isp.layouts.admin')
@section('title', 'Customers')
@section('eyebrow', 'Subscriber management')

@section('content')
@php
    $visibleCustomers = $customers->getCollection();
    $filterKeys = auth()->user()->isBranchOperator()
        ? ['search', 'status']
        : ['search', 'router_id', 'branch_id', 'status'];
    $activeFilters = collect($filterKeys)
        ->filter(fn ($key) => request()->filled($key))
        ->count();
@endphp

<section class="customers-hero">
    <div class="customers-hero-copy">
        <span>SUBSCRIBER CONTROL</span>
        <h2>PPPoE subscribers</h2>
        <p>Create, renew, suspend and monitor customer access from one familiar workspace.</p>
        <div class="customer-hero-metrics" aria-label="All customer summary">
            <span><b>{{ $visibleCustomers->count() }}</b> on this page</span>
            <a href="{{ route('isp.customers.index', ['status' => 'active']) }}" @class(['is-selected' => request('status') === 'active'])><i class="metric-online"></i><b>{{ $customerSummary['active'] }}</b> active total</a>
            <a href="{{ route('isp.customers.index', ['status' => 'attention']) }}" @class(['is-selected' => request('status') === 'attention'])><i class="metric-alert"></i><b>{{ $customerSummary['needs_attention'] }}</b> need attention</a>
        </div>
    </div>
    <div class="customers-hero-actions">
        @if(auth()->user()->isAdmin())
            <div class="customer-import-menu">
                <a class="customer-hero-button subtle" href="{{ route('isp.customers.export', ['mode' => 'full']) }}">
                    <i aria-hidden="true">↓</i>
                    <span><small>ALL CUSTOMER DATA</small>Full export</span>
                </a>
                <a class="customer-hero-button subtle" href="{{ route('isp.customers.import-mikrotik.create') }}">
                    <i aria-hidden="true">⇄</i>
                    <span><small>ROUTER DATA</small>Import MikroTik</span>
                </a>
                <a class="customer-hero-button subtle" href="{{ route('isp.customers.import.create') }}">
                    <i aria-hidden="true">↥</i>
                    <span><small>SPREADSHEET</small>Import Excel</span>
                </a>
            </div>
        @endif
        <a class="customer-hero-button primary" href="{{ route('isp.customers.create') }}">
            <i aria-hidden="true">+</i>
            <span><small>NEW SUBSCRIBER</small>Add customer</span>
        </a>
    </div>
</section>

<section class="customer-filter-card">
    <div class="customer-filter-heading">
        <div>
            <span>FIND CUSTOMERS</span>
            <strong>Search and filter</strong>
        </div>
        @if($activeFilters)
            <a href="{{ route('isp.customers.index') }}">Clear {{ $activeFilters }} {{ Str::plural('filter', $activeFilters) }}</a>
        @endif
    </div>

    <div class="customer-filter-workspace">
        <form class="customer-filter-form {{ auth()->user()->isBranchOperator() ? 'operator-filter' : '' }}" method="GET">
            <label class="customer-search-field">
                <span aria-hidden="true">⌕</span>
                <input name="search" value="{{ request('search') }}" placeholder="Search name, phone, username or branch">
            </label>
            @if(auth()->user()->isAdmin())
                <label>
                    <span>Router</span>
                    <select name="router_id">
                        <option value="">All routers</option>
                        @foreach($routers as $router)
                            <option value="{{ $router->id }}" @selected((string) request('router_id') === (string) $router->id)>{{ $router->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>Branch</span>
                    <select name="branch_id">
                        <option value="">All branches</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) request('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label>
                <span>Status</span>
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="online" @selected(request('status') === 'online')>Online</option>
                    <option value="offline" @selected(request('status') === 'offline')>Offline</option>
                    <option value="expired" @selected(request('status') === 'expired')>Expired</option>
                    <option value="suspended" @selected(request('status') === 'suspended')>Suspended</option>
                    <option value="unknown" @selected(request('status') === 'unknown')>Unknown / router unreachable</option>
                    <option value="attention" @selected(request('status') === 'attention')>Need attention</option>
                </select>
            </label>
            <button class="customer-filter-button" type="submit">Apply filters</button>
            <button class="customer-filter-button" type="submit" formaction="{{ route('isp.customers.export') }}">Export filtered CSV</button>
            <small>To export, select a status such as Expired. Exports include contact and service details{{ auth()->user()->isBranchOperator() ? ' for your branch only' : '' }}.</small>
        </form>

        @if(auth()->user()->isAdmin())
            <form class="bulk-sync-form" method="POST" action="{{ route('isp.customers.sync-all') }}" data-confirm="Sync all {{ $customers->total() }} customers matching the current filters to RADIUS?">
                @csrf
                <input type="hidden" name="search" value="{{ request('search') }}">
                <input type="hidden" name="router_id" value="{{ request('router_id') }}">
                <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                <input type="hidden" name="status" value="{{ request('status') }}">
                <button class="customer-sync-all" @disabled($customers->total() === 0)>
                    <i aria-hidden="true">↻</i>
                    <span>Sync all <b>{{ $customers->total() }}</b></span>
                </button>
            </form>
        @endif
    </div>
</section>

<div class="customer-list-heading">
    <div>
        <span>CUSTOMER DIRECTORY</span>
        <h3>{{ number_format($customers->total()) }} {{ Str::plural('customer', $customers->total()) }}</h3>
        <p>
            @if($activeFilters)
                Showing customers matching the selected filters.
            @else
                Manage subscriptions, RADIUS access and usage.
            @endif
        </p>
    </div>
    <span class="customer-page-count">Page {{ $customers->currentPage() }} of {{ max($customers->lastPage(), 1) }}</span>
</div>

<section class="customer-card-list">
    @forelse($customers as $customer)
        @php
            $isExpired = $customer->expires_at?->lt(today()) ?? false;
            $displayStatus = $isExpired
                ? 'expired'
                : ($customer->status === 'suspended'
                    ? 'suspended'
                    : ($customer->live_connection_status ?? $customer->status));
            $initials = collect(preg_split('/\s+/', trim($customer->name)))
                ->filter()
                ->take(2)
                ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                ->implode('');
            $daysToExpiry = $customer->expires_at
                ? (int) today()->diffInDays($customer->expires_at, false)
                : null;
            $expirySummary = match (true) {
                $daysToExpiry === null => 'No expiry scheduled',
                $daysToExpiry < 0 => abs($daysToExpiry).' '.Str::plural('day', abs($daysToExpiry)).' overdue',
                $daysToExpiry === 0 => 'Ends today',
                $daysToExpiry === 1 => 'Ends tomorrow',
                default => $daysToExpiry.' days remaining',
            };
            $attentionReasons = collect([
                ! $isExpired && $customer->status === 'suspended' ? 'Customer access suspended' : null,
                $isExpired ? 'Internet plan expired' : null,
                ! in_array($customer->router_device_condition, ['old', 'new'], true) ? 'Unknown customer device' : null,
                $customer->router_device_condition === 'new' && $customer->routerPayment?->status === 'unpaid'
                    ? 'New customer device · unpaid'
                    : null,
            ])->filter();
        @endphp
        <article class="customer-card customer-card--{{ $displayStatus }} {{ auth()->user()->isBranchOperator() ? 'operator-customer-card' : '' }}">
            <div class="customer-card-top">
                <div class="customer-main">
                    <div class="customer-avatar"><span>{{ $initials ?: '?' }}</span></div>
                    <div class="customer-identity">
                        <h3>{{ $customer->name }}</h3>
                        <div class="customer-contact-row">
                            <code><i aria-hidden="true">@</i>{{ $customer->username }}</code>
                            <span><i aria-hidden="true">☎</i>{{ $customer->phone ?: 'No phone' }}</span>
                        </div>
                    </div>
                </div>
                <div class="customer-card-tools">
                    <span class="customer-status status-{{ $displayStatus }}"><i></i>{{ ucfirst($displayStatus) }}</span>
                    <div class="customer-action-menu" data-customer-menu>
                        <button class="customer-menu-trigger" type="button" aria-label="Actions for {{ $customer->name }}" aria-haspopup="menu" aria-expanded="false">•••</button>
                        <div class="customer-menu-popover" role="menu" hidden>
                            <div class="customer-menu-heading"><span>QUICK ACTIONS</span><strong>{{ $customer->name }}</strong></div>
                            <a class="customer-menu-item is-primary" role="menuitem" href="{{ route('isp.payments.index', ['customer' => $customer]) }}"><i aria-hidden="true">₹</i><span><strong>Collect payment</strong><small>Renew internet for 30 days</small></span></a>
                            @if($isExpired)
                                <button class="customer-menu-item" role="menuitem" type="button" disabled><i aria-hidden="true">⌛</i><span><strong>Expired plan</strong><small>Collect payment to restore access</small></span></button>
                            @else
                                <form method="POST" action="{{ route('isp.customers.toggle', $customer) }}">
                                    @csrf
                                    <button class="customer-menu-item" role="menuitem" type="submit"><i aria-hidden="true">{{ $customer->status === 'active' ? 'Ⅱ' : '▶' }}</i><span><strong>{{ $customer->status === 'active' ? 'Suspend access' : 'Activate access' }}</strong><small>{{ $customer->status === 'active' ? 'Pause PPPoE authentication' : 'Restore PPPoE authentication' }}</small></span></button>
                                </form>
                            @endif
                            @if(auth()->user()->isAdmin())
                                <form method="POST" action="{{ route('isp.customers.sync', $customer) }}">
                                    @csrf
                                    <button class="customer-menu-item" role="menuitem" type="submit"><i aria-hidden="true">↻</i><span><strong>Sync RADIUS</strong><small>Push the latest account details</small></span></button>
                                </form>
                            @endif
                            <a class="customer-menu-item" role="menuitem" href="{{ route('isp.customers.edit', ['customer' => $customer, 'return_to' => request()->fullUrl()]) }}"><i aria-hidden="true">✎</i><span><strong>Edit customer</strong><small>Update profile and service details</small></span></a>
                            <div class="customer-menu-divider"></div>
                            <form data-confirm="Delete this customer from both the admin panel and RADIUS?" method="POST" action="{{ route('isp.customers.destroy', $customer) }}">
                                @csrf
                                @method('DELETE')
                                <button class="customer-menu-item is-danger" role="menuitem" type="submit"><i aria-hidden="true">×</i><span><strong>Delete customer</strong><small>Remove from the panel and RADIUS</small></span></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            @if($attentionReasons->isNotEmpty())
                <div class="customer-attention-summary">
                    <strong><i aria-hidden="true">!</i> Needs attention</strong>
                    <div>@foreach($attentionReasons as $reason)<span>{{ $reason }}</span>@endforeach</div>
                </div>
            @endif

            @if(auth()->user()->isAdmin())
                <div class="customer-service-grid">
                    <div class="customer-info-tile customer-plan-tile">
                        <span class="customer-tile-icon" aria-hidden="true">◇</span>
                        <div><small>INTERNET PACKAGE</small><strong>{{ $customer->package?->name ?? 'No package' }}</strong><span>{{ $customer->package?->rate_limit ?: 'Unlimited speed' }} @if($customer->package) · ₹{{ number_format($customer->package->price, 0) }}@endif</span></div>
                    </div>
                    <div class="customer-info-tile">
                        <span class="customer-tile-icon router" aria-hidden="true">⌁</span>
                        <div><small>NETWORK ROUTER</small><strong>{{ $customer->router?->name ?? 'Not assigned' }}</strong><span>{{ ucfirst($customer->router_device_condition ?? 'unknown') }} customer device @if($customer->routerPayment) · {{ str_replace('_', ' ', $customer->routerPayment->status) }}@endif</span></div>
                    </div>
                    <div class="customer-info-tile">
                        <span class="customer-tile-icon branch" aria-hidden="true">⌖</span>
                        <div><small>BRANCH</small><strong>{{ $customer->branch?->name ?? 'No branch' }}</strong><span>{{ $customer->address ? Str::limit($customer->address, 42) : 'No installation address' }}</span></div>
                    </div>
                </div>
            @endif

            <div class="customer-card-footer">
                <div class="customer-usage-summary">
                    <div class="customer-footer-title"><span aria-hidden="true">↕</span><div><small>DATA USAGE</small><strong>{{ $customer->usage_last_at ? 'Updated '.$customer->usage_last_at->diffForHumans() : 'No accounting data yet' }}</strong></div></div>
                    <div class="customer-traffic-pills">
                        <span class="usage-download"><i>↓</i><b>{{ \Illuminate\Support\Number::fileSize($customer->usage_download_bytes, 2) }}</b><small>download</small></span>
                        <span class="usage-upload"><i>↑</i><b>{{ \Illuminate\Support\Number::fileSize($customer->usage_upload_bytes, 2) }}</b><small>upload</small></span>
                    </div>
                </div>
                @if(auth()->user()->isAdmin())
                    <div class="customer-expiry-summary {{ $isExpired ? 'is-expired' : '' }}">
                        <div class="customer-expiry-date"><b>{{ $customer->expires_at?->format('d') ?? '—' }}</b><span>{{ $customer->expires_at?->format('M Y') ?? 'No date' }}</span></div>
                        <div><small>PLAN EXPIRY</small><strong>{{ $expirySummary }}</strong><span>{{ $customer->last_synced_at ? 'RADIUS synced '.$customer->last_synced_at->diffForHumans() : 'RADIUS not synced yet' }}</span></div>
                    </div>
                @endif
            </div>
        </article>
    @empty
        <article class="customer-empty-card">
            <span>♙</span>
            <strong>No customer found</strong>
            <p>Try clearing the filters or add a new PPPoE subscriber.</p>
            @if($activeFilters)
                <a class="button secondary" href="{{ route('isp.customers.index') }}">Clear filters</a>
            @else
                <a class="button primary" href="{{ route('isp.customers.create') }}">+ Add customer</a>
            @endif
        </article>
    @endforelse
</section>

<div class="pagination customer-pagination">{{ $customers->links() }}</div>
@endsection

@push('scripts')
<script>
(() => {
    const menus = [...document.querySelectorAll('[data-customer-menu]')];
    if (!menus.length) return;

    const closeMenu = menu => {
        const trigger = menu.querySelector('.customer-menu-trigger');
        const popover = menu.querySelector('.customer-menu-popover');
        popover.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        menu.closest('.customer-card')?.classList.remove('is-menu-open');
    };
    const closeAll = except => menus.forEach(menu => {
        if (menu !== except) closeMenu(menu);
    });

    menus.forEach(menu => {
        const trigger = menu.querySelector('.customer-menu-trigger');
        const popover = menu.querySelector('.customer-menu-popover');
        trigger.addEventListener('click', event => {
            event.stopPropagation();
            const willOpen = popover.hidden;
            closeAll(menu);
            popover.hidden = !willOpen;
            trigger.setAttribute('aria-expanded', String(willOpen));
            menu.closest('.customer-card')?.classList.toggle('is-menu-open', willOpen);
            if (willOpen) popover.querySelector('[role="menuitem"]')?.focus();
        });
        menu.addEventListener('click', event => event.stopPropagation());
    });

    document.addEventListener('click', () => closeAll());
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const openMenu = menus.find(menu => !menu.querySelector('.customer-menu-popover').hidden);
        if (!openMenu) return;
        closeMenu(openMenu);
        openMenu.querySelector('.customer-menu-trigger')?.focus();
    });
})();

(() => {
    const form = document.querySelector('.bulk-sync-form');
    if (!form) return;
    const button = form.querySelector('button');
    const buttonLabel = button.querySelector('span');
    let afterId = 0;
    let synced = 0;
    let failed = 0;
    let running = false;

    form.addEventListener('submit', async event => {
        if (event.defaultPrevented || running) return;
        event.preventDefault();
        running = true;
        button.disabled = true;

        try {
            let hasMore = true;
            while (hasMore) {
                const data = new FormData(form);
                data.set('after_id', afterId);
                data.set('synced_total', synced);
                data.set('failed_total', failed);
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: data,
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                });
                if (!response.ok) throw new Error(`Sync request failed with HTTP ${response.status}`);
                const result = await response.json();
                afterId = result.next_after_id;
                synced = result.synced_total;
                failed = result.failed_total;
                hasMore = result.has_more;
                buttonLabel.textContent = `Syncing ${result.processed}/${result.total} · ${failed} failed`;
            }
            window.location.reload();
        } catch (error) {
            running = false;
            button.disabled = false;
            buttonLabel.textContent = `Retry sync · ${synced} synced, ${failed} failed`;
            console.error(error);
        }
    });
})();
</script>
@endpush
