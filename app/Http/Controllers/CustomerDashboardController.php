<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\V4\CustomerStatsController;
use App\Models\New\Devices;
use App\Models\New\Subscription;
use App\Models\UserModel;
use App\Support\CustomerPaymentHistory;
use App\Support\CustomerStatsPeriod;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CustomerDashboardController extends Controller
{
    public function overview(Request $request, CustomerStatsController $stats)
    {
        $uid = $this->identity($request);
        $filters = CustomerStatsPeriod::filters($request);
        $user = UserModel::where('uid', $uid)->first();
        $history = [];
        $historyError = false;
        if (Schema::hasTable('watch_position')) {
            try {
                $historyRequest = Request::create('/', 'GET', ['userId' => $uid, 'per_page' => 8]);
                $history = app(WatchPositionController::class)->getWatchContinue($historyRequest)->getData(true)['watch_history'] ?? [];
            } catch (\Throwable $exception) {
                report($exception);
                $historyError = true;
            }
        }

        return $this->json([
            'profile' => ['name' => $user?->name, 'username' => $user?->username],
            'stats' => $stats->dataForUser($uid, 1, 10, $filters),
            'continue_watching' => collect($history)->filter(fn ($item) => ! $item['duration'] || $item['position'] / $item['duration'] < .9)->take(4)->values(),
            'continue_unavailable' => $historyError,
        ]);
    }

    public function watching(Request $request, CustomerStatsController $stats)
    {
        $uid = $this->identity($request);
        $filters = CustomerStatsPeriod::filters($request);

        return $this->json($stats->dataForUser($uid, (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 10), $filters));
    }

    public function account(Request $request)
    {
        $uid = $this->identity($request);
        $subscriptions = Schema::hasTable('n_subscriptions') ? Subscription::with('plan')->where('user_id', $uid)->orderByDesc('end_at')->get() : collect();
        $devices = Schema::hasTable('n_devices') ? Devices::where('user_id', $uid)->orderByDesc('last_activity')->get() : collect();

        return $this->json([
            'subscriptions' => $subscriptions->map(function ($item) {
                $active = $item->isActive() && (! $item->start_at || $item->start_at->startOfDay()->lte(now()));

                return [
                    'id' => $item->id,
                    'plan' => $item->plan?->name ?? 'Subscription',
                    'device_type' => $item->plan?->device_type,
                    'valid_from' => $item->start_at?->toDateString(),
                    'valid_until' => $item->end_at?->toDateString(),
                    'status' => $active ? 'active' : ($item->start_at?->isFuture() && $item->is_active ? 'upcoming' : 'expired'),
                ];
            }),
            'devices' => $devices->map(fn ($item) => [
                'id' => $item->id, 'name' => $item->device_name ?: 'Zo Stream device',
                'type' => $item->device_type, 'status' => $item->status,
                'last_active' => $item->last_activity?->toIso8601String(), 'owner' => $item->is_owner_device,
            ]),
        ]);
    }

    public function payments(Request $request, CustomerPaymentHistory $history)
    {
        $uid = $this->identity($request);
        $filters = CustomerStatsPeriod::filters($request);
        $rows = $history->filtered($uid, $filters)->paginate((int) ($filters['per_page'] ?? 10));
        [$from, $to] = CustomerStatsPeriod::range($filters);

        return $this->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'items' => $rows->getCollection()->map(fn ($payment) => $history->format($payment)),
            'pagination' => ['page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    public function export(Request $request, CustomerPaymentHistory $history)
    {
        $uid = $this->identity($request);
        $filters = CustomerStatsPeriod::filters($request);
        [$from, $to] = CustomerStatsPeriod::range($filters);
        $query = $history->filtered($uid, $filters);

        return response()->streamDownload(function () use ($query, $history) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Receipt reference', 'Plan', 'Device', 'Payment date', 'Amount', 'Currency', 'Payment status', 'Valid until', 'Payment method', 'Gateway', 'Transaction ID'], ',', '"', '');
            foreach ($query->lazy(200) as $payment) {
                $row = $history->format($payment);
                $values = array_map(fn ($key) => CustomerPaymentHistory::csvCell($row[$key]), [
                    'receipt_number', 'plan', 'device_type', 'payment_date', 'amount', 'currency', 'status', 'valid_until', 'payment_method', 'payment_gateway', 'transaction_id',
                ]);
                fputcsv($stream, $values, ',', '"', '');
            }
            fclose($stream);
        }, 'zo-stream-payments-'.$from->format('Y-m-d').'-'.$to->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function receipt(Request $request, string $payment, CustomerPaymentHistory $history)
    {
        $uid = $this->identity($request);
        $record = $history->query($uid)->whereKey($payment)->firstOrFail();
        $row = $history->format($record);
        abort_unless($row['receipt_available'], 409, 'Receipts are available for paid payments only.');
        $user = UserModel::where('uid', $uid)->first();
        $logo = public_path('images/zostream-invoice-logo.png');
        $pdf = Pdf::loadView('pdf.customer-receipt', [
            'payment' => $row, 'customer' => $user?->name ?: 'Zo Stream customer', 'username' => $user?->username,
            'logo' => is_file($logo) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logo)) : null,
        ])->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);

        return $pdf->download($row['receipt_number'].'.pdf')->header('Cache-Control', 'private, no-store');
    }

    private function identity(Request $request): string
    {
        // API identity is injected by auth.token. Browser identity comes only from the app bridge session.
        $uid = $request->is('api/v4/*') ? $request->input('auth_user_id') : $request->session()->get('customer_web_user_id');
        abort_unless(is_string($uid) && $uid !== '', 401, 'Please sign in to view your account.');

        return $uid;
    }

    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
