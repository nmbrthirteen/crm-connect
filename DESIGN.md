# CRM Connect — Design Spec

A whitelabeled WordPress plugin that captures **every** Elementor form submission — including every field, UTM, and trackable — and reliably forwards it, server-side, to Freshsales (Freshworks CRM) via a configurable per-form field mapping. Built internal-first for upgaming.com, architected to generalize to other CRMs later.

- **Code slug / text domain:** `crm-connect` (neutral — never references Freshsales or a brand)
- **UI display name (default, editable):** "CRM Connect" — the deployment sets its own brand in the UI; no brand is hardcoded

---

## 1. Core mandate

> Miss **nothing** — not a single field, UTM parameter, or trackable — and do it **reliably**, server-side, so no ad-blocker or CRM outage can lose a lead.

Every design decision below serves that mandate.

---

## 2. Architecture spine

```
Elementor submit (AJAX)
  → elementor_pro/forms/new_record hook (server-side)
      → write COMPLETE payload to crm_connect queue table   ← "never lose it" guarantee (persist BEFORE any CRM call)
  → background worker (wp-cron heartbeat)
      → CRM_Provider (Freshsales) — dynamic field mapping
      → retries w/ exponential backoff, rate-limit aware
      → success | dead-letter
  → reconciliation cron (defense in depth)
      → diff Elementor's wp_e_submissions vs our queue → backfill anything missing
```

The submission is **persisted to our DB before any network call**, so a slow/down/rate-limited CRM can never lose data and never blocks the form response.

---

## 3. Capture layer (the "miss nothing" front door)

**Submission capture:** `elementor_pro/forms/new_record( $record, $ajax_handler )`
- Fields: `$record->get('fields')` → `[ id => [ value, raw_value, type, title ] ]`
- Form identity: `$record->get_form_settings('form_name')` and `'id'`
- Meta (free, server-side): `page_url`, `remote_ip`, `user_agent`, timestamp

**Attribution capture (non-blockable):** first-party JS on every page writes an attribution cookie on landing. First-party + same-domain ⇒ ad-blockers don't touch it; the actual CRM send is server→server ⇒ no client blocker can reach it.
- Captures **first-touch** (set once, never overwritten) **and last-touch** (updated each visit)
- Stores: `utm_source/medium/campaign/term/content`, `gclid`, `fbclid`, `msclkid`, referrer, landing page, first/last timestamps
- Server reads it from `$_COOKIE` during the hook — independent of whether the form contains hidden fields
- Optional redundancy: auto-inject hidden fields into Elementor forms as a fallback source

**Everything captured becomes a mappable field** in the UI (form fields + all trackables + Elementor meta + Freshsales visitor id if present).

---

## 4. Mapping model

**Per-form profiles ("feeds").** Each profile:
- targets **one Elementor form** (matched by `form_name` + `form_id`)
- contains **one or more destinations** (two-layer: e.g. Contact **and** Deal from a single submission)
- has its own field map and dedup rule
- a form with no profile is ignored

**Form-field discovery (before any submission):** parse the page's `_elementor_data` post meta for `widgetType: "form"` → `form_fields` repeater (`custom_id`, `field_label`, `field_type`). Fallback/enrichment: if a submission brings a field the parser missed, add it to the known-fields list automatically.

**Freshsales-field discovery:** `GET /api/settings/<entity>/fields` (`contacts`, `deals`, `sales_accounts`) → all fields incl. custom + dropdown choices. **Cached** (rate-limit budget), manual "refresh fields" button.

**Mapping UI:** all form fields (left) ↔ all Freshsales fields incl. custom (right), both live. Type-aware:
- Dropdowns: map each form value → a valid Freshsales choice in the UI
- Text/number/date/email/phone: validated passthrough
- Static/default values per destination for required CRM fields

**Unmapped fields — admin-gated auto-create (chosen path):**
- One-click **"+ Create in Freshsales"** per unmapped form field → `POST /api/settings/<entity>/forms/<form_id>/fields` (text/number/dropdown/radio/lookup/multiselect), then wires the mapping. Deliberate, visible, once per field — never silent-on-every-submission (avoids schema rot, respects API scope/rate limits).
- Anything still unmapped → **catch-all "Submission details" long-text field + a note/activity** on the record, so it's visible in the CRM.
- Raw payload is **always** in our DB regardless → nothing is ever truly lost.

