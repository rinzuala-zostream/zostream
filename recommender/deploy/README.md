# Persistent recommendation service

This removes gzip/JSON model loading from ordinary cache misses. One Python
process holds one model; every request still supplies the current user's watch
history and wishlist. Laravel's per-user Redis caches and live visibility filters
remain in use. This does not establish a capacity guarantee for 5,000 concurrent
requests.

## Deploy on the VPS

Upload the changed application files plus `recommender/server.py` and this deploy
directory through your normal deployment process first. No new Python packages
are needed. The unit assumes `/var/www/zostream.in`, `/usr/bin/python3`, user/group
`www-data`, and the default artifact path. If `RECOMMENDER_MODEL` differs, change
the unit's `--model` path to match. Python 3.10+ is required by `hybrid.py`.

```bash
cd /var/www/zostream.in
/usr/bin/python3 --version
sudo -u www-data test -r recommender/artifacts/hybrid_model.json.gz
sudo -u www-data test -r recommender/server.py
sudo install -m 644 recommender/deploy/zostream-recommender.service /etc/systemd/system/zostream-recommender.service
sudo systemctl daemon-reload
sudo systemctl enable --now zostream-recommender
sudo systemctl status zostream-recommender --no-pager
curl --fail --max-time 10 http://127.0.0.1:8765/health
```

Wait for `{"ready": true}` before enabling Laravel. If the readability checks or
startup fail, fix the service user/group/path permissions first. The existing
training command's model group should match the unit group; keep the model private.
Do not expose port 8765 through nginx or open it publicly. It binds loopback only.

Add/update these entries in the production `.env` (do not duplicate existing keys):

```dotenv
RECOMMENDER_SERVICE_URL=http://127.0.0.1:8765
RECOMMENDER_SERVICE_TIMEOUT_SECONDS=5
```

Then rebuild Laravel configuration using the same deployment user as usual:

```bash
php artisan config:cache
```

Long-running Laravel workers, if used, need their normal deployment reload too.
There is no need to flush Redis or other application caches.

## Check warm model performance without the Redis response cache

Run after the health check succeeds. This calls the service directly, so each
iteration performs inference with an empty history without reloading the model.
It does not measure Laravel/DB/network time or populated user histories.

```bash
for attempt in 1 2 3; do
  curl --fail --silent --show-error --max-time 10 \
    -o /dev/null -w 'HTTP %{http_code}; total %{time_total}s\n' \
    -H 'Content-Type: application/json' \
    -d '{"user_id":"performance-probe","limit":11,"mode":"adult","include_age_restricted":false,"signals":{"watch_position":[],"wishlist":[]}}' \
    http://127.0.0.1:8765/homepage
done
sudo systemctl show zostream-recommender -p MemoryCurrent -p MemoryPeak -p NRestarts
```

Next measure authenticated homepage cache misses with representative histories
and controlled concurrency. Monitor API latency, CPU, service memory and fallback
frequency before increasing traffic or worker count. One sequential worker bounds
memory but requests can queue; the five-second Laravel timeout bounds waiting.

## Reload and failure behavior

Training already atomically replaces the artifact. The service checks its inode,
mtime and size before inference and reloads when changed. The first inference
after replacement pays the load cost; old and new models can overlap in memory.
The unit caps memory at 2 GiB and automatically restarts after failure. Confirm
production peak memory, especially after model growth. Restart the unit whenever
Python code is deployed. Health becomes available after initial model loading.

If a replacement model is corrupt/unreadable, inference returns 503 until repaired;
the old object remains in memory but is not served as the new model. Laravel serves
live home sections on service failure or timeout. It does not spawn expensive CLI
processes during an outage. Such fallback responses can remain in the existing
homepage cache until refresh (currently 30/300 seconds).

Watch service failures with:

```bash
sudo journalctl -u zostream-recommender -n 50 --no-pager
```

To roll back, set `RECOMMENDER_SERVICE_URL=` and run `php artisan config:cache`.
Laravel then uses the original Python CLI path. Only after that, stop the unit if
needed. Rollback restores the model-loading delay on AI cache misses.
