<?php

namespace App\Http\Controllers;

use App\Models\MovieModel;
use App\Models\New\Episode;
use App\Models\New\PaymentHistory;
use App\Models\New\Season;
use App\Models\PPVPaymentModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PublicPpvController extends Controller
{
    public function show(Request $request, string $type, string $id)
    {
        $request->validate(['period' => 'sometimes|in:all,today,month']);
        $model = match ($type) {
            'movie', 'parent' => MovieModel::class,
            'season' => Season::class,
            'episode' => Episode::class,
        };
        // Prefer the stable ID, then support the numeric IDs used by admin links.
        $item = $model::where('id', $id)->first();
        if (! $item && ctype_digit($id)) {
            $item = $model::where('num', $id)->first();
        }
        abort_unless($item, 404);

        $children = collect();
        if ($type === 'parent') {
            foreach ($item->seasons as $season) {
                if ($season->isPayPerView) {
                    $children->push($this->presentation($season, 'season'));
                }
                foreach ($season->episodes->where('isPayPerView', true) as $episode) {
                    $children->push($this->presentation($episode, 'episode'));
                }
            }
            abort_if($children->isEmpty(), 404);
        } else {
            abort_unless($item->isPayPerView, 404);
        }

        $ids = $type === 'parent' ? [] : $this->paymentIds($item, $type);
        if ($type === 'parent') {
            foreach ($item->seasons as $season) {
                if ($season->isPayPerView) {
                    $ids = [...$ids, ...$this->paymentIds($season, 'season')];
                }
                foreach ($season->episodes->where('isPayPerView', true) as $episode) {
                    $ids = [...$ids, ...$this->paymentIds($episode, 'episode')];
                }
            }
            if ($item->isPayPerView) {
                $ids = [...$ids, ...$this->paymentIds($item, 'movie')];
            }
        }

        $period = $request->query('period', 'all');
        $timezone = 'Asia/Kolkata';
        $updatedAt = now($timezone);
        $start = match ($period) {
            'today' => $updatedAt->copy()->startOfDay(),
            'month' => $updatedAt->copy()->startOfMonth(),
            default => null,
        };
        $summary = ['purchases' => 0, 'refunds' => 0, 'cancelled' => 0, 'currencies' => []];
        foreach ([
            [PaymentHistory::query()->where('app_payment_type', 'ppv'), 'n_payment_histories', 'status', 'amount', 'payment_date'],
            [PPVPaymentModel::query(), 'ppv_payment', 'payment_status', 'amount_paid', 'purchase_date'],
        ] as [$query, $table, $status, $amount, $date]) {
            $query->whereIn('movie_id', array_unique(array_filter($ids)));
            if ($start) {
                $query->whereRaw("COALESCE($date, created_at) >= ?", [$start->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')])
                    ->whereRaw("COALESCE($date, created_at) <= ?", [$updatedAt->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')]);
            }
            $currency = Schema::hasColumn($table, 'currency') ? "UPPER(COALESCE(NULLIF(TRIM(currency), ''), 'INR'))" : "'INR'";
            $rows = $query->selectRaw("LOWER($status) as payment_status, $currency as currency_code, COUNT(*) as purchases, SUM($amount) as revenue")
                ->groupBy(DB::raw("LOWER($status)"), DB::raw($currency))->get();
            foreach ($rows as $row) {
                if (in_array($row->payment_status, ['success', 'captured', 'completed'], true)) {
                    $summary['purchases'] += (int) $row->purchases;
                    $summary['currencies'][$row->currency_code] = ($summary['currencies'][$row->currency_code] ?? 0) + (float) $row->revenue;
                } elseif (in_array($row->payment_status, ['refund', 'refunded'], true)) {
                    $summary['refunds'] += (int) $row->purchases;
                } elseif (in_array($row->payment_status, ['cancelled', 'canceled'], true)) {
                    $summary['cancelled'] += (int) $row->purchases;
                }
            }
        }

        // Pass an explicit public projection; never serialize content/payment models.
        return response()->view('ppv-report', [
            'content' => $this->presentation($item, $type),
            'children' => $children,
            'summary' => $summary,
            'period' => $period,
            'updatedAt' => $updatedAt,
        ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function paymentIds($item, string $type): array
    {
        $ids = [(string) $item->id];
        // Legacy movie payments used the numeric ID. Season/episode numbers
        // overlap movie numbers and must not be treated as payment identifiers.
        if ($type === 'movie') {
            $ids[] = (string) $item->num;
        } elseif ($type === 'episode' && $item->season) {
            $ids[] = $item->season->movie_id.'_'.$item->id;
        }

        return $ids;
    }

    private function presentation($item, string $type): array
    {
        $available = $item->status === 'Published' && (bool) ($item->isEnable ?? $item->is_active ?? true);

        return [
            'title' => $item->title ?: ucfirst($type),
            'type' => $type,
            'poster' => $item->poster ?: $item->thumbnail,
            'price' => $type === 'parent' ? null : (float) ($item->ppv_amount ?? $item->amount ?? 0),
            'status' => $available ? 'Available' : 'Unavailable',
            'url' => route('ppv.public', ['type' => $type, 'id' => $item->id ?: $item->num]),
        ];
    }
}
