# Deploying to Hostinger (Single plan)

Single has no SSH, no subdomains and no Node.js apps, so everything runs on one domain:

```
/home/<user>/brewspot-api/                      Laravel app (private)
/home/<user>/domains/<domain>/public_html/      Nuxt SPA (static)
    backend/index.php                           Laravel entry point
    .htaccess                                   /api, /sanctum, /up, /storage -> Laravel; rest -> SPA
```

## 1. Build (on your PC)

```powershell
powershell -ExecutionPolicy Bypass -File deploy\hostinger\build.ps1
```

Produces `deploy\hostinger\dist\brewspot-api.zip` and `public_html.zip`.

## 2. Upload (hPanel > File Manager)

1. In the home folder (`/home/<user>`), create `brewspot-api`, upload `brewspot-api.zip` into it, right-click > Extract, then delete the zip.
2. Open `domains/<domain>/public_html`, delete the default `default.php`/`index.php` if present, upload `public_html.zip`, Extract, delete the zip.
3. First deploy only: create `brewspot-api/.env` from `env.example` and fill in every value.
   Get `APP_KEY` locally with `php artisan key:generate --show`.

## 3. Set PHP to 8.4 (hPanel > Advanced > PHP Configuration)

`composer.lock` is resolved on PHP 8.4; on 8.3 every request fails Composer's platform check.

## 4. Migrate and seed

There is no SSH and web PHP has `exec()` disabled, so these run through a one-time installer:
copy `install.php.example` to `install-<random hex>.php` (`openssl rand -hex 16`), upload it to
`public_html`, then open:

- `https://<domain>/install-<hex>.php?log=1` shows the tail of `laravel.log` and `schedule.log`
- `https://<domain>/install-<hex>.php?run=1` runs `migrate` and the Role/Admin/Feature/SubscriptionPlan seeders, then deletes itself

`storage:link` is not needed (and fails: `symlink()`/`exec()` are disabled). Uploaded files are
served by Laravel through the public disk's `'serve' => true`.

Check the file is gone afterwards. Later deploys with new migrations: re-upload it and use `?run=1`
(re-running `AdminSeeder` resets the admin's UUID and password).

Do **not** run `config:cache`: `PayPalSubscriptionController` reads `env()` directly, which returns null once config is cached.

The admin seeded by `AdminSeeder` uses the password from the seeder file. Log in and change it immediately.

## 5. Permanent cron jobs (Custom, every minute)

Two jobs, one plain command each with absolute paths:

```
/usr/bin/php /home/<user>/brewspot-api/artisan schedule:run
```

```
/usr/bin/php /home/<user>/brewspot-api/artisan queue:work --stop-when-empty --max-time=45
```

The queue sends mail that is queued (login 2FA codes, subscription notices); without the second
job those emails sit in the `jobs` table forever.

hPanel quirks found on the Single plan:
- At most 2 cron jobs; a third is silently not saved.
- Commands over ~255 characters, or containing `[ ]` / `{ }`, are silently not saved.
- `cd ... &&`, `;` chains and `>` redirection don't behave as in a shell: only use single commands
  with absolute paths. Output shows under "View Output".
- No "every minute" preset: set each of Minute/Hour/Day/Month/Weekday to its `(*)` option.

## 6. Check

- `https://<domain>/up` returns Laravel's health page.
- `https://<domain>/` loads the app; log in and watch the browser console.
- Errors: `brewspot-api/storage/logs/laravel.log` in File Manager, or the installer's `?log=1`.

## Redeploying

Run `build.ps1` again and re-extract both zips over the existing folders (choose overwrite).
Extraction never touches `.env` or uploaded files in `storage/`. Run the installer with `?run=1` if there are new migrations.

## Changing the domain

Update `APP_URL`, `FRONTEND_URL` and the PayMongo URLs in `.env`, and the `public_html` path in the
scheduler cron if it contains the domain. The Nuxt build uses `/api`, so it needs no rebuild.

## Auto-deploy with GitHub Actions

Both repos have `.github/workflows/deploy-hostinger.yml`, which runs on every push to `main`
(or manually from the Actions tab). GitHub builds the app (Composer / `nuxt generate`), uploads only
changed files over FTP, and for the server calls `POST /api/deploy/migrate`.

Each workflow first lists its FTP target folder and stops if it doesn't look right
(`artisan` for the API, `backend/` for public_html), so a wrong path never uploads anything.

**Repo settings > Secrets and variables > Actions**, in both repos:

| Name | Kind | Value |
|---|---|---|
| `FTP_SERVER` | secret | FTP hostname/IP from hPanel > Files > FTP Accounts |
| `FTP_USERNAME` | secret | FTP username |
| `FTP_PASSWORD` | secret | FTP password |
| `FTP_PROTOCOL` | variable (optional) | `ftps` by default; set `ftp` only if FTPS fails |

Server repo only:

| Name | Kind | Value |
|---|---|---|
| `FTP_API_DIR` | variable | `brewspot-api/` as seen from the FTP root |
| `SITE_URL` | variable | `https://<domain>` |
| `DEPLOY_TOKEN` | secret | same value as `DEPLOY_TOKEN` in the server `.env` |

Client repo only:

| Name | Kind | Value |
|---|---|---|
| `FTP_PUBLIC_DIR` | variable | the site's `public_html/` as seen from the FTP root, e.g. `domains/<domain>/public_html/` |

The paths depend on where the FTP account starts. If its root is the home folder, use
`brewspot-api/` and `domains/<domain>/public_html/`. If it starts inside `public_html`, the API
folder is unreachable: create an FTP account whose directory is the home folder instead.

The first run uploads all app files (a few hundred); later runs only upload changes.
`backend/index.php` and `.env` are never touched; re-upload `backend/index.php` by hand if it ever
changes. Seeders are not run automatically.

**`vendor/` is not auto-deployed.** It is ~7,000 files, and Hostinger closes FTP sessions after about
an hour, before a full upload finishes. When `composer.lock` changes (the server workflow shows a
warning), run `build.ps1` and extract `brewspot-api.zip` over `brewspot-api/` with "Overwrite" checked.
