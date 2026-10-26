# Deploy mobile chat

Deploy backend **before** releasing the updated apps. `CHAT_ENABLED` defaults to
false so incomplete infrastructure cannot accidentally accept chat writes.
No production migration or deployment is performed by adding this code.

1. Install the committed Composer lockfile (`composer install --no-dev
   --optimize-autoloader`). The existing dependency set requires PHP 8.4; the
   Composer platform pin keeps local PHP 8.5 from upgrading beyond that target.
   Enable the Redis PHP extension and use the existing Redis authentication,
   private networking and memory monitoring. Reverb is included in this lockfile.
2. Run `php artisan migrate --force
   --path=database/migrations/2026_10_24_000001_create_chat_tables.php`.
   Also run these chat migrations in order with `php artisan migrate --force --path=<path>`:
   `database/migrations/2026_10_25_000001_create_invite_chat_rooms.php`,
   `database/migrations/2026_10_26_000001_add_room_name_to_chat_invite_rooms.php`,
   `database/migrations/2026_10_26_000002_add_idle_expiry_to_private_chat_rooms.php`.
   Existing private rooms receive a one-hour deadline from their latest message
   or creation time. Rooms already idle for an hour become unavailable. Deploy
   this backend and apply the migrations before releasing apps with End room.
3. Generate private Reverb credentials and configure the `.env.example` chat
   entries. `REVERB_APP_KEY` is public; `REVERB_APP_SECRET` is private. The public
   URL must include that public key:
   `wss://chat.zostream.in/app/PUBLIC_KEY?protocol=7&client=zostream-mobile&version=1.0&flash=false`.
   This hostname is a deployment example and must have DNS/TLS configured.
4. Run `php artisan config:cache`; supervise the two processes below. Keep
   Reverb's HTTP port private. `REVERB_HOST/PORT/SCHEME` identify the **internal**
   publish endpoint (normally `127.0.0.1:8080`, HTTP), while
   `CHAT_WEBSOCKET_URL` identifies the **public** TLS endpoint.
5. Install a TLS proxy using the location example below. Set
   `REVERB_ALLOWED_ORIGINS` to the comma-separated API root hostnames used by
   official apps. The native clients explicitly send that Origin. Browser
   origins alone do not grant private-channel access.
6. Verify login, global chat on two devices, online movie/episode chat, history
   scroll, reconnect catch-up, send retry, report/block/unblock and moderation.
   Check that stopped/expired playback loses content-room access within five
   seconds. Verify the analytics kill switch and backend-generated event counts.
7. Set `CHAT_ENABLED=true`, rebuild config cache and restart the supervised chat
   processes. Keep the existing Laravel scheduler running for `chat:prune`.
   When testing before enablement, enable only in the staging environment.

Example Supervisor entries (replace paths/user to match the server):

```ini
[program:zostream-reverb]
command=php /var/www/zostream/current/artisan reverb:start --host=127.0.0.1 --port=8080
directory=/var/www/zostream/current
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/zostream-reverb.log
stopwaitsecs=20

[program:zostream-chat-delivery]
command=php /var/www/zostream/current/artisan chat:deliver
directory=/var/www/zostream/current
user=www-data
numprocs=1
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/zostream-chat-delivery.log
stopwaitsecs=20
```

Nginx fragment inside the TLS virtual host for the public chat hostname:

```nginx
location /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 120s;
    proxy_send_timeout 120s;
}
location / { return 404; }
```

The `/apps/...` publish API stays on the private internal endpoint. Native
clients never need the secret, Redis address or database credentials.

For multiple Reverb nodes, enable `REVERB_SCALING_ENABLED=true` with a private
shared Redis connection and use the same app credentials on each node. Run one
outbox worker across the deployment. The committed configuration disables client
broadcast events, limits frame size and enables connection rate limiting.

To disable chat, set `CHAT_ENABLED=false` and rebuild config cache. APIs then
refuse joins, reads and sends; change notifications contain no chat text. Keep
stored history for recovery. A code rollout should restart Reverb and the outbox
worker so they load the updated config and code.
