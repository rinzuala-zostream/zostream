<?php

namespace App\Isp\Http\Controllers;

use App\Isp\Models\Branch;
use App\Isp\Models\Customer;
use App\Isp\Models\CustomerOnboarding;
use App\Isp\Models\Package;
use App\Isp\Models\Router;
use App\Isp\Services\CustomerOnboardingService;
use App\Isp\Services\MikroTikService;
use App\Isp\Services\RadiusService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CustomerController extends Controller
{
    public function index(Request $request, MikroTikService $mikrotik): View
    {
        $liveStatusIds = $this->liveStatusIds($request, $mikrotik);
        $customers = $this->filteredQuery($request, $liveStatusIds)
            ->with(['router', 'package', 'branch', 'routerPayment'])
            ->orderBy('username')->paginate(15)->withQueryString();
        $this->attachAccountingUsage($customers->getCollection());

        return view('isp.customers.index', [
            'customers' => $customers,
            'routers' => $request->user()->isBranchOperator()
                ? Router::whereKey($request->user()->branch?->router_id)->get()
                : Router::orderBy('name')->get(),
            'branches' => $request->user()->isBranchOperator()
                ? Branch::whereKey($request->user()->branch_id)->get()
                : Branch::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $operatorBranch = request()->user()->isBranchOperator()
            ? Branch::with(['packages:id', 'router:id,name,is_active'])->find(request()->user()->branch_id)
            : null;

        return view('isp.customers.form', [
            'customer' => new Customer,
            'routers' => Router::where('is_active', true)->get(),
            'packages' => Package::where('is_active', true)->get(),
            'branches' => request()->user()->isBranchOperator()
                ? collect([$operatorBranch])->filter()
                : Branch::with('packages:id')->where('is_active', true)->orderBy('name')->get(),
            'operatorBranch' => $operatorBranch,
        ]);
    }

    public function export(Request $request, MikroTikService $mikrotik): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || ($user->isBranchOperator() && $user->branch_id), 403);
        // Full records include credentials and financial details: never expose them to operators.
        if ($request->input('mode') === 'full') {
            abort_unless($user->isAdmin(), 403);

            return $this->fullExport();
        }

        $request->validate([
            'mode' => ['nullable', Rule::in(['filtered'])],
            'status' => ['required', Rule::in(['active', 'suspended', 'expired', 'online', 'offline', 'unknown'])],
            'search' => ['nullable', 'string', 'max:150'],
            'router_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $query = $this->filteredQuery($request, $this->liveStatusIds($request, $mikrotik));
        $columns = ['id', 'name', 'phone', 'username', 'branch_id', 'status', 'expires_at'];

        return response()->streamDownload(function () use ($query, $columns): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the customer export stream.');
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $columns, ',', '"', '');
            $query->select($columns)->chunkById(250, function ($customers) use ($output): void {
                foreach ($customers as $customer) {
                    fputcsv($output, array_map($this->exportValue(...), [
                        $customer->id, $customer->name, $customer->phone, $customer->username,
                        $customer->branch_id, $customer->status, $customer->expires_at?->toDateString(),
                    ]), ',', '"', '');
                }
            });
            fclose($output);
        }, 'isp-customers-'.$request->input('status').'-'.today()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function fullExport(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the customer export stream.');
            }

            // The UTF-8 BOM keeps names and addresses readable when opened in Excel.
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'customer_id', 'name', 'phone', 'installation_address',
                'branch_id', 'branch', 'router_id', 'router', 'router_host',
                'package_id', 'package', 'mikrotik_profile', 'rate_limit',
                'package_price', 'package_validity_days', 'pppoe_username',
                'pppoe_password', 'status', 'expires_at', 'wifi_reminder_sent_for',
                'expiry_suspended_for', 'router_device_condition',
                'mikrotik_id', 'last_synced_at',
                'payment_count', 'payment_total', 'last_payment_at',
                'router_payment_amount', 'router_payment_status',
                'router_payment_method', 'router_payment_reference',
                'router_payment_notes', 'router_paid_at', 'created_at', 'updated_at',
            ], ',', '"', '');

            Customer::query()
                ->with(['branch', 'router', 'package', 'routerPayment'])
                ->withCount('payments')
                ->withSum('payments', 'amount')
                ->withMax('payments', 'paid_at')
                ->orderBy('id')
                ->chunkById(250, function ($customers) use ($output): void {
                    foreach ($customers as $customer) {
                        fputcsv($output, array_map($this->exportValue(...), [
                            $customer->id,
                            $customer->name,
                            $customer->phone,
                            $customer->address,
                            $customer->branch_id,
                            $customer->branch?->name,
                            $customer->router_id,
                            $customer->router?->name,
                            $customer->router?->host,
                            $customer->package_id,
                            $customer->package?->name,
                            $customer->package?->mikrotik_profile,
                            $customer->package?->rate_limit,
                            $customer->package?->price,
                            $customer->package?->validity_days,
                            $customer->username,
                            $customer->password,
                            $customer->status,
                            $customer->expires_at?->toDateString(),
                            $customer->wifi_reminder_sent_for?->toDateString(),
                            $customer->expiry_suspended_for?->toDateString(),
                            $customer->router_device_condition,
                            $customer->mikrotik_id,
                            $customer->last_synced_at?->toDateTimeString(),
                            $customer->payments_count,
                            $customer->payments_sum_amount,
                            $customer->payments_max_paid_at,
                            $customer->routerPayment?->amount,
                            $customer->routerPayment?->status,
                            $customer->routerPayment?->method,
                            $customer->routerPayment?->gateway_payment_id
                                ?: $customer->routerPayment?->gateway_order_id,
                            $customer->routerPayment?->notes,
                            $customer->routerPayment?->paid_at?->toDateTimeString(),
                            $customer->created_at?->toDateTimeString(),
                            $customer->updated_at?->toDateTimeString(),
                        ]), ',', '"', '');
                    }
                });

            fclose($output);
        }, 'isp-customers-full-'.today()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function store(
        Request $request,
        CustomerOnboardingService $onboarding,
    ): RedirectResponse|JsonResponse {
        $data = $this->validated($request);

        $customerData = Arr::only($data, [
            'router_id', 'package_id', 'branch_id', 'name', 'phone', 'address', 'username',
            'password', 'status', 'router_device_condition', 'expires_at',
        ]);
        $storedPaths = [];

        try {
            foreach (['aadhaar_front', 'aadhaar_back'] as $side) {
                if ($request->hasFile($side)) {
                    $path = $request->file($side)->store('isp/customers/aadhaar', 'local');
                    $customerData[$side.'_path'] = $path;
                    $storedPaths[] = $path;
                }
            }

            $condition = $data['router_device_condition'];
            $paymentChoice = $condition === 'new' ? ($data['router_payment_choice'] ?? null) : null;
            $amount = $paymentChoice === 'pay_now' ? (float) ($data['router_amount'] ?? 0) : 0;
            $notes = $paymentChoice === 'pay_later' ? ($data['router_payment_note'] ?? null) : null;

            if ($condition === 'new' && $paymentChoice === 'pay_now') {
                $pending = $onboarding->createCashfreeOnboarding(
                    $customerData,
                    $amount,
                    $notes,
                    $request->user()->id,
                );

                return response()->json([
                    'requires_payment' => true,
                    'onboarding_id' => $pending->id,
                    'order_id' => $pending->cashfree_order_id,
                    'payment_session_id' => $pending->payment_session_id,
                    'mode' => strtoupper((string) config('cashfree.env', 'SANDBOX')) === 'PRODUCTION'
                        ? 'production'
                        : 'sandbox',
                ], 201);
            }

            $result = $onboarding->createWithoutPayment(
                $customerData,
                $condition,
                $amount,
                $notes,
                $request->user()->id,
            );
        } catch (Throwable $e) {
            Storage::disk('local')->delete($storedPaths);
            report($e);

            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = 'Customer created; ZoStream Mobile and TV access activated.';
        $warnings = array_filter([$result['sync_error'], $result['activation_error']]);
        if ($warnings) {
            return redirect()->route('isp.customers.index')->with(
                'warning',
                'Customer created, but follow-up needs a retry: '.implode(' ', $warnings),
            );
        }

        return redirect()->route('isp.customers.index')->with('success', $message);
    }

    public function edit(Request $request, Customer $customer): View
    {
        $this->ensureCustomerAccess($request, $customer);

        return view('isp.customers.form', [
            'customer' => $customer,
            'routers' => Router::where('is_active', true)->get(),
            'packages' => Package::where('is_active', true)->get(),
            'branches' => $request->user()->isBranchOperator()
                ? Branch::with('packages:id')->whereKey($request->user()->branch_id)->get()
                : Branch::with('packages:id')->where('is_active', true)
                    ->when($customer->branch_id, fn ($query) => $query->orWhere('id', $customer->branch_id))
                    ->orderBy('name')->get(),
            'returnTo' => $this->customerIndexReturnUrl($request),
        ]);
    }

    public function update(
        Request $request,
        Customer $customer,
        RadiusService $radius,
    ): RedirectResponse {
        $this->ensureCustomerAccess($request, $customer);
        $data = $this->validated($request, $customer);
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        unset($data['router_payment_choice'], $data['router_amount'], $data['router_payment_note']);
        $oldPaths = [];
        $newPaths = [];
        foreach (['aadhaar_front', 'aadhaar_back'] as $side) {
            unset($data[$side]);
            if ($request->hasFile($side)) {
                $column = $side.'_path';
                $oldPaths[] = $customer->{$column};
                $data[$column] = $request->file($side)->store('isp/customers/aadhaar', 'local');
                $newPaths[] = $data[$column];
            }
        }
        try {
            $customer->update($data);
            Storage::disk('local')->delete(array_filter($oldPaths));
        } catch (Throwable $e) {
            Storage::disk('local')->delete($newPaths);
            throw $e;
        }

        return $this->syncAndRedirect(
            $customer,
            $radius,
            'Customer updated',
            $this->customerIndexReturnUrl($request),
        );
    }

    public function destroy(Customer $customer, RadiusService $radius): RedirectResponse
    {
        $this->ensureCustomerAccess(request(), $customer);

        try {
            $removed = $radius->deleteCustomer($customer);
            try {
                $radius->disconnect($customer);
            } catch (Throwable $e) {
                report($e);
            }
            $documentPaths = [$customer->aadhaar_front_path, $customer->aadhaar_back_path];
            $customer->delete();
            Storage::disk('local')->delete(array_filter($documentPaths));

            $message = $removed
                ? 'Customer deleted from the admin panel and RADIUS.'
                : 'Customer deleted from the admin panel; no matching RADIUS credentials existed.';

            return back()->with('success', $message);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Customer was not deleted because RADIUS cleanup failed: '.$e->getMessage());
        }
    }

    public function sync(Customer $customer, RadiusService $radius): RedirectResponse
    {
        $this->ensureCustomerAccess(request(), $customer);

        return $this->syncAndRedirect($customer, $radius, 'Customer');
    }

    public function completeOnboarding(
        Request $request,
        CustomerOnboardingService $service,
    ): JsonResponse {
        $data = $request->validate([
            'onboarding_id' => ['required', 'integer', 'exists:customer_onboardings,id'],
            'order_id' => ['required', 'string', 'max:100'],
        ]);
        $onboarding = CustomerOnboarding::query()
            ->where('operator_id', $request->user()->id)
            ->findOrFail($data['onboarding_id']);
        if (! hash_equals((string) $onboarding->cashfree_order_id, $data['order_id'])) {
            return response()->json(['message' => 'Cashfree order does not match this onboarding.'], 422);
        }

        try {
            $result = $service->completePaidOnboarding($onboarding);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 409);
        }

        $message = $result['created']
            ? 'Router payment verified and customer created.'
            : 'Customer was already created for this payment.';
        $warnings = array_filter([$result['sync_error'], $result['activation_error']]);
        session()->flash($warnings ? 'warning' : 'success', $warnings
            ? $message.' Follow-up needs a retry: '.implode(' ', $warnings)
            : $message.' ZoStream Mobile and TV access activated.');

        return response()->json([
            'message' => $message,
            'customer_id' => $result['customer']->id,
            'redirect' => route('isp.customers.index'),
        ]);
    }

    public function document(Request $request, Customer $customer, string $side): StreamedResponse
    {
        $this->ensureCustomerAccess($request, $customer);
        abort_unless(in_array($side, ['front', 'back'], true), 404);
        $path = $side === 'front' ? $customer->aadhaar_front_path : $customer->aadhaar_back_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, "aadhaar-{$side}-customer-{$customer->id}.".pathinfo($path, PATHINFO_EXTENSION));
    }

    public function syncAll(Request $request, RadiusService $radius, MikroTikService $mikrotik): RedirectResponse|JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'online', 'offline', 'expired', 'unknown'])],
            'router_id' => ['nullable', 'exists:routers,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'after_id' => ['nullable', 'integer', 'min:0'],
            'synced_total' => ['nullable', 'integer', 'min:0'],
            'failed_total' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! $request->expectsJson()) {
            return back()->with('warning', 'Bulk sync uses short background batches to prevent a gateway timeout. Refresh this page and click Sync all again.');
        }

        $query = $this->filteredQuery($request, $this->liveStatusIds($request, $mikrotik));
        $total = (clone $query)->count();
        $afterId = $request->integer('after_id');
        $customers = (clone $query)->with(['router', 'package', 'branch'])
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(8)
            ->get();
        $synced = 0;
        $failed = 0;
        $errors = [];
        foreach ($customers as $customer) {
            try {
                $radius->syncCustomer($customer);
                $synced++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
                if (count($errors) < 5) {
                    $errors[] = "{$customer->username}: {$e->getMessage()}";
                }
            }
        }

        $lastId = $customers->last()?->id ?? $afterId;
        $hasMore = (clone $query)->where('id', '>', $lastId)->exists();
        $syncedTotal = $request->integer('synced_total') + $synced;
        $failedTotal = $request->integer('failed_total') + $failed;
        $processed = (clone $query)->where('id', '<=', $lastId)->count();

        if (! $hasMore) {
            $type = $failedTotal > 0 ? 'warning' : 'success';
            session()->flash($type, "Bulk sync complete — {$syncedTotal} synced, {$failedTotal} failed.");
        }

        return response()->json([
            'total' => $total,
            'processed' => $processed,
            'batch_synced' => $synced,
            'batch_failed' => $failed,
            'synced_total' => $syncedTotal,
            'failed_total' => $failedTotal,
            'next_after_id' => $lastId,
            'has_more' => $hasMore,
            'errors' => $errors,
        ]);
    }

    public function toggle(Customer $customer, RadiusService $radius): RedirectResponse
    {
        $this->ensureCustomerAccess(request(), $customer);

        if ($customer->status === 'suspended' && $customer->expires_at?->lt(today())) {
            return redirect()->route('isp.customers.index')->with(
                'warning',
                'This customer is expired. Record a payment/renewal or move the expiry date before activating PPPoE.'
            );
        }

        $customer->update(['status' => $customer->status === 'active' ? 'suspended' : 'active']);

        return $this->syncAndRedirect($customer, $radius, ucfirst($customer->status));
    }

    private function syncAndRedirect(
        Customer $customer,
        RadiusService $radius,
        string $message,
        ?string $redirectTo = null,
    ): RedirectResponse {
        $redirectTo ??= route('isp.customers.index');

        try {
            $radius->syncCustomer($customer);

            return redirect()->to($redirectTo)->with('success', $message.' and synced with RADIUS.');
        } catch (Throwable $e) {
            report($e);

            return redirect()->to($redirectTo)->with('warning', $message.' locally, but RADIUS sync failed: '.$e->getMessage());
        }
    }

    private function customerIndexReturnUrl(Request $request): string
    {
        $fallback = route('isp.customers.index');
        $candidate = trim((string) $request->input('return_to', ''));
        if ($candidate === '') {
            return $fallback;
        }

        $parts = parse_url($candidate);
        $customerIndexPath = parse_url($fallback, PHP_URL_PATH);
        if ($parts === false || ($parts['path'] ?? '') !== $customerIndexPath) {
            return $fallback;
        }
        if (isset($parts['host']) && ! hash_equals(Str::lower($request->getHost()), Str::lower($parts['host']))) {
            return $fallback;
        }

        return $candidate;
    }

    private function filteredQuery(Request $request, array $liveStatusIds = []): Builder
    {
        return $this->baseCustomerQuery($request)
            ->when($request->input('status') === 'active', fn ($q) => $q
                ->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', today())))
            ->when($request->input('status') === 'suspended', fn ($q) => $q->where('status', 'suspended'))
            ->when($request->input('status') === 'expired', fn ($q) => $q->whereDate('expires_at', '<', today()))
            ->when(in_array($request->input('status'), ['online', 'offline', 'unknown'], true), fn ($q) => $q
                ->whereIn('id', $liveStatusIds[$request->input('status')] ?? []));
    }

    private function baseCustomerQuery(Request $request): Builder
    {
        return Customer::query()
            ->when($request->user()?->isBranchOperator(), fn ($query) => $query
                ->where('branch_id', $request->user()->branch_id))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('username', 'like', '%'.$request->string('search').'%')
                ->orWhere('phone', 'like', '%'.$request->string('search').'%')
                ->orWhereHas('branch', fn ($branch) => $branch
                    ->where('name', 'like', '%'.$request->string('search').'%'))))
            ->when(! $request->user()?->isBranchOperator() && $request->filled('router_id'), fn ($q) => $q->where('router_id', $request->integer('router_id')))
            ->when(! $request->user()?->isBranchOperator() && $request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')));
    }

    private function liveStatusIds(Request $request, MikroTikService $mikrotik): array
    {
        if (! in_array($request->input('status'), ['online', 'offline', 'unknown'], true)) {
            return [];
        }

        $candidates = $this->baseCustomerQuery($request)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()))
            ->with('router')
            ->get(['id', 'router_id', 'username']);
        $ids = ['online' => [], 'offline' => [], 'unknown' => []];

        foreach ($candidates->groupBy('router_id') as $routerCustomers) {
            $router = $routerCustomers->first()->router;
            if (! $router?->is_active) {
                array_push($ids['unknown'], ...$routerCustomers->pluck('id')->all());

                continue;
            }

            try {
                $sessions = Cache::remember(
                    "dashboard.router.{$router->id}.ppp-active",
                    now()->addSeconds(20),
                    fn () => $mikrotik->activePppUsers($router),
                );
                $activeNames = collect($sessions)->pluck('name')->filter()
                    ->map(fn ($name) => mb_strtolower((string) $name));

                foreach ($routerCustomers as $customer) {
                    $status = $activeNames->contains(mb_strtolower($customer->username)) ? 'online' : 'offline';
                    $ids[$status][] = $customer->id;
                }
            } catch (Throwable $e) {
                report($e);
                array_push($ids['unknown'], ...$routerCustomers->pluck('id')->all());
            }
        }

        return $ids;
    }

    private function attachAccountingUsage($customers): void
    {
        if ($customers->isEmpty()) {
            return;
        }

        $routerIds = $customers->pluck('router_id')->unique()->values();
        $routerHosts = $customers->pluck('router.host')->filter()->unique()->values();
        $usernames = $customers->pluck('username')->unique()->values();
        $routerIdByHost = $customers->pluck('router_id', 'router.host');
        $usage = [];

        DB::table('radacct')
            ->selectRaw('router_id, nasipaddress, username, SUM(COALESCE(acctinputoctets, 0)) as upload_bytes, SUM(COALESCE(acctoutputoctets, 0)) as download_bytes, MAX(COALESCE(acctupdatetime, acctstoptime, acctstarttime)) as last_activity_at')
            ->whereIn('username', $usernames)
            ->where(function ($query) use ($routerIds, $routerHosts): void {
                $query->whereIn('router_id', $routerIds)
                    ->orWhere(function ($query) use ($routerHosts): void {
                        $query->whereNull('router_id')->whereIn('nasipaddress', $routerHosts);
                    });
            })
            ->groupBy(['router_id', 'nasipaddress', 'username'])
            ->get()
            ->each(function ($row) use (&$usage, $routerIdByHost): void {
                $routerId = $row->router_id ?: $routerIdByHost->get($row->nasipaddress);
                if (! $routerId) {
                    return;
                }

                $key = $routerId.'|'.$row->username;
                $usage[$key]['upload'] = ($usage[$key]['upload'] ?? 0) + (int) $row->upload_bytes;
                $usage[$key]['download'] = ($usage[$key]['download'] ?? 0) + (int) $row->download_bytes;
                if ($row->last_activity_at && ($usage[$key]['last'] ?? null) < $row->last_activity_at) {
                    $usage[$key]['last'] = $row->last_activity_at;
                }
            });

        $customers->each(function (Customer $customer) use ($usage): void {
            $totals = $usage[$customer->router_id.'|'.$customer->username] ?? [];
            $customer->setAttribute('usage_upload_bytes', $totals['upload'] ?? 0);
            $customer->setAttribute('usage_download_bytes', $totals['download'] ?? 0);
            $customer->setAttribute(
                'usage_last_at',
                isset($totals['last']) ? Carbon::parse($totals['last']) : null,
            );
        });
    }

    private function validated(Request $request, ?Customer $customer = null): array
    {
        $data = $request->validate([
            'router_id' => $request->user()->isBranchOperator()
                ? ['nullable']
                : array_values(array_filter([
                    'required',
                    'exists:routers,id',
                    $customer ? Rule::in([$customer->router_id]) : null,
                ])),
            'package_id' => ['required', 'exists:packages,id'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => [
                $customer ? 'nullable' : 'required',
                'string',
                'max:30',
                function (string $attribute, mixed $value, \Closure $fail) use ($customer): void {
                    if (! $customer && strlen(substr(preg_replace('/\D+/', '', (string) $value) ?: '', -10)) !== 10) {
                        $fail('Enter a valid 10-digit phone number for the ZoStream account.');
                    }
                },
            ],
            'branch_id' => $request->user()->isBranchOperator()
                ? ['required', Rule::in([$request->user()->branch_id])]
                : ['nullable', 'exists:branches,id'],
            'address' => ['nullable', 'string', 'max:1000'],
            'username' => [
                'required',
                'string',
                'max:64',
                Rule::unique('customers', 'username')->ignore($customer),
            ],
            'password' => [$customer ? 'nullable' : 'required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'expires_at' => $request->user()->isAdmin()
                ? ['nullable', 'date']
                : ['prohibited'],
            'router_device_condition' => ['required', Rule::in(['old', 'new'])],
            'router_payment_choice' => [$customer ? 'nullable' : Rule::requiredIf(fn (): bool => $request->input('router_device_condition') === 'new'), Rule::in(['pay_now', 'pay_later'])],
            'router_amount' => [Rule::requiredIf(fn (): bool => ! $customer && $request->input('router_device_condition') === 'new' && $request->input('router_payment_choice') === 'pay_now'), 'nullable', 'numeric', 'min:1', 'max:999999.99'],
            'router_payment_note' => [Rule::requiredIf(fn (): bool => ! $customer && $request->input('router_device_condition') === 'new' && $request->input('router_payment_choice') === 'pay_later'), 'nullable', 'string', 'max:1000'],
            'aadhaar_front' => [
                $customer?->aadhaar_front_path ? 'nullable' : 'required',
                'required_with:aadhaar_back',
                File::image()->max(5 * 1024),
            ],
            'aadhaar_back' => [
                $customer?->aadhaar_back_path ? 'nullable' : 'required',
                'required_with:aadhaar_front',
                File::image()->max(5 * 1024),
            ],
        ]);

        if ($request->user()->isBranchOperator()) {
            $data['branch_id'] = $request->user()->branch_id;
            if ($customer) {
                $data['router_id'] = $customer->router_id;

                return $data;
            }
            $branch = Branch::with('router')->find($request->user()->branch_id);
            if (! $branch?->router_id || ! $branch->router?->is_active) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'router_id' => 'Your branch does not have an active default router. Ask an administrator to assign one.',
                ]);
            }
            $data['router_id'] = $branch->router_id;
        }
        if (! empty($data['branch_id'])) {
            $branch = Branch::with('packages:id')->find($data['branch_id']);
            if ($branch && $branch->packages->isNotEmpty() && ! $branch->packages->contains((int) $data['package_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'package_id' => 'The selected package is not available for this branch.',
                ]);
            }
        }

        return $data;
    }

    private function ensureCustomerAccess(Request $request, Customer $customer): void
    {
        if ($request->user()?->isBranchOperator()) {
            abort_unless($customer->branch_id === $request->user()->branch_id, 403);
        }
    }

    private function exportValue(mixed $value): string|int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $value = (string) ($value ?? '');

        // Prevent spreadsheet applications from executing customer-controlled formulae.
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
