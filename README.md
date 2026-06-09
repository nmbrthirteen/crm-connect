# CRM Connect

A whitelabeled WordPress plugin that captures website form submissions — every field, UTM and trackable — and reliably forwards them **server-side** to a CRM (Freshsales first) via a configurable per-form mapping. See [`DESIGN.md`](./DESIGN.md) for the full design rationale.

## Install (development)

1. Copy this folder into `wp-content/plugins/crm-connect`.
2. Optional but recommended: `composer dump-autoload -o` (a PSR-4 fallback autoloader is built in, so this is not required).
3. Activate **CRM Connect** in WP Admin → Plugins. Activation creates the queue table.
4. Go to the **CRM Connect** menu:
   - **Settings** — enter your Freshsales domain (`yourco.myfreshworks.com`) + API key, then **Test connection**. Set branding, alert email/Slack, retention, auto-pause.
   - **Mappings** — add a mapping: pick a form, add a CRM object (Contact/Deal), map fields, set the dedupe key and a catch-all field. **Save**.
   - **Submissions** — live log of every submission with status, full payload, CRM request/response, and retry.

Requires PHP 8.0+, Elementor Pro (for the Elementor form source).

## Architecture (two swappable seams)

```
Form submit ─▶ FormSource (Elementor) ─▶ CaptureService ─▶ QueueStore (DB, persist-first)
                                                                  │
                          Attribution cookie (first+last touch) ──┘
                                                                  ▼
                                  QueueWorker (retries, backoff, dead-letter, 429-aware, auto-pause)
                                                                  ▼
                                  CrmProvider (Freshsales) ◀─ FieldMapper (per-form profile)
```

- **CRM is pluggable** via the `crm_connect_provider` filter (`CrmProvider` interface). Freshsales is provider #1.
- **Form platform is pluggable** via the `crm_connect_form_sources` filter (`FormSource` interface). Elementor is source #1.
- The queue, mapping, capture, log, and alerting layers are agnostic to both.

### Key extension points (filters/actions)

| Hook | Purpose |
|---|---|
| `crm_connect_provider` | Return a `CrmProvider` to swap CRM |
| `crm_connect_form_sources` | Add/replace `FormSource` implementations |
| `crm_connect_trackables` | Add custom trackable source keys |
| `crm_connect_attribution_enabled` | Gate the attribution cookie behind a consent manager |
| `crm_connect_attribution_lifetime_days` | Cookie lifetime (default 90) |
| `crm_connect_dead_letter` / `crm_connect_worker_paused` | Hook delivery failures |

## Reliability properties

- **Persist-before-send**: the full payload is written to the DB inside the form hook, before any CRM call — a CRM outage can never lose a submission.
- **Retries** with exponential backoff → **dead-letter** after 6 attempts.
- **429-aware**: honors Freshsales' `Retry-After`; schema reads are cached (rate-limit budget is 1000/hr).
- **Crash recovery**: items stuck in `sending` for >5 min are re-claimed.
- **Auto-pause** after N consecutive failures + email/Slack alert; manual resume.
- **Retention purge** of old sent rows (configurable, default 180 days).

## License

GPL-2.0-or-later. See [`LICENSE`](./LICENSE).

## Known follow-ups (not yet built)

- **Reconciliation cron** against Elementor's `wp_e_submissions` tables (defense-in-depth backfill if the hook itself ever fatals). Deferred pending verification of Elementor's submission-table schema.
- **Deal orchestration**: a Deal needs `name`, `amount`, `sales_account_id`. The lookup-or-create-Sales-Account-by-company step is not yet wired — currently Deal fields are mapped directly like any object.
- **`create_field` form id**: Freshsales' custom-field endpoint path (`settings/<entity>/forms/<form_id>/fields`) uses a placeholder form id `0`; verify against a live account.
- Choice-mapping UI for CRM dropdown fields (value → choice) is stubbed; mapping currently passes raw values (unmatched values fall to the catch-all).
