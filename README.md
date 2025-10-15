# BALP v2 — Drop‑in balíček (aktuální)
- `index.php` — přesměruje na instalátor nebo UI
- `installer.php` — instalátor (DB + volitelné přihlášení)
- `health.php` — diagnostika
- `db_probe.php` — sonda algoritmu hesel
- `api.php` — REST‑like API (bez mod_rewrite) s volitelným JWT
- `public/app.html` — CRUD UI (Bootstrap + DataTables)
- `admin_users.php` — správa uživatelů (VARBINARY(16) login/heslo)
- `helpers.php`, `config/config.sample.php`
