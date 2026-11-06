# Ticketing and notification integrations

Added in 3.79.0. Sends incident and report events to email, Slack, Microsoft
Teams, a signed generic webhook and a generic ticketing webhook, with a
reliable delivery pipeline and optional two-way ticket sync.

Nothing is sent until an administrator creates a destination **and** a routing
rule. The existing alert mailer is unchanged unless you hand email over (below).

## Connectors

| Type | Notes |
|---|---|
| Email | The existing SMTP settings (`smtp_*`). Recipients are configured per destination. |
| Signed webhook | JSON envelope, HMAC-SHA256 signature (see below). |
| Slack | Incoming webhook `https://hooks.slack.com/services/...`. `{"text","blocks"}`. 429 + `Retry-After` is honoured. |
| Microsoft Teams | **Workflows (Power Automate) webhook** ("When a Teams webhook request is received"). Office 365 Connector URLs (`*.webhook.office.com`) were retired in May 2026 and are rejected with an explanation. Posts appear as the Workflows bot; no custom name or icon. Payload: a `message` with one Adaptive Card 1.4 attachment. |
| Generic ticketing webhook | create / update / resolve / reopen / comment calls to URLs you configure, plus a JSON path to the ticket id in the create response. |

There are **no native connectors** for Zammad, GLPI, HaloPSA, ConnectWise or
Autotask, and none were tested against those products. The connector interface
(`inc/integrations/connector_base.php`) lets one be added: implement
`OpnmgrIntegrationConnector` and call `integration_register_connector()`.

## Events and envelope

Event types: `incident.opened`, `incident.severity_changed`,
`incident.acknowledged`, `incident.resolved`, `incident.reopened` (a
recurrence of a condition resolved within the reopen window),
`incident.escalated` (escalation routes only), `policy.deployment_failed`,
`report.ready`, and `integration.test` (Test button only).

Other modules emit with `integration_emit(string $eventType, array $context): ?string`
(`inc/integrations/events.php`). It writes the outbox row on the caller's
connection (so it joins an open transaction), does no network I/O, never throws,
and returns the event id. A repeated `dedupe_key` returns the original id.

```json
{
  "schema": "opnmanager.event", "version": 1,
  "id": "uuid", "type": "incident.opened",
  "occurred_at": "2026-11-06T12:00:00Z",
  "correlation_id": "incident-123",
  "origin": {"kind": "local|inbound", "destination_id": null},
  "customer": {"id": 1, "name": "..."}, "site": {"id": 2, "name": "..."},
  "firewall": {"id": 3, "hostname": "..."},
  "platform": {"family": "...", "edition": "..."},
  "tags": ["..."],
  "incident": {"id": 123, "type": "gateway.down", "object_key": "WAN_GW", "severity": "critical",
               "previous_severity": null, "status": "open", "first_seen_at": "...",
               "occurrence_count": 1, "recurrence_of": null},
  "severity": "critical",
  "summary": "sanitised one-liner", "details": {},
  "links": {"manager": "https://manager.example/incidents.php?incident=123"},
  "delivery": {"id": 55, "idempotency_key": "sha256", "attempt": 1}
}
```

`delivery` is added per destination at send time. The summary and details pass
through key-based scrubbing (passwords, secrets, tokens, private keys, raw
configuration) and text redaction (PEM blocks, bearer tokens, `key=value`
secrets, long opaque tokens). Credentials, private keys and raw configurations
are not part of the envelope. The manager link requires login.

## Signed webhook headers

```
Content-Type: application/json
X-OPNManager-Event, X-OPNManager-Event-Id, X-OPNManager-Delivery-Id
X-OPNManager-Idempotency-Key  (also sent as Idempotency-Key)
X-OPNManager-Timestamp: <unix seconds>
X-OPNManager-Signature: v1=<hex HMAC-SHA256(secret, timestamp + "." + raw body)>
```

Receivers should verify over the raw body, reject timestamps outside their
window, and de-duplicate on the idempotency key.

## Routing

A rule sends matching events to one destination. Filters: event types, customer,
site, severity, incident type, device tag (any of), a schedule (timezone, days,
from/until; outside it the event is skipped or deferred to the next window), and
an escalation threshold: "only if still unacknowledged after N minutes" (fires
once per incident, never for an acknowledged incident, waits out maintenance).
A destination bound to a customer never receives another customer's events.
Per event, each destination is used once (the first matching rule wins).
**Routing preview** (Routing rules page) shows, for a sample incident, which rules
match, why others do not, and whether delivery would be withheld.

Withholding, applied at routing **and again just before sending**:

* acknowledged or resolved incident: no opened / reopened / severity / escalation message;
* active maintenance window with `suppress_alerts`: same; a ticket that already exists still gets updates;
* acknowledged / resolved messages reach only chat/email destinations that were told about the incident;
* an event caused by inbound sync is never sent back to its origin destination.

Withheld events are stored as `suppressed` deliveries with the reason.

### Email: avoiding duplicates with the existing mailer

Setting `integrations_legacy_email_owner` (Integrations page):

* `legacy` (default): `cron/evaluate_alerts.php` keeps mailing opted-in admins. An email
  destination drops any recipient the legacy mailer would already mail for that
  severity, so nobody receives both.
* `integrations`: the legacy incident mail stands down; email destinations send.

