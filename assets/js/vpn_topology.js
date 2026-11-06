/*
 * VPN topology: interactive map, searchable table, findings and detail panel.
 * Plain SVG/JS, no libraries. Everything from the server is inserted as
 * text nodes only, so no value in a configuration can inject markup.
 */
(function () {
  'use strict';
  var cfgEl = document.getElementById('vpn-config');
  var CFG = cfgEl ? JSON.parse(cfgEl.textContent) : {};
  var STATE_LABEL = { up: 'Up', degraded: 'Degraded', idle: 'Idle', down: 'Down', disabled: 'Disabled', unknown: 'Unknown', stale: 'Stale' };
  var REL_LABEL = { observed: 'Observed', inferred: 'Inferred', manual: 'Manual', unpaired: 'Unpaired' };
  var KIND_LABEL = { site_to_site: 'Site-to-site', remote_access: 'Remote access', external: 'External peer', unknown: 'Unknown', ambiguous: 'Ambiguous' };
  var NS = 'http://www.w3.org/2000/svg';
  var data = null, prefs = { view: 'map', positions: {}, filters: {} }, selected = null, saveTimer = null;
  var pos = {};

  function $(id) { return document.getElementById(id); }
  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    if (attrs) for (var k in attrs) { if (k === 'class') e.className = attrs[k]; else e.setAttribute(k, attrs[k]); }
    if (text !== undefined && text !== null) e.textContent = text;
    return e;
  }
  function svg(tag, attrs, text) {
    var e = document.createElementNS(NS, tag);
    if (attrs) for (var k in attrs) e.setAttribute(k, attrs[k]);
    if (text !== undefined) e.textContent = text;
    return e;
  }
  function badge(text, cls) { return el('span', { class: 'badge me-1 ' + cls }, text); }
  function stateBadge(s) { return badge(STATE_LABEL[s] || s, 'vpn-badge vpn-st-' + s); }
  function relBadge(r) { return badge(REL_LABEL[r] || r, 'vpn-badge vpn-rel-' + r); }
  function fmtAge(sec) {
    if (sec === null || sec === undefined) return 'n/a';
    if (sec < 90) return sec + 's'; if (sec < 5400) return Math.round(sec / 60) + 'm';
    if (sec < 172800) return (sec / 3600).toFixed(1) + 'h'; return Math.round(sec / 86400) + 'd';
  }
  function fmtBytes(n) {
    if (n === null || n === undefined) return 'n/a';
    var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0; n = Number(n);
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i ? n.toFixed(1) : n) + ' ' + u[i];
  }
  function api(url, body) {
    var opt = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (body) { opt.method = 'POST'; opt.headers['Content-Type'] = 'application/json'; body.csrf = CFG.csrf; opt.body = JSON.stringify(body); }
    return fetch(url, opt).then(function (r) { return r.json().catch(function () { return { success: false, message: 'Bad response' }; }); });
  }

  // ---------------------------------------------------------------- prefs
  function savePrefsSoon() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () { api('/api/vpn_prefs.php', { prefs: prefs }); }, 800);
  }

  // -------------------------------------------------------------- filters
  var FILTERS = ['customer', 'site', 'platform', 'protocol', 'state', 'relationship', 'kind'];
  function readFilters() {
    var f = {};
    FILTERS.forEach(function (k) { var v = $('f-' + k).value; if (v) f[k] = v; });
    var q = $('f-q').value.trim(); if (q) f.q = q;
    return f;
  }
  function applyFilterUI() {
    FILTERS.forEach(function (k) { $('f-' + k).value = prefs.filters[k] || ''; });
    $('f-q').value = prefs.filters.q || '';
  }
  function tunnelMatches(t, f) {
    if (f.customer && t.customer_ids.map(String).indexOf(f.customer) < 0) return false;
    if (f.site && t.site_ids.map(String).indexOf(f.site) < 0) return false;
    if (f.platform && t.platforms.indexOf(f.platform) < 0) return false;
    if (f.protocol && t.protocol !== f.protocol) return false;
    if (f.state && t.state !== f.state) return false;
    if (f.relationship && t.relationship !== f.relationship) return false;
    if (f.kind && t.kind !== f.kind) return false;
    if (f.q) {
      var hay = [t.name, t.protocol, t.kind, t.a && t.a.name, t.b && t.b.name,
        t.a && t.a.local_networks.join(' '), t.a && t.a.remote_networks.join(' '), t.a && t.a.remote.host,
        t.b && t.b.local_networks.join(' '), t.b && t.b.remote_networks.join(' ')].join(' ').toLowerCase();
      if (hay.indexOf(f.q.toLowerCase()) < 0) return false;
    }
    return true;
  }
  function visibleTunnels() { var f = prefs.filters; return data.tunnels.filter(function (t) { return tunnelMatches(t, f); }); }

  // -------------------------------------------------------------- summary
  function renderSummary(list) {
    var c = {}; list.forEach(function (t) { c[t.state] = (c[t.state] || 0) + 1; });
    var box = $('vpn-summary'); box.textContent = '';
    ['up', 'degraded', 'idle', 'down', 'stale', 'unknown', 'disabled'].forEach(function (s) {
      var d = el('div', { class: 'vpn-tile vpn-st-' + s });
      d.appendChild(el('div', { class: 'vpn-tile-n' }, String(c[s] || 0)));
      d.appendChild(el('div', { class: 'vpn-tile-l' }, STATE_LABEL[s]));
      d.addEventListener('click', function () { $('f-state').value = prefs.filters.state === s ? '' : s; filtersChanged(); });
      box.appendChild(d);
    });
    $('vpn-asof').textContent = 'Built ' + new Date(data.generated_at * 1000).toLocaleString();
    var warn = $('vpn-hidden'); warn.textContent = data.hidden_findings ? data.hidden_findings + ' finding(s) involve firewalls outside your access scope and are not shown.' : '';
  }

  // ------------------------------------------------------------------ map
  function layout(nodes) {
    var fws = nodes.filter(function (n) { return n.type === 'firewall'; });
    var W = 900, H = 560, cx = W / 2, cy = H / 2, R = Math.min(W, H) / 2 - 90;
    fws.sort(function (a, b) { return String(a.customer).localeCompare(String(b.customer)) || a.label.localeCompare(b.label); });
    fws.forEach(function (n, i) {
      if (pos[n.id]) return;
      var a = (2 * Math.PI * i / Math.max(1, fws.length)) - Math.PI / 2;
      pos[n.id] = fws.length === 1 ? { x: cx, y: cy } : { x: cx + R * Math.cos(a), y: cy + R * Math.sin(a) };
    });
    var per = {};
    nodes.forEach(function (n) {
      if (n.type === 'firewall' || pos[n.id]) return;
      var owner = n.id.indexOf('ra:') === 0 ? 'fw' + n.id.split(':')[1] : null;
      if (!owner) { var e = data.graph.edges.filter(function (x) { return x.to === n.id; })[0]; owner = e ? e.from : null; }
      var o = pos[owner] || { x: cx, y: cy }, k = per[owner] = (per[owner] || 0) + 1;
      var dx = o.x - cx, dy = o.y - cy, len = Math.sqrt(dx * dx + dy * dy) || 1;
      var base = Math.atan2(dy, dx) + (k - 1) * 0.55 - 0.3;
      pos[n.id] = { x: o.x + 120 * Math.cos(base) + (dx / len) * 20, y: o.y + 120 * Math.sin(base) + (dy / len) * 20 };
    });
  }
  function nodeVisible(n, vis) {
    if (n.type === 'firewall') {
      var f = prefs.filters;
      if (f.customer && String(n.customer_id) !== f.customer) return false;
      if (f.platform && n.platform !== f.platform) return false;
      return true;
    }
    return vis.edges.some(function (e) { return e.to === n.id; });
  }
  function renderMap(list) {
    var host = $('vpn-map'); host.textContent = '';
    var ids = {}; list.forEach(function (t) { ids[t.id] = 1; });
    var vis = { edges: data.graph.edges.filter(function (e) {
      if (e.tunnels) return e.tunnels.some(function (i) { return ids[i]; });
      return ids[e.tunnel];
    }) };
    var nodes = data.graph.nodes.filter(function (n) { return nodeVisible(n, vis); });
    layout(data.graph.nodes);
    var s = svg('svg', { viewBox: '0 0 900 560', class: 'vpn-svg', role: 'img', 'aria-label': 'VPN topology map' });
    var defs = svg('defs'); s.appendChild(defs);
    var shown = {}; nodes.forEach(function (n) { shown[n.id] = 1; });
    var gE = svg('g'), gN = svg('g'); s.appendChild(gE); s.appendChild(gN);
    var edgeEls = [];
    vis.edges.forEach(function (e) {
      if (!shown[e.from] || !shown[e.to]) return;
      var line = svg('line', { class: 'vpn-edge vpn-st-' + e.state + ' vpn-rel-' + e.relationship + (selected === (e.tunnel || e.id) ? ' sel' : ''), 'data-id': e.id });
      line.addEventListener('click', function () { select(e.tunnel || e.tunnels); });
      var tt = svg('title', {}, (e.protocol || '') + ' · ' + (STATE_LABEL[e.state] || e.state) + ' · ' + (REL_LABEL[e.relationship] || e.relationship)); line.appendChild(tt);
      gE.appendChild(line); edgeEls.push({ e: e, l: line });
      if (e.count) { var cnt = svg('text', { class: 'vpn-edge-count', 'data-edge': e.id }, String(e.count)); gE.appendChild(cnt); edgeEls[edgeEls.length - 1].c = cnt; }
    });
    function place() {
      edgeEls.forEach(function (x) {
        var a = pos[x.e.from], b = pos[x.e.to];
        x.l.setAttribute('x1', a.x); x.l.setAttribute('y1', a.y); x.l.setAttribute('x2', b.x); x.l.setAttribute('y2', b.y);
        if (x.c) { x.c.setAttribute('x', (a.x + b.x) / 2); x.c.setAttribute('y', (a.y + b.y) / 2 - 4); }
      });
    }
    nodes.forEach(function (n) {
      var g = svg('g', { class: 'vpn-node vpn-node-' + n.type, tabindex: 0 });
      var shape = n.type === 'firewall' ? svg('rect', { x: -46, y: -16, width: 92, height: 32, rx: 6 })
        : svg('ellipse', { rx: 44, ry: 17 });
      g.appendChild(shape);
      var label = n.type === 'firewall' ? n.label : (n.type === 'remote_access' ? n.label.replace('Remote access ', 'RA ') + ' ×' + n.count : n.label);
      g.appendChild(svg('text', { class: 'vpn-node-label', y: 4 }, label.length > 16 ? label.slice(0, 15) + '…' : label));
      g.appendChild(svg('title', {}, n.label + (n.detail ? ' · ' + n.detail : '') + (n.customer ? ' · ' + n.customer : '')));
      function put() { g.setAttribute('transform', 'translate(' + pos[n.id].x + ',' + pos[n.id].y + ')'); }
      put(); gN.appendChild(g);
      var drag = null;
      g.addEventListener('pointerdown', function (ev) {
        var p = pt(s, ev); drag = { dx: p.x - pos[n.id].x, dy: p.y - pos[n.id].y, moved: false };
        g.setPointerCapture(ev.pointerId); ev.preventDefault();
      });
      g.addEventListener('pointermove', function (ev) {
        if (!drag) return; var p = pt(s, ev);
        pos[n.id] = { x: Math.max(40, Math.min(860, p.x - drag.dx)), y: Math.max(20, Math.min(540, p.y - drag.dy)) };
        drag.moved = true; put(); place();
      });
      g.addEventListener('pointerup', function () {
        if (drag && drag.moved) { prefs.positions[n.id] = { x: pos[n.id].x, y: pos[n.id].y }; savePrefsSoon(); }
        else selectNode(n);
        drag = null;
      });
    });
    place();
    host.appendChild(s);
    if (!nodes.length) host.appendChild(el('p', { class: 'text-muted p-3' }, 'No tunnels match the current filters.'));
  }
  function pt(s, ev) {
    var p = s.createSVGPoint(); p.x = ev.clientX; p.y = ev.clientY;
    return p.matrixTransform(s.getScreenCTM().inverse());
  }
  function selectNode(n) {
    if (n.type === 'firewall') {
      var ts = data.tunnels.filter(function (t) { return t.firewall_ids.indexOf(n.firewall_id) >= 0; });
      detailFirewall(n, ts);
    } else if (n.type === 'remote_access') {
      select(data.tunnels.filter(function (t) { return t.kind === 'remote_access' && t.b === null && t.protocol === n.protocol && t.firewall_ids[0] === n.firewall_id; }).map(function (t) { return t.id; }));
    } else {
      var e = data.graph.edges.filter(function (x) { return x.to === n.id; })[0]; if (e) select(e.tunnel);
    }
  }

  // ---------------------------------------------------------------- table
  var sortKey = 'name', sortDir = 1;
  function renderTable(list) {
    var body = $('vpn-rows'); body.textContent = '';
    var keyFn = { name: function (t) { return t.name; }, state: function (t) { return t.state; }, protocol: function (t) { return t.protocol; },
      kind: function (t) { return t.kind; }, relationship: function (t) { return t.relationship; },
      activity: function (t) { return (t.a.state.last_activity_at || 0); } };
    list = list.slice().sort(function (a, b) { var x = keyFn[sortKey](a), y = keyFn[sortKey](b); return (x > y ? 1 : x < y ? -1 : 0) * sortDir; });
    list.forEach(function (t) {
      var tr = el('tr', { class: selected === t.id ? 'table-active' : '', style: 'cursor:pointer' });
      var c1 = el('td'); c1.appendChild(el('div', { class: 'fw-semibold' }, t.name));
      c1.appendChild(el('div', { class: 'small text-muted' }, nets(t))); tr.appendChild(c1);
      tr.appendChild(el('td', null, t.protocol));
      tr.appendChild(el('td', null, KIND_LABEL[t.kind] || t.kind));
      var c4 = el('td'); c4.appendChild(stateBadge(t.state)); if (t.partial) c4.appendChild(el('span', { class: 'small text-muted', title: 'Only one end is managed or observed' }, 'one side')); tr.appendChild(c4);
      var c5 = el('td'); c5.appendChild(relBadge(t.relationship)); tr.appendChild(c5);
      var la = t.a.state.last_activity_at; tr.appendChild(el('td', { class: 'small' }, la ? new Date(la * 1000).toLocaleString() : 'n/a'));
      tr.appendChild(el('td', { class: 'small' }, t.findings.length ? String(t.findings.length) : ''));
      tr.addEventListener('click', function () { select(t.id); });
      body.appendChild(tr);
    });
    if (!list.length) { var tr = el('tr'); var td = el('td', { colspan: 7, class: 'text-muted' }, 'No tunnels match the current filters.'); tr.appendChild(td); body.appendChild(tr); }
    $('vpn-count').textContent = list.length + ' of ' + data.tunnels.length + ' tunnels';
  }
  function nets(t) {
    var a = t.a, parts = [];
    if (a.local_networks.length) parts.push(a.local_networks.join(', '));
    var r = a.remote_networks.concat(t.b ? t.b.local_networks : []);
    if (r.length) parts.push('⇄ ' + Array.from(new Set(r)).join(', '));
    return parts.join(' ');
  }

  // ------------------------------------------------------------- findings
  function renderFindings() {
    var box = $('vpn-findings'); box.textContent = '';
    var vis = {}; visibleTunnels().forEach(function (t) { vis[t.id] = 1; });
    var f = prefs.filters;
    var list = data.findings.filter(function (x) {
      if (!x.affected.tunnels.length) return !f.customer && !f.state && !f.relationship && !f.kind;
      return x.affected.tunnels.some(function (i) { return vis[i]; });
    });
    $('vpn-finding-count').textContent = list.length;
    if (!list.length) box.appendChild(el('p', { class: 'text-muted' }, 'No findings for the current selection.'));
    list.forEach(function (x) { box.appendChild(findingCard(x)); });
  }
  function findingCard(x) {
    var c = el('div', { class: 'card mb-2 vpn-finding vpn-sev-' + x.severity, id: 'f-' + x.id });
    var b = el('div', { class: 'card-body py-2' }); c.appendChild(b);
    var h = el('div', { class: 'd-flex flex-wrap align-items-center gap-1' });
    h.appendChild(badge(x.severity, 'vpn-badge vpn-sevb-' + x.severity));
    h.appendChild(badge(x.basis, 'vpn-badge vpn-basis-' + x.basis));
    h.appendChild(badge('confidence ' + x.confidence, 'vpn-badge text-bg-secondary'));
    h.appendChild(el('strong', { class: 'ms-1' }, x.title)); b.appendChild(h);
    b.appendChild(el('p', { class: 'mb-1 small' }, x.explanation));
    var det = el('details', { class: 'small' }); det.appendChild(el('summary', null, 'Evidence, freshness and next step'));
    function sect(title, items) { if (!items || !items.length) return; det.appendChild(el('div', { class: 'fw-semibold mt-1' }, title)); var ul = el('ul', { class: 'mb-1' }); items.forEach(function (i) { ul.appendChild(el('li', null, i)); }); det.appendChild(ul); }
    sect('Evidence', x.evidence); sect('Data freshness', x.freshness);
    if (x.limitation) { det.appendChild(el('div', null)); det.lastChild.appendChild(el('span', { class: 'fw-semibold' }, 'Limitation: ')); det.lastChild.appendChild(document.createTextNode(x.limitation)); }
    if (x.next_step) { var n = el('div', { class: 'mt-1' }); n.appendChild(el('span', { class: 'fw-semibold' }, 'Next diagnostic: ')); n.appendChild(document.createTextNode(x.next_step));
      if (x.probe && x.affected.firewalls.length) {
        var q = '/vpn_diagnostics.php?firewall=' + x.affected.firewalls[0] + (x.affected.tunnels[0] ? '&tunnel=' + x.affected.tunnels[0] : '') + '&method=' + encodeURIComponent(x.probe.method) + (x.probe.target ? '&dest=' + encodeURIComponent(x.probe.target) : '') + (x.probe.size ? '&size=' + x.probe.size : '');
        var a = el('a', { href: q, class: 'ms-2' }, 'Open guided diagnostic'); n.appendChild(a); }
      det.appendChild(n); }
    b.appendChild(det);
    return c;
  }

  // --------------------------------------------------------------- detail
  function select(ids) {
    if (Array.isArray(ids)) {
      if (ids.length === 1) { return select(ids[0]); }
      selected = null; detailGroup(ids); return;
    }
    selected = ids; var t = data.tunnels.filter(function (x) { return x.id === ids; })[0]; if (t) detailTunnel(t); render();
  }
  function kv(dl, k, v) { if (v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length)) return; dl.appendChild(el('dt', { class: 'col-5 text-muted fw-normal' }, k)); dl.appendChild(el('dd', { class: 'col-7 mb-1' }, Array.isArray(v) ? v.join(', ') : String(v))); }
  function endBlock(title, e, st) {
    var d = el('div', { class: 'vpn-end mb-2' });
    var h = el('div', { class: 'fw-semibold' }); h.appendChild(document.createTextNode(title + ': ' + e.firewall_name + ' / ' + e.name + ' ')); h.appendChild(stateBadge(st.state)); d.appendChild(h);
    d.appendChild(el('div', { class: 'small' }, st.reason + ' (' + st.basis + ')'));
    if (st.evidence.length) { var ul = el('ul', { class: 'small mb-1' }); st.evidence.forEach(function (x) { ul.appendChild(el('li', null, x)); }); d.appendChild(ul); }
    st.limitations.forEach(function (x) { d.appendChild(el('div', { class: 'small text-muted fst-italic' }, x)); });
    var dl = el('dl', { class: 'row small mb-0' });
    kv(dl, 'Role', e.role.replace('_', ' ') + ' (' + e.role_source + ')'); kv(dl, 'Interface', e.instance); kv(dl, 'Tunnel addresses', e.tunnel_addresses);
    kv(dl, 'Protected networks (' + e.local_networks_basis + ')', e.local_networks); kv(dl, 'Remote networks', e.remote_networks);
    kv(dl, 'AllowedIPs', e.allowed_ips);
    e.selectors.forEach(function (s, i) { kv(dl, 'Selector ' + (i + 1), s.local.join(',') + ' → ' + s.remote.join(',') + (s.nat_local ? ' (NAT ' + s.nat_local + ')' : '')); });
    kv(dl, 'Remote endpoint', [e.remote.host, e.remote.port].filter(Boolean).join(':')); kv(dl, 'Remote gateway', e.remote.gateway); kv(dl, 'Peer key', e.remote.public_key);
    if (e.runtime) { kv(dl, 'Agent status', e.runtime.status); kv(dl, 'Last handshake', e.runtime.latest_handshake); kv(dl, 'Received / sent', fmtBytes(e.runtime.rx_bytes) + ' / ' + fmtBytes(e.runtime.tx_bytes)); }
    if (e.details) Object.keys(e.details).forEach(function (k) { var v = e.details[k]; if (typeof v !== 'object') kv(dl, k.replace(/_/g, ' '), v); });
    kv(dl, 'Telemetry age', fmtAge(st.telemetry_age_s)); kv(dl, 'Data source', e.source.replace(/_/g, ' '));
    d.appendChild(dl); return d;
  }
  function detailTunnel(t) {
    var p = $('vpn-detail'); p.textContent = '';
    p.appendChild(el('h5', { class: 'mb-1' }, t.name));
    var bs = el('div', { class: 'mb-2' }); bs.appendChild(stateBadge(t.state)); bs.appendChild(relBadge(t.relationship));
    bs.appendChild(badge(KIND_LABEL[t.kind] || t.kind, 'vpn-badge text-bg-secondary')); bs.appendChild(badge(t.protocol, 'vpn-badge text-bg-secondary'));
    bs.appendChild(badge('confidence ' + t.confidence, 'vpn-badge text-bg-secondary')); p.appendChild(bs);
    if (t.partial) p.appendChild(el('div', { class: 'alert alert-secondary py-1 small' }, 'Only one end of this tunnel is managed or observed. The remote side\'s state is not known and is not inferred.'));
    p.appendChild(endBlock('This end', t.a, t.a.state));
    if (t.b) p.appendChild(endBlock('Other end', t.b, t.b.state));
    if (t.evidence.length) { p.appendChild(el('div', { class: 'fw-semibold' }, 'How this was determined')); var ul = el('ul', { class: 'small' }); t.evidence.forEach(function (x) { ul.appendChild(el('li', null, x)); }); p.appendChild(ul); }
    var col = []; Object.keys(t.collected).forEach(function (k) { var c = t.collected[k]; col.push(k.toUpperCase() + ': configuration ' + (c.config_time || 'unavailable') + ', telemetry ' + (c.telemetry_at || 'none')); });
    p.appendChild(el('div', { class: 'small text-muted mb-2' }, 'Collected — ' + col.join(' | ')));
    if (t.findings.length) {
      p.appendChild(el('div', { class: 'fw-semibold' }, 'Findings'));
      t.findings.forEach(function (id) { var f = data.findings.filter(function (x) { return x.id === id; })[0]; if (f) { var a = el('a', { href: '#f-' + id, class: 'd-block small' }, '[' + f.severity + '] ' + f.title); a.addEventListener('click', function () { showTab('findings'); }); p.appendChild(a); } });
    }
    var links = el('div', { class: 'd-flex flex-wrap gap-1 my-2' });
    t.firewall_ids.forEach(function (fid, i) {
      var name = (i === 0 ? t.a : t.b || {}).firewall_name || ('#' + fid);
      links.appendChild(el('a', { class: 'btn btn-sm btn-outline-secondary', href: t.links.health[fid] }, name + ' health'));
      links.appendChild(el('a', { class: 'btn btn-sm btn-outline-secondary', href: t.links.details[fid] }, name + ' details'));
    });
    if (CFG.canDiagnose) links.appendChild(el('a', { class: 'btn btn-sm btn-primary', href: t.links.diagnostics }, 'Run diagnostics'));
    p.appendChild(links);
    if (t.incidents.length) { p.appendChild(el('div', { class: 'fw-semibold' }, 'Open incidents')); t.incidents.forEach(function (i) { p.appendChild(el('a', { href: i.url, class: 'd-block small' }, '[' + i.severity + '] ' + i.title)); }); }
    if (CFG.canManage) p.appendChild(manageBlock(t));
  }
  function manageBlock(t) {
    var box = el('div', { class: 'border-top pt-2 mt-2' });
    box.appendChild(el('div', { class: 'fw-semibold small' }, 'Operator correction'));
    box.appendChild(el('div', { class: 'small text-muted mb-1' }, 'Changes how OPNManager interprets this tunnel. The tunnel itself is never modified.'));
    var msg = el('div', { class: 'small' });
    function post(body) { api('/api/vpn_pairing.php', body).then(function (r) { msg.textContent = r.success ? 'Saved.' : (r.message || 'Failed'); if (r.success) load(); }); }
    if (t.b) {
      var b = el('button', { class: 'btn btn-sm btn-outline-warning me-1', type: 'button' }, 'These are not the same tunnel (un-pair)');
      b.addEventListener('click', function () { post({ action: 'unpair', a: t.a.key, b: t.b.key }); }); box.appendChild(b);
      if (t.relationship === 'manual') { var c = el('button', { class: 'btn btn-sm btn-outline-secondary', type: 'button' }, 'Remove manual pairing'); c.addEventListener('click', function () { post({ action: 'clear_pair', a: t.a.key, b: t.b.key }); }); box.appendChild(c); }
    } else {
      var sel = el('select', { class: 'form-select form-select-sm mb-1' }); sel.appendChild(el('option', { value: '' }, 'Pair with another firewall\'s endpoint…'));
      data.tunnels.forEach(function (o) { if (o.b === null && o.a.protocol === t.a.protocol && o.a.firewall_id !== t.a.firewall_id) sel.appendChild(el('option', { value: o.a.key }, o.a.firewall_name + ' / ' + o.a.name)); });
      var pb = el('button', { class: 'btn btn-sm btn-outline-primary mb-1', type: 'button' }, 'Pair');
      pb.addEventListener('click', function () { if (sel.value) post({ action: 'pair', a: t.a.key, b: sel.value, note: 'Paired from the topology page' }); });
      box.appendChild(sel); box.appendChild(pb);
    }
    var cl = el('select', { class: 'form-select form-select-sm mt-1' }); cl.appendChild(el('option', { value: '' }, 'Classify this endpoint…'));
    [['site_to_site', 'Site-to-site'], ['remote_access', 'Remote-access user'], ['external', 'External / unmanaged peer'], ['unknown', 'Unknown']].forEach(function (o) { cl.appendChild(el('option', { value: o[0] }, o[1])); });
    cl.addEventListener('change', function () { if (cl.value) post({ action: 'classify', a: t.a.key, classification: cl.value }); });
    box.appendChild(cl); box.appendChild(msg); return box;
  }
  function detailGroup(ids) {
    var p = $('vpn-detail'); p.textContent = '';
    p.appendChild(el('h5', null, ids.length + ' remote-access peers'));
    p.appendChild(el('div', { class: 'small text-muted mb-2' }, 'Remote-access peers are expected to be quiet; idle is normal for them.'));
    ids.forEach(function (id) { var t = data.tunnels.filter(function (x) { return x.id === id; })[0]; if (!t) return; var a = el('a', { href: '#', class: 'd-block' }); a.appendChild(stateBadge(t.state)); a.appendChild(document.createTextNode(t.a.name)); a.addEventListener('click', function (ev) { ev.preventDefault(); select(id); }); p.appendChild(a); });
  }
  function detailFirewall(n, ts) {
    var p = $('vpn-detail'); p.textContent = '';
    p.appendChild(el('h5', null, n.label));
    p.appendChild(el('div', { class: 'small text-muted mb-2' }, [n.platform_label, n.customer, n.site].filter(Boolean).join(' · ')));
    var links = el('div', { class: 'd-flex gap-1 mb-2' });
    links.appendChild(el('a', { class: 'btn btn-sm btn-outline-secondary', href: '/firewall_health.php?firewall=' + n.firewall_id }, 'Health'));
    links.appendChild(el('a', { class: 'btn btn-sm btn-outline-secondary', href: '/firewall_details.php?id=' + n.firewall_id }, 'Details'));
    if (CFG.canDiagnose) links.appendChild(el('a', { class: 'btn btn-sm btn-primary', href: '/vpn_diagnostics.php?firewall=' + n.firewall_id }, 'Diagnostics'));
    p.appendChild(links);
    ts.forEach(function (t) { var a = el('a', { href: '#', class: 'd-block small' }); a.appendChild(stateBadge(t.state)); a.appendChild(document.createTextNode(t.name)); a.addEventListener('click', function (ev) { ev.preventDefault(); select(t.id); }); p.appendChild(a); });
  }

  // ------------------------------------------------------------- plumbing
  function showTab(name) {
    prefs.view = name === 'table' ? 'table' : (name === 'map' ? 'map' : prefs.view);
    ['map', 'table', 'findings'].forEach(function (n) { $('tab-' + n).classList.toggle('d-none', n !== name); var b = $('btn-' + n); b.classList.toggle('active', n === name); });
    if (name !== 'findings') savePrefsSoon();
  }
  function render() {
    var list = visibleTunnels();
    renderSummary(list); renderMap(list); renderTable(list); renderFindings();
  }
  function filtersChanged() { prefs.filters = readFilters(); savePrefsSoon(); render(); }
  function fillSelect(id, entries) { var s = $(id); while (s.options.length > 1) s.remove(1); entries.forEach(function (e) { s.appendChild(el('option', { value: e[0] }, e[1])); }); }
  function load() {
    $('vpn-loading').classList.remove('d-none');
    return api('/api/vpn_topology.php').then(function (d) {
      $('vpn-loading').classList.add('d-none');
      if (!d.success) { $('vpn-error').textContent = d.message || 'Could not load the topology.'; return; }
      data = d;
      fillSelect('f-customer', Object.keys(d.customers).map(function (k) { return [k, d.customers[k]]; }));
      fillSelect('f-site', Object.keys(d.sites).map(function (k) { return [k, d.sites[k]]; }));
      var plats = {}; d.devices.forEach(function (x) { plats[x.platform] = 1; });
      fillSelect('f-platform', Object.keys(plats).map(function (k) { return [k, k]; }));
      applyFilterUI(); render();
      if (selected) select(selected);
    });
  }
  function init() {
    pos = {};
    api('/api/vpn_prefs.php').then(function (r) {
      if (r.success && r.prefs) { prefs = r.prefs; pos = {}; Object.keys(prefs.positions).forEach(function (k) { pos[k] = prefs.positions[k]; }); }
      return load();
    }).then(function () { showTab(prefs.view === 'table' ? 'table' : 'map'); });
    FILTERS.forEach(function (k) { $('f-' + k).addEventListener('change', filtersChanged); });
    var t; $('f-q').addEventListener('input', function () { clearTimeout(t); t = setTimeout(filtersChanged, 200); });
    ['map', 'table', 'findings'].forEach(function (n) { $('btn-' + n).addEventListener('click', function () { showTab(n); }); });
    $('btn-reset-layout').addEventListener('click', function () { prefs.positions = {}; pos = {}; savePrefsSoon(); render(); });
    $('btn-refresh').addEventListener('click', load);
    document.querySelectorAll('[data-sort]').forEach(function (th) { th.addEventListener('click', function () { var k = th.getAttribute('data-sort'); sortDir = sortKey === k ? -sortDir : 1; sortKey = k; renderTable(visibleTunnels()); }); });
  }
  document.addEventListener('DOMContentLoaded', init);
})();
