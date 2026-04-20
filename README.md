# Lead Form Proxy (WordPress plugin)

Renders a fixed-field lead form via the `[lead_form]` shortcode, stores each submission locally in a custom database table, POSTs it as JSON to a configured HTTP endpoint, and retries transient failures via WP-Cron with exponential backoff. Designed to be paired with the [`lead-api`](https://github.com/BryanAdamson/lead-api) .NET service, but the outbound contract is plain JSON so any HTTP endpoint will do.

Key properties:

- **Offline-first.** Every submission is written to the local table *before* any network call, so form data is never lost if the remote API is down.
- **Idempotent.** Each row gets a client-generated `submissionUuid` that is included in the POST body and preserved across retries. The server can use this to dedupe; an HTTP `409 Conflict` is treated as “already persisted” (success), not an error.
- **Bounded retries.** A configurable max-attempts limit moves rows from `pending` → `failed` so the queue can’t grow forever.

## Outbound JSON contract

```json
{
  "submissionUuid": "client-generated-uuid-v4",
  "email":    "user@example.com",
  "fullName": "Jane Doe",
  "phone":    "+1 555-123-4567",
  "message":  "Please call me",
  "source":   "https://example.com/contact"
}
```

Status-code handling:

| Response   | Row status | Retries? |
|------------|------------|----------|
| `2xx`      | `sent`     | no |
| `409`      | `sent`     | no (treated as “already persisted”) |
| `4xx` (other) | `failed`   | no (payload is wrong — not a transient fault) |
| `5xx`, timeout, DNS error | `pending` | yes, up to **Max send attempts** |

---

## Run it via the bundled Docker stack (recommended for local dev)

The sibling `lead-api` repo ships a full end-to-end docker-compose stack (WordPress + MariaDB + WP-CLI + the .NET API + SQL Server) that bind-mounts this plugin directory straight into WordPress. It’s the fastest way to see the plugin end-to-end.

Assuming both repos are cloned next to each other (`~/Work/lead-api` and `~/Work/lead-form-proxy`):

```bash
cd ../lead-api

make e2e-up          # build & start everything
make e2e-bootstrap   # install WP, activate this plugin, set API URL to http://lead-api:8080/api/leads
```

Then open:

- Front end → `http://localhost:18080`
- WP admin → `http://localhost:18080/wp-admin` (user `admin`, password `admin`)
- API health → `http://localhost:15080/health`

### See the form on a page

```bash
docker compose -f docker-compose.e2e.yml exec -T wpcli \
  wp post create --post_type=page --post_title='Contact' \
                 --post_status=publish --post_content='[lead_form]'
```

Visit `http://localhost:18080/?page_id=<id>` (the create command prints the ID), fill in the form and submit. Then:

```bash
# WP side: the local row should be `sent`
docker compose -f docker-compose.e2e.yml exec -T wpcli \
  wp db query "SELECT id, status, attempt_count, remote_response_code, last_error \
               FROM wp_lead_form_submissions ORDER BY id DESC LIMIT 5"

# The client-generated submissionUuid lives inside payload_json:
docker compose -f docker-compose.e2e.yml exec -T wpcli \
  wp db query "SELECT id, JSON_EXTRACT(payload_json, '\$.submissionUuid') AS uuid \
               FROM wp_lead_form_submissions ORDER BY id DESC LIMIT 5"

# API side: the lead should be persisted
docker compose -f docker-compose.e2e.yml exec -T sqlserver \
  /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -P 'YourStrong!Passw0rd' -C \
  -d LeadDb -Q "SELECT Id, SubmissionUuid, Email FROM Leads"
```

### Simulate the API being down

```bash
cd ../lead-api
docker compose -f docker-compose.e2e.yml stop lead-api
# submit the form in the browser → row is `pending`

docker compose -f docker-compose.e2e.yml start lead-api
docker compose -f docker-compose.e2e.yml exec -T wpcli \
  wp eval 'Lead_Form_Proxy_Cron::run_retries();'
# row flips to `sent`
```

### Run the 5-scenario automated suite

From the `lead-api` directory:

```bash
make e2e-test
```

Scenarios: happy path, offline → recover, replay / 409 idempotency, validation 400 (no retry), and max-attempts exhaustion.

### Tear down

```bash
cd ../lead-api
make e2e-down   # removes volumes too; next `make e2e-up` starts fresh
```

---

## Install into an existing WordPress site

1. Copy the whole `lead-form-proxy` folder into `wp-content/plugins/` on your site.
2. Activate **Lead Form Proxy** in **Plugins**.
3. Open **Settings → Lead Form Proxy** and fill in:
   - **API endpoint URL** — e.g. `https://your-lead-api.example.com/api/leads`.
   - **Bearer token** — optional; sent as `Authorization: Bearer …`.
   - **Max send attempts** — after this many consecutive transient failures a row is marked `failed` (default 5).
   - **Cron batch size** — rows processed per cron tick (default 20).
   - **Retry schedule** — WP-Cron interval for the retry worker.
4. Drop the shortcode `[lead_form]` on any page.

### WP-Cron reliability in production

WP-Cron is driven by site traffic. Low-traffic sites should disable WP-Cron and invoke `wp-cron.php` from a real system cron for predictable retry latency:

```apacheconf
# wp-config.php
define('DISABLE_WP_CRON', true);
```

```cron
# /etc/cron.d/wp-cron
*/5 * * * * www-data curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null
```

### Uninstall data

By default, uninstalling the plugin **preserves** the submissions table and options (so you don’t lose queued leads by accident). To wipe everything on uninstall, add to `wp-config.php` *before* you delete the plugin:

```php
define('LEAD_FORM_PROXY_UNINSTALL_DELETE_DATA', true);
```

---

## Project layout

```
lead-form-proxy/
├── lead-form-proxy.php           plugin bootstrap + contract docs
├── uninstall.php                 optional data wipe (gated by constant)
├── includes/
│   ├── class-submission-service.php   core: build_payload, insert, process, retry
│   ├── class-api-client.php           wp_remote_post wrapper; flags 409 as duplicate
│   ├── class-cron.php                 retry worker invoked by WP-Cron
│   ├── class-admin.php                Settings page
│   └── class-shortcode.php            [lead_form] renderer + POST handler
└── tests/
    └── smoke.php                 plain-PHP test for build_payload() shape
```

## Developer checks

```bash
# PHP syntax lint every file
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l

# Plain-PHP smoke test (no WordPress required)
php tests/smoke.php
```
