# BALP v2 — Drop‑in balíček (aktuální)
- `index.php` — přesměruje na instalátor nebo UI
- `installer.php` — instalátor (DB + volitelné přihlášení)
- `health.php` — diagnostika
- `db_probe.php` — sonda algoritmu hesel
- `api.php` — REST‑like API (bez mod_rewrite) s volitelným JWT
- `public/app.html` — CRUD UI (Bootstrap + DataTables)
- `admin_users.php` — správa uživatelů (VARBINARY(16) login/heslo)
- `helpers.php`, `config/config.sample.php`

## Modulární architektura

BALP v2 je nyní rozdělený do samostatných modulů v adresáři `modules/`. Každý modul
popisuje své API endpointy, veřejná aktiva a pomocné skripty v souboru `module.php`.
K dispozici jsou předpřipravené moduly:

- `modules/polotovary` — polotovary a výrobní příkazy,
- `modules/suroviny` — správa surovin,
- `modules/naterove_hmoty` — evidence nátěrových hmot.
- `modules/nh_vyroba` — výrobní příkazy pro nátěrové hmoty.

Moduly se registrují automaticky pomocí `modules/bootstrap.php`. Jakýkoli skript v
`api/` je pouze tenký vstupní bod, který zavolá funkci
`balp_include_module_api(<slug>, <endpoint>)` a deleguje zpracování na odpovídající
modul. Přidání nového modulu tak spočívá v založení nové složky v `modules/` a v
definici mapy endpointů v `module.php`. Přehled registrovaných modulů vrací API
volání `api.php?action=_modules`.
