# Shared policies and configuration templates (3.79)

Code: `inc/policy/*.php`, pages `policy_templates.php`, `policy_template.php`,
`policy_deploy.php`, `policy_compliance.php`, agent endpoint
`api/policy_artifact.php`, jobs `cron/policy_reconcile.php` and
`cron/policy_compliance.php`, schema `database/migrations/0025_policy_templates.sql`.

## Status: preview everywhere, apply on lab firewalls only

The platform capability `policy.apply` is still *untested* on every platform:
nothing has been run on a live OPNsense or pfSense device. That means template
application is only possible on firewalls listed in the setting
`policy_apply_lab_firewall_ids`. Everywhere else, plans carry the blocker
"Preview only: …" and the UI explains why. Previews, plans and compliance work
on every firewall whose configuration parses.

## Model

- **Template**: has revisions. Each revision goes draft → published → retired.
  A published revision cannot be changed. Its `content_hash` (sha256 of the
  canonical JSON) is re-checked every time the revision is planned.
- **Components** (a bounded set): `alias` (host/network/port), `filter_rule`,
  `dns_servers`, `ntp_servers`, `management_access` (expands to a sources
  alias, a ports alias, an allow rule and an optional block rule; the web GUI
  is never rewritten), `remote_syslog`. Compatibility per platform:

  | Component | OPNsense 26.x | pfSense CE / Plus |
  |---|---|---|
  | alias | MVC `OPNsense/Firewall/Alias` (legacy `aliases` if no MVC) | `aliases/alias` |
  | filter_rule | MVC `OPNsense/Firewall/Filter/rules` (legacy `filter/rule` if no MVC) | `filter/rule` |
  | dns_servers / ntp_servers | `system/dnsserver`, `system/timeservers` | same |
  | remote_syslog | MVC `Syslog/destinations` (unsupported without MVC Syslog) | `syslog/remoteserver[1-3]`, UDP only |

- **Variables** have types: `ip`, `cidr` (must be network-aligned), `network`,
  `host`, `port` (a port or a range `a-b`), `protocol`, `interface` (the logical
  interface key), `string`, and `list:` forms of these. In a template you
  reference one as `{{name}}`. Values inherit global < customer < site <
  firewall. At the same scope, a value set for one template beats a value set
  for all templates. Missing or invalid values are errors, and a plan with
  errors cannot be approved. Interface mappings must exist in the target
  config.
- **Assignments** follow the same precedence, which the UI shows: the most
  specific scope wins. The template is reported as a conflict and not planned
  when either of these happens:
  - two revisions are assigned at the winning scope;
  - two templates would own the same alias name, or both set DNS or NTP.
- **Exceptions** apply per firewall to one object key, or `*` for the whole
  template. Each needs a reason and an owner, and may have an expiry.
  Exceptions are revoked, never deleted. They are audited and kept in the
  history.

## Managed-object identity

Every managed object's description ends with the marker
`[opnmgr:tpl=<template id>:obj=<component key>]`. Rules in a bottom block add
`:bottom` to the marker. MVC objects also get a deterministic UUID, and
pfSense rules a deterministic `<tracker>` (range 4000000000+), both derived
from tpl+key. Because these are deterministic, the same plan always renders
the same bytes. The artifact is therefore never stored. The endpoint
re-renders it from the approved base backup and serves it only when its
sha256 and plan hash match the approval.

An object without a marker is unmanaged. It is never modified, moved, removed
or adopted. A template object whose name matches an unmanaged object blocks
the plan until someone creates an explicit mapping
(`policy_object_mappings`). The next plan then shows an **adopt** change,
field by field. For pfSense syslog slots, which have no description field,
the slot is found from the value OPNMGR last applied.

## Ordering

Each template's rules form contiguous blocks inside the section it writes:

- **top** rules sit before all unmanaged rules;
- **bottom** rules sit after them;
- blocks from several templates are ordered by template id.

Planning one template never moves another template's rules. Placement depends
only on the config, so re-planning a result never shows a reorder.

- **OPNsense MVC**: evaluation follows `<sequence>`. Top rules get 1..n and
  bottom rules 99000+i. They are also placed first or last in the document.
  How OPNsense breaks ties between equal sequence numbers is **not verified**;
  the plan warns when unmanaged rules share the top band. Whether MVC rules
  are evaluated before or after legacy rules is decided by OPNsense, not
  OPNMGR.
- **pfSense / legacy**: evaluation follows document order. pfSense always
  evaluates floating rules before interface rules, wherever they sit in the
  document. A managed floating rule therefore acts before every interface
  rule. Its top or bottom position only orders it among the other floating
  rules. A managed interface rule only competes with rules on the same
  interface.

## Workflow and safety

Each deployment goes through these steps:

1. Pick a revision and targets.
2. Plans are built per firewall from the newest **validated** backup. Its age
   must be at most `policy_max_backup_age_minutes` (default 60). If it is
   older, the plan cannot be approved, and the page offers "Request fresh
   backup" and "Re-plan".
