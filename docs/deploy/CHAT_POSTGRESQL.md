# Chat PostgreSQL deployment

Chat uses the dedicated `chat` PostgreSQL connection. Keep the existing `DB_*`
settings pointing at MySQL: users, subscriptions, content, device sessions and
playback stay there. Redis and Reverb continue to handle chat realtime delivery.
There is no history import or dual write. Existing MySQL chat tables, if present,
are left untouched and are no longer used.

## VPS setup

Install PostgreSQL and the PostgreSQL PHP extension matching both CLI PHP and
PHP-FPM (for PHP 8.4 on Debian/Ubuntu, `postgresql` and `php8.4-pgsql`). Restart
PHP-FPM after installing the extension. Check `php --ri pdo_pgsql`.

Create a dedicated role/database using `sudo -u postgres psql`:

```sql
CREATE ROLE zostream_chat LOGIN;
\password zostream_chat
CREATE DATABASE zostream_chat OWNER zostream_chat;
\q
```

Use the password prompt to set a new password. Keep PostgreSQL on the VPS's local
interface for this same-server deployment; use `127.0.0.1` in the app configuration.

Add these to the application's `.env`, preserving all existing `DB_*` entries:

```dotenv
CHAT_ENABLED=false
CHAT_DB_CONNECTION=chat
CHAT_DB_HOST=127.0.0.1
CHAT_DB_PORT=5432
CHAT_DB_DATABASE=zostream_chat
CHAT_DB_USERNAME=zostream_chat
CHAT_DB_PASSWORD="your-new-password"
CHAT_DB_SSLMODE=prefer
```

If `CHAT_DB_URL` was previously set, remove it or update it to this database;
the URL overrides the individual host/database credentials.

From the deployed backend directory:

```sh
php artisan config:clear
php artisan chat:migrate --force
php artisan migrate:status --database=chat --path=database/migrations/chat
php artisan config:cache
```

`chat:migrate` runs all chat migrations and records their history in
PostgreSQL. Ordinary `php artisan migrate` no longer discovers chat migrations;
they live in `database/migrations/chat`. Do not run `migrate:fresh` on the main
database. This setup does not require altering main MySQL tables or copying data.

Set `CHAT_ENABLED=true`, run `php artisan config:cache` again, reload PHP-FPM
using the VPS's existing service command, and restart the existing Supervisor
processes for Reverb, `chat:deliver` (all configured lanes), and chat invitation
queue workers. Long-running processes must reload the new connection settings.
Use the actual process names from [CHAT.md](CHAT.md) / the VPS configuration.

## Live verification

1. Confirm normal login, home/content requests and playback start/stop still work.
2. Run `php artisan chat:realtime-status --probe`; inspect worker logs if events
   remain pending or the WebSocket handshake fails.
3. With two accounts, test global messages, history, unread/read state,
   block/report/moderation and reconnect catch-up.
4. Test private invitations and watch together; stopping the host must retire
   the room/session. Confirm notifications and playback access checks still work.
5. In PostgreSQL, verify rows appear in `chat_rooms`, `chat_messages` and
   `chat_outbox`. Confirm no new chat rows appear in MySQL.

The same VPS still shares CPU, RAM and disk. PostgreSQL isolates chat tables and
locks; it does not isolate server capacity. Chat entitlement/playback checks still
read MySQL, and watch heartbeats update the existing playback records. Host-stop
cleanup still runs synchronously when chat is enabled; invitation FCM sends also
remain synchronous. This database split does not remove those latency sources.
Account deletion cleans chat on PostgreSQL and account data on MySQL using
separate transactions; there is no distributed transaction across the databases.

To disable chat, set `CHAT_ENABLED=false`, rebuild config cache and restart chat
processes. Disabled playback cleanup skips chat storage. This does not switch
chat data back to MySQL.

## Local checks

```sh
php vendor/bin/phpunit tests/Feature/ChatApiTest.php tests/Feature/ChatDatabaseMigrationTest.php tests/Feature/ChatRealtimeStatusTest.php tests/Feature/ChatQueueMigrationTest.php
```

The chat API suite normally uses two independent in-memory SQLite databases, so
cross-database joins or accidental writes to the main database fail. To exercise
PostgreSQL too, create a **disposable** database whose name ends in `_test`, then:

```sh
CHAT_TEST_PG_DATABASE=zostream_chat_test \
CHAT_TEST_PG_HOST=127.0.0.1 \
CHAT_TEST_PG_PORT=5432 \
CHAT_TEST_PG_USERNAME=your_test_user \
CHAT_TEST_PG_PASSWORD=your_test_password \
php vendor/bin/phpunit tests/Feature/ChatApiTest.php
```

This opt-in test run resets all tables in that test database before each test.
The main database in this suite remains an isolated SQLite fixture.