**Dedup:** `POST /api/contacts/upsert` with `unique_identifier` (email) → create-or-update, no duplicate contacts.

**Deal layer (`POST /api/deals` requires `name`, `amount`, `sales_account_id`):**
- `name` from a profile template, e.g. `"{company} — {form_name}"`
- `amount` from a mapped field or a static default
- `sales_account_id` via **lookup-or-create Sales Account by company name** before deal creation

---

## 5. Reliability stack

- **Durable queue:** custom table; complete payload persisted in the hook before any CRM call.
- **Worker:** wp-cron minute heartbeat (no external dependency) + low-latency nudge on submit. Exponential-backoff retries → **dead-letter** on exhaustion. wp-cron's `doing_cron` lock serializes runs, avoiding double-send races.
- **Rate-limit aware:** Freshsales allows **1000 req/hr/account**, returns 429. Worker throttles, honors `Retry-After`, caches schema, batches where possible.
- **Auto-pause:** after N consecutive failures the worker auto-pauses (so it doesn't burn the hourly budget hammering a broken endpoint) + alerts; manual **Resume** button.
- **Reconciliation cron:** periodically diffs Elementor's `wp_e_submissions` against our queue and backfills/re-queues anything missing — so even a fatal error in *our own* hook self-heals.

---

## 6. Observability & recovery

- **Submissions log:** every submission with status (`queued → sending → sent / failed / dead-letter`), full captured payload, exact Freshsales request + response/error, attempt count, **single + bulk Retry**.
- **Alerting:** email (+ optional Slack webhook) on dead-letter, invalid API key, or backed-up queue.
- **Health widget:** queue depth, last successful send, today's success/fail counts, remaining hourly rate-limit budget.

---

## 7. Consent, PII & retention

- **Cookie:** first-party, ~90-day lifetime, **default on**, gated behind a `filter` so a consent manager (Cookiebot/etc.) can suppress it when required.
- **Retention:** setting to auto-purge queue rows older than N days (**default 180**).
- **Deletion:** one admin action to erase a submission's stored data (GDPR right-to-be-forgotten).

---

## 8. Generalization seam

A `CRM_Provider` interface — `list_objects()`, `discover_fields($object)`, `upsert_record($object, $data)`, `create_field($object, $spec)`. **Freshsales** is the first concrete implementation. The queue, capture layer, mapping UI, reconciliation, logging, and alerting are all **CRM-agnostic** and talk only to this interface. Adding HubSpot/Pipedrive later = one new provider class, zero changes to the spine.

---

## 9. Whitelabel & security

- **Branding editable in UI:** display name (default "Upgaming CRM Connect"), logo, admin-menu label — rebrandable per deployment without code changes. Code slug stays `crm-connect`.
- **Security:** nonce + `current_user_can` capability checks on all admin/AJAX endpoints; API key + bundle alias stored encrypted at rest; least-privilege.

---

## 10. Settings stored

- Freshsales **bundle alias** (`<bundle>.myfreshworks.com`) + **API key** (`Authorization: Token token=KEY`)
- Branding (name/logo/menu label)
- Per-form **profiles** (form match → destinations → field map → dedup rule → deal template)
- Alerting (email, Slack webhook), retention days, consent filter toggle, auto-pause threshold

---

## Verified API facts (do not re-derive)

**Elementor**
- Hook: `elementor_pro/forms/new_record($record, $ajax_handler)`; `$record->get('fields')`, `$record->get_form_settings('form_name'|'id')`; meta carries `page_url`/`remote_ip`/`user_agent`.
- Forms in `_elementor_data` post meta as `widgetType:"form"` → `form_fields` repeater (`custom_id`,`field_label`,`field_type`).
- Submissions also stored in `wp_e_submissions` / `wp_e_submissions_values` (reconciliation source).

**Freshsales Suite**
- Base `https://<bundle>.myfreshworks.com/crm/sales/api/…`; auth `Authorization: Token token=KEY`.
- Upsert contact `POST /api/contacts/upsert` (`unique_identifier`); deal `POST /api/deals` (needs `name`,`amount`,`sales_account_id`).
- Field schema `GET /api/settings/{contacts|deals|sales_accounts}/fields` (incl. custom + choices).
- Create custom field `POST /api/settings/<entity>/forms/<form_id>/fields` (text/number/dropdown/radio/lookup/multiselect).
- Rate limit **1000 req/hr/account**, 429 on exceed.