## Delivery reliability

* Outbox: `integration_events`. Dispatcher routes events into `integration_deliveries`.
* Idempotency key = `sha256("dest:<id>|event:<event id>")`, unique per destination.
  Routing an event twice, or two workers, produce one send.
* Leasing: `SELECT ... FOR UPDATE SKIP LOCKED` while holding a lock on the
  destination row, so the per-destination concurrency limit is exact across
  processes. Expired leases (crashed worker) return to the retry queue.
* Retries: exponential backoff 30 s, 60 s, 120 s ... capped at 1 h, +/-20% jitter, up
  to `max_attempts` (default 6), then `dead` (dead-letter queue). `429` pauses
  the destination for `Retry-After` and does not consume attempts.
* Replay: a dead delivery is reset in place (same idempotency key); a succeeded
  or suppressed notification is re-sent as a new delivery. Permission `integration.replay`.
* Errors are redacted (known secrets, URL paths, tokens) before storage.

### Tickets

One live mapping per incident and destination (`integration_ticket_links`).
Later events update that ticket; a persisting condition emits nothing more.
Per-destination policy: on resolve (resolve / comment only / nothing), on
recurrence (reopen the previous ticket within a window / open a new ticket), on
external close while still failing (record only / reopen, at most hourly).
Ticket deliveries for an incident are sent in order.

**Ambiguous create.** `outcome_unknown` is stored before the create request is
sent, with the idempotency key as `Idempotency-Key`. If the result is lost
(timeout, reset, 5xx): with a lookup URL the next attempt looks the ticket up by
key and adopts it; without one the mapping becomes `needs_reconciliation` and
**no second create is ever sent**. An operator then chooses: link the existing
ticket id, confirm "not created" (retry with the same key), or abandon.
Only failures before any connection was made (DNS, connect refused, TLS
verification) are retried as plainly safe.

## Inbound sync

`POST /api/integration_inbound.php?destination=<id>`, JSON body, headers
`X-OPNManager-Timestamp`, `X-OPNManager-Signature` (`v1=` HMAC-SHA256 of
`timestamp.body` with the destination's **inbound secret**), and a unique
`X-OPNManager-Nonce` (or `event_id` in the body). Checked in order: destination
exists, enabled and has an inbound secret; signature; timestamp within 300 s
(`integrations_inbound_window_seconds`); nonce unused (stored hashed, kept 2
days). All failures return the same 401 and are logged. Body limit 64 KiB.

Types: `ticket.acknowledged`, `ticket.comment`, `ticket.assigned`,
`ticket.closed`, `ticket.reopened`, `ticket.updated`, with `external_id`.

* acknowledgement acknowledges the local incident (if enabled), attributed `ext:<destination>`;
* comment adds an audited, redacted note to the incident timeline (if enabled);
* assignment sets `alert_incidents.owner` (if enabled);
* closed records ticket state only and flags "closed while failing". The incident
  stays open: it clears when the condition clears.
* Loops: events caused by inbound sync carry their origin and are not sent back
  to that destination (recorded as suppressed); other destinations still hear them.

Outbound polling for LAN/VPN installs: give the ticketing destination a status
poll URL template and JSON path; `cron/integration_poll.php` applies the same
actions without any inbound exposure.

## Security

* Secrets (all URLs, tokens, signing/inbound secrets) are encrypted at rest
  (`opnmgr_encrypt`), masked in the UI (blank on edit keeps the value), absent
  from audit entries. Configure `OPNMGR_MASTER_KEY`, otherwise they are stored plain.
* Every request resolves DNS once, checks all answers, pins the chosen IP with
  `CURLOPT_RESOLVE`, never follows redirects (a 3xx is a failure), caps the
  response size, and refuses private, loopback, link-local, CGNAT, multicast,
  reserved and cloud-metadata addresses (IPv4, IPv6, mapped and NAT64) unless the
  destination has **allow internal endpoint** (also required for plain http).
* The resolved address is recorded per destination; a change is audited, shown
  on the Integrations page, and with policy `block` holds delivery until accepted.
* Configuration needs `integration.manage` (admin); history and mappings need
  `integration.view`; replay and reconciliation need `integration.replay`.
  Staff scoped to customers (`inc/scope.php`) see only destinations, rules,
  deliveries and mappings of those customers.
* The Test button sends a message labelled as a test, only when pressed.

## Operations

Cron (not installed automatically; logs under `/var/log/opnmgr`):

```
* * * * *   www-data php /var/www/opnsense/cron/integration_dispatch.php >> /var/log/opnmgr/integration_dispatch.log 2>&1
*/5 * * * * www-data php /var/www/opnsense/cron/integration_poll.php     >> /var/log/opnmgr/integration_poll.log 2>&1
```

Both are registered in `scheduled_tasks` (migration 0028). Tests:
`tests/integration_pipeline_test.php`, `tests/integration_tickets_test.php`
(use a disposable `opnsense_fw_test_*` database).

## Known limits

* Events reach destinations on the next dispatcher run (up to a minute).
* Incidents resolved by the evaluator's orphan sweep (firewall deleted) are closed
  by direct SQL and emit no event, so a ticket for them is not resolved.
* Email retry resends to all recipients of the destination.
* `database/schema.sql` must be regenerated (`scripts/generate_schema.sh`) to
  include migration 0028.
