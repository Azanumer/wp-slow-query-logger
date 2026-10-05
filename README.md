# WP Slow Query Logger

A tiny WordPress plugin that finds your slowest pages. It logs every visitor page load slower than your threshold (default 2 seconds) — with load time, SQL query count, SQL time, and peak memory — and lists the worst offenders under **Tools → Slow Requests**.

No JavaScript, no external services, no settings page to babysit. Activate it, wait for traffic, then fix whatever is at the top of the list.

## Files

| File | What it does |
|---|---|
| `wp-slow-query-logger.php` | The whole plugin: activation table, `shutdown` logger, admin viewer |

## Install

1. Upload the plugin folder to `wp-content/plugins/` (or drop `wp-slow-query-logger.php` in `mu-plugins/`).
2. Activate it in **Plugins**.
3. Open **Tools → Slow Requests** after some traffic.

## Customising the threshold

Default: requests slower than **2000 ms** are logged. Lower it while tuning:

```php
// Log anything slower than 1 second:
add_filter( 'wsql_threshold_ms', function () { return 1000; } );
```

Add that to your theme's `functions.php` or a snippets plugin.

## What gets skipped

Admin pages, AJAX, CRON, REST requests, and logged-in administrators are never measured — the log reflects real visitor traffic only.

## Notes

- Per-query SQL timing needs `define( 'SAVEQUERIES', true );` in `wp-config.php`; without it, only the query count is recorded (fine for production — SAVEQUERIES adds memory overhead).
- The log table (`{prefix}wsql_slow_requests`) is dropped on uninstall, so stale data never lingers.
- One INSERT per slow request is cheap; the fast 99% of your traffic writes nothing.

Requires WordPress 5.8+ and PHP 7.4+. MIT licensed.