3. Each plan lists the creates, modifies (field from → to), adopts, reorders
   and deletes, plus unsupported items, exceptions, warnings and blockers.
4. **Approval** (`template.approve`) binds the target, firewall, revision,
   content hash, plan hash and base fingerprint into `approval_hash`.
5. **Execution** (`template.apply`) runs one target at a time, in ring order
   (canary → pilot → production, from `firewalls.update_ring`). Before each
   target it:
   - re-checks `policy.apply`;
   - re-checks HA safety (`ha_dispatch_check`);
   - re-plans from the newest backup. If anything differs, the target becomes
     `stale`, the approval is cleared, and the deployment returns to
     `planned`.
6. It then takes the per-firewall DB lock (`policy_firewall_locks`), queues a
   pre-change backup, and queues the apply.
7. **Device script** (`inc/policy/device.php`):
   - refuses if sha256(config.xml) differs from the base fingerprint;
   - fetches the artifact with the agent credentials plus a single-use token;
   - checks the artifact's sha256 and its root element (it must be the
     device's own platform);
   - saves the current config in `/conf/opnmgr` (OPNsense) or
     `/cf/conf/opnmgr` (pfSense) and writes a state file;
   - installs a recovery script and a boot hook
     (`rc.syshook.d/start/60-opnmgr-policy-recover` on OPNsense,
     `/usr/local/etc/rc.d/opnmgr_policy_recover.sh` on pfSense);
   - starts the timer detached (`daemon -f`, falling back to `nohup`)
     **before** installing;
   - installs through the platform's own locked mechanism
     (`installConfigShell`).

   Every outcome is printed as an `OPNMGR_POLICY_*` marker.
8. **Verification**:
   - an `ok` marker moves the target to verifying and requests a read-back
     backup;
   - a confirm is queued only once that backup re-plans to zero changes with
     exactly the planned managed objects, **and** the agent has checked in
     after the apply result;
   - a mismatch, or a timeout near the deadline (120 s margin), queues a
     rollback;
   - a "completed" command without a marker is never treated as success.
9. **Recovery**:
   - without a confirm, the timer reinstalls the saved config at the deadline
     (`policy_recovery_window_seconds`, default 900);
   - after a reboot, the boot hook re-arms the timer, or reverts at once if
     the deadline has passed;
   - confirm and revert both claim the state file with a single `mv`, so only
     one of them can win.
10. **Failure**: the first failure stops the deployment, holds every later
    target, releases the lock, and emits `integration_emit('policy.deployment_failed', …)`
    if that function exists.
11. **Management path**: the plan is blocked by any managed block/reject rule
    that could match the manager's addresses (setting
    `policy_manager_source_ips`) inbound on 22/80/443/8443, or traffic to the
    manager. If that setting is empty, every block rule is blocked.
12. **HA**: when a firewall is the sync source (it has `synchronizetoip`) and
    the plan touches sections it syncs, only that firewall is written. Its
    partner is `secondary_verify`: it is checked by read-back and never
    written. Deploying to the secondary on its own is blocked.

## Compliance and drift

`cron/policy_compliance.php` and the Compliance page re-plan every effective
template against the newest backup and give each one a state:

- `compliant`
- `pending`: the desired revision has not been applied yet
- `drifted`: the revision was applied, then the managed objects were changed
  out of band (the count is shown)
- `exception`
- `conflict`
- `invalid`
- `unknown`

The existing whole-config drift status is shown next to these. Nothing is
corrected automatically.

## Cron entries to add

```
*/2 * * * * www-data php /var/www/opnsense/cron/policy_reconcile.php  >> /var/log/opnmgr/policy_reconcile.log 2>&1
17 * * * *  www-data php /var/www/opnsense/cron/policy_compliance.php >> /var/log/opnmgr/policy_compliance.log 2>&1
```

## Known limitations

- Nothing has been verified on a live device. The device scripts are
  exercised only under `/bin/sh` with stubbed binaries. The pfSense fixtures
  are synthetic.
- The MVC field set and the sequence range are taken from the fixtures, not
  validated against OPNsense's model validators. OPNsense tie-breaking on
  equal sequence numbers is unverified.
- OPNsense HA sync is not triggered by OPNMGR. A secondary that is never
  synced fails verification, which is reported, not hidden. The mapping from
  components to sync items is best effort. On an HA secondary, components
  that are not synced are not applied in this release.
- DOM serialisation writes empty elements as `<x/>`. Files are identical in
  canonical XML but not byte for byte; the tests compare canonical XML.
- pfSense remote syslog categories (`logall`, …) are not managed. On pfSense,
  `dnsNgw` bindings are not managed; the plan warns about them.
- The single-use fetch token appears in the queued command text, as it does
  for config restore. It is burned on first use.
