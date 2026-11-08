<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class MigrateChat extends Command
{
    protected $signature = 'chat:migrate {--force : Run in production} {--pretend : Show SQL without executing it}';

    protected $description = 'Migrate only chat storage on the configured chat database';

    public function handle(): int
    {
        $connection = config('chat.connection');
        if (! is_string($connection) || $connection === '' || ! config("database.connections.$connection")) {
            $this->error('Configure CHAT_DB_CONNECTION and its database connection first.');

            return self::FAILURE;
        }

        $this->line('Chat database connection: '.$connection);

        return $this->call('migrate', [
            '--database' => $connection,
            '--path' => 'database/migrations/chat',
            '--force' => $this->option('force'),
            '--pretend' => $this->option('pretend'),
        ]);
    }
}
