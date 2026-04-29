# CloudPanel Env Variables Addon

Adds a **Variables** tab to every site in CloudPanel for managing environment variables. On save, the addon:

- writes `env[KEY] = "value"` into the site's PHP-FPM pool config and reloads PHP-FPM (vars are then available in PHP via `$_SERVER['KEY']` / `getenv('KEY')`)
- auto-detects PM2 apps belonging to the site (matched by working directory under the site root) and restarts them with `pm2 restart <app> --update-env` so `process.env` picks up the new values

**No `.env`, no `ecosystem.config.js`, no other on-disk file inside the site dir.** Variables are stored only in `/opt/clp-env-addon/data/<domain>.json` (owned `clp:clp`, dir mode 750, outside any web root) and pushed into PM2 by exporting them in the restart subshell.

The source-of-truth for variables lives outside CloudPanel in `/opt/clp-env-addon/data/<domain>.json`, so CloudPanel updates can never lose your data. An APT post-invoke hook re-applies the controller, template, route, and tab patches automatically after every `apt`/`clp-update` operation.

## Install

```bash
sudo mkdir -p /opt/clp-env-addon
sudo cp -r ./* /opt/clp-env-addon/
sudo bash /opt/clp-env-addon/scripts/clp-env-addon install
```

Then open CloudPanel → any site → you should see a new **Variables** tab.

## Commands

```bash
clp-env-addon install     # initial install
clp-env-addon repair      # re-apply patches (runs automatically after apt)
clp-env-addon check       # verify everything is in place
clp-env-addon uninstall   # remove the addon (keeps your stored variables)
```

## How it survives CloudPanel updates

CloudPanel ships as a Debian package; updates replace files under `/home/clp/htdocs/app/`. The installer registers an APT hook at `/etc/apt/apt.conf.d/99-clp-env-addon` that calls `clp-env-addon repair --quiet` after every dpkg operation. `repair` is idempotent and exits in <1ms when patches are intact.

## What gets patched

- `src/Controller/Frontend/EnvController.php` — added
- `templates/Frontend/Site/env.html.twig` — added
- `public/assets/css/frontend/env.css` — added
- `config/routes.yaml` — three routes appended in a marker block
- `templates/Frontend/Site/Partial/tab-container.html.twig` — one `<li>` injected before the closing `</ul>`

## Files written per site

- `/etc/php/<version>/fpm/pool.d/<siteUser>.conf` — managed block delimited by `; BEGIN clp-env-addon` / `; END clp-env-addon`

Nothing is written inside the site's home or web root.

## PM2 detection

PM2 apps are matched by `pm_cwd` from `pm2 jlist` against the site's directory (`/home/<siteUser>/htdocs/<domain>`). If your Node app is started from a path inside that tree, it'll be picked up automatically. Apps started from outside the site root won't be matched — start them from inside it (e.g. `cd /home/foo/htdocs/example.com && pm2 start app.js --name myapp`).

## Uninstall

```bash
sudo clp-env-addon uninstall
# To purge stored data and source too:
sudo rm -rf /opt/clp-env-addon /usr/local/bin/clp-env-addon /opt/clp-env-addon/data
```

Per-site PHP-FPM pool blocks are intentionally left in place on uninstall.
