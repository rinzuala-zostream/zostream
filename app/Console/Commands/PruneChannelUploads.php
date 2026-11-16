<?php

namespace App\Console\Commands;

use App\Models\Channel\ChannelUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PruneChannelUploads extends Command
{
    protected $signature = 'channel-uploads:prune';

    protected $description = 'Remove expired, unfinished channel uploads (completed sources are retained)';

    public function handle(): int
    {
        if (! Schema::hasTable('channel_uploads')) {
            return self::SUCCESS;
        }
        $ids = ChannelUpload::where('status', '!=', 'ready')->where('expires_at', '<', now())->limit(200)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $upload = ChannelUpload::whereKey($id)->lockForUpdate()->first();
                if ($upload && $upload->status !== 'ready' && $upload->expires_at->isPast()) {
                    Storage::disk('channel_uploads')->deleteDirectory($upload->directory());
                    $upload->delete();
                }
            });
        }

        return self::SUCCESS;
    }
}
