# Walkthrough script and transcript

The published walkthrough is **caption-led**: the on-screen captions in the video
carry the explanation. **No narration audio was produced**, and the MP4 has no audio
track. This file is the verified script — it is what a narrator would read, and it
doubles as the transcript of the on-screen text.

Every claim here was checked against the running application before recording.
Timings are approximate and follow the recording produced by
[`scripts/record_demo_walkthrough.js`](../scripts/record_demo_walkthrough.js).

All data shown is simulated demo data from
[`scripts/demo_fixture.php`](../scripts/demo_fixture.php): fictitious customers,
reserved documentation addresses and `.example` hostnames. No live firewall is
contacted at any point.

---

## 0:00 — Title

> OPNManager. Self-hosted OPNsense fleet management for MSPs and IT teams.
> Every firewall you look after, grouped by customer and site.
> Everything shown here is simulated demo data.

## 0:10 — Orientation: the fleet dashboard

> Start with what needs attention. The roll-up tiles only appear when the count is
> non-zero, so a quiet fleet stays a short page.
>
> This demo fleet is eleven firewalls across five customers: one offline, four with
> updates pending, two that have drifted from their approved baseline, and three
> certificates inside the warning window.
>
> Below the tiles, every firewall with its health score, uptime, check-in age and
> agent version.

## 0:35 — Workflow one: find a connectivity problem

> Problems become incidents — one entry per ongoing condition, opened when it starts
> and closed when it actually clears, rather than one email per poll. Acknowledging
> stops the notifications without closing the incident.
>
> Opening the affected firewall shows what the agent reported: gateways with latency
> and loss, VPN tunnels with handshake age, service state, and certificate expiry.
> Here the backup LTE gateway is degraded at 148 milliseconds and 2.4 percent loss,
> and the intrusion detection service is configured but not running.
>
> Certificate metadata only — private key material is never collected or stored.

## 1:15 — Workflow two: review what changed

> Mark a configuration backup as the baseline and every firewall is compared against
> it. Serialisation noise, and the revision block OPNsense stamps on every save, are
> ignored — so an untouched firewall does not report drift.
>
> The diff names each change by its description rather than by line number. On this
> firewall two rules were added since the baseline: an RDP forward, and a guest VLAN
> permitted to the internet. Nothing here restores anything; you decide what happens
> next.

## 1:55 — Both themes

> The whole interface has a light theme, with system preference detection and a
> manual toggle.

## 2:05 — Workflow three: plan an update

> Updates run as campaigns through canary, pilot and production rings, with manual
> progression between them. Rings are a rollout mechanism, not customer tiers.
>
> Members of a CARP pair are never updated at the same time — the backup member goes
> first so the master keeps serving, and the second waits until the first is back
> with CARP settled.
>
> Maintenance windows can be scoped to a firewall, a site or a whole customer.
> Monitoring, health collection and incident recording all continue during a window;
> only the outbound notification is withheld, and the suppression is recorded on the
> incident.

## 2:45 — Monitoring, reporting and administration

> Per-firewall traffic, load, memory and disk, collected on each agent check-in.
>
> The audit log answers who did what, to which firewall, from where — filterable by
> action, user, firewall, result and date range. Credential material is never
> recorded.
>
> Customers and sites are organisational groupings. They have no accounts and never
> log in; only your own staff sign in, subject to their role.

## 3:15 — Where to get it

> OPNManager is free and self-hosted. The code, the install guide and the full
> screenshot gallery are on GitHub at github dot com slash agit8or1 slash OPNMGR.
>
> It is one of several free tools for MSPs at mspreboot dot com.

---

## Producing narration later

If narration is added, record against this script and keep the caption timings. The
captions in [`walkthrough.vtt`](walkthrough.vtt) match the on-screen text and can be
reused as subtitles for a narrated cut.
