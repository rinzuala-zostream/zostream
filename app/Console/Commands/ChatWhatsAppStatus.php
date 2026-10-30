<?php

namespace App\Console\Commands;

use App\Support\Chat\ChatDatabase;
use App\Support\Chat\InvitationPhone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ChatWhatsAppStatus extends Command
{
    protected $signature = 'chat:whatsapp-status {--invite= : Inspect a specific invitation ID}';

    protected $description = 'Read-only checks for room WhatsApp delivery; never sends messages or prints credentials';

    public function handle(): int
    {
        $connection = config('queue.default');
        $driver = config("queue.connections.$connection.driver");
        $checks = [
            'CHAT_WHATSAPP_ENABLED' => (bool) config('chat.whatsapp_enabled'),
            'WHATSAPP_PHONE_ID configured' => filled(config('app.whatsapp_phone_id')),
            'WHATSAPP_TOKEN configured' => filled(config('app.whatsapp_token')),
        ];
        $ready = ! in_array(false, $checks, true);
        $this->table(['Setting', 'Value'], array_merge(
            array_map(fn ($key, $value) => [$key, $value ? 'yes' : 'NO'], array_keys($checks), $checks),
            [['Queue connection', $connection], ['Queue driver', $driver ?? 'unknown'],
                ['Template', config('chat.whatsapp_template')], ['Language', config('chat.whatsapp_language')]],
        ));
        if (in_array($driver, ['sync', 'null'], true)) {
            $this->warn('Configure a persistent queue and a queue:work process for reliable invitation delivery.');
            $ready = false;
        }
        foreach (['chat_room_invites', 'chat_invite_rooms', 'whatsapp_messages'] as $table) {
            $present = str_starts_with($table, 'chat_')
                ? ChatDatabase::schema()->hasTable($table) : Schema::hasTable($table);
            $this->line($table.': '.($present ? 'present' : 'MISSING - run migrations'));
            $ready = $ready && $present;
        }
        if (ChatDatabase::schema()->hasTable('chat_room_invites') && (! ChatDatabase::schema()->hasColumn('chat_room_invites', 'link_token_hash')
            || ! ChatDatabase::schema()->hasColumn('chat_room_invites', 'whatsapp_sent_at'))) {
            $this->error('Invitation link columns missing: run the chat invitation links migration.');
            $ready = false;
        }
        if ($driver === 'database') {
            $queueDb = config("queue.connections.$connection.connection") ?: config('database.default');
            $table = config("queue.connections.$connection.table", 'jobs');
            if (Schema::connection($queueDb)->hasTable($table)) {
                $count = DB::connection($queueDb)->table($table)
                    ->where('queue', config("queue.connections.$connection.queue", 'default'))
                    ->where('payload', 'like', '%SendChatInvitationWhatsApp%')->count();
                $this->line('Queued room invitation jobs: '.$count);
            } else {
                $this->error('Queue table missing: run the chat delivery queue migration.');
                $ready = false;
            }
        }
        if (ChatDatabase::schema()->hasTable('chat_room_invites') && ChatDatabase::schema()->hasColumn('chat_room_invites', 'whatsapp_sent_at')) {
            $id = $this->option('invite');
            if ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1)) {
                $this->error('--invite must be a positive invitation ID.');
                return self::FAILURE;
            }
            $invite = ChatDatabase::connection()->table('chat_room_invites')->when($id, fn ($q) => $q->where('id', $id))->latest('id')->first();
            if ($invite) {
                $user = DB::table('user')->where('uid', $invite->invitee_user_id)->first();
                $phone = $user ? InvitationPhone::international($user->auth_phone, $user->country_code ?? null) : null;
                $this->table(['Invitation', 'State', 'WhatsApp accepted at', 'Expired', 'Recipient phone usable'], [[
                    $invite->id, $invite->status, $invite->whatsapp_sent_at ?? 'not sent',
                    $invite->expires_at <= now() ? 'yes' : 'no', $phone ? 'yes' : 'NO',
                ]]);
            } else { $this->line('No matching room invitation.'); }
        }
        $this->line('chat:deliver handles WebSocket events; WhatsApp jobs require a separate queue:work process.');
        $this->line('Check queue:failed and the chat WhatsApp skip/provider-code logs. Provider acceptance is not proof of delivery.');
        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
