# Zo Stream Admin (Vue)

The production admin workspace is part of the Laravel application and is served at `/admin`.
The imported `admin/` directory is retained as the original Next.js reference; Laravel does not execute it.

## Architecture

- `resources/js/admin/router.js` owns admin client routes with the `/admin` history base.
- `resources/js/admin/lib/api.js` provides the API v4 adapter, auth headers, token refresh, and envelope normalization.
- `resources/js/admin/lib/resources.js` defines reusable CRUD schemas.
- `resources/js/admin/pages` contains dashboard, authentication, resource, analytics, and operations screens.
- `app/Http/Controllers/Api/V4/AdminRealtimeConfigController.php` keeps Firebase Admin credentials on the server for warning, ticker, and official-client configuration.
- `routes/web.php` sends all `/admin/*` browser routes to the Vue entry point.

## Local development

Run Laravel and Vite as usual:

```bash
php artisan serve
npm run dev
```

Then open `http://127.0.0.1:8000/admin`.

Admin login depends on the existing `ADMIN_WHATSAPP_ALLOWED_NUMBERS`, `ADMIN_QR_ALLOWED_UIDS`, Firebase, and API v4 configuration.
