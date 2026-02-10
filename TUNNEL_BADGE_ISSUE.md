# Tunnel Mode Badge Issue

## Problem
The "Tunnel Mode" badge does not appear when accessing firewalls through the tunnel proxy.

## Why It Doesn't Work

### Technical Root Cause
The OPNsense firewall sends **gzip-compressed** HTTP responses. Nginx's `sub_filter` directive cannot modify compressed content, so the badge HTML never gets injected into the page.

### Attempted Solutions (All Failed)

1. **`proxy_set_header Accept-Encoding "";`** 
   - Result: Firewall ignored the header and still sent gzipped responses
   
2. **`gunzip on;` directive**
   - Result: Nginx decompressed responses but sub_filter still didn't inject content
   - Possibly because response was re-compressed after sub_filter processing
   
3. **JavaScript injection via sub_filter**
   - Result: Same issue - sub_filter doesn't work with compressed responses
   
4. **PHP proxy wrapper (tunnel_with_badge.php)**
   - Result: HTTP 500 errors, broke tunnel connections completely
   - Had to revert to direct HTTPS approach

## Current Status
✅ Tunnels work perfectly via direct HTTPS (https://opn.agit8or.net:81XX)  
❌ Badge feature **DEFERRED** to future version

## Future Solutions

### Option 1: Client-Side JavaScript (Recommended)
Inject JavaScript into OPNManager pages that:
1. Detects if current page is a tunnel session (check URL pattern)
2. Uses DOM manipulation to inject badge HTML
3. Stores badge state in localStorage

**Implementation:**
```javascript
// In firewall_proxy_ondemand.php or tunnel window
if (window.location.hostname === 'opn.agit8or.net' && /^81\d{2}$/.test(window.location.port)) {
    const badge = document.createElement('div');
    badge.innerHTML = '🔒 Tunnel Mode';
    badge.style.cssText = 'position:fixed;top:10px;right:10px;background:linear-gradient(...);';
    document.body.appendChild(badge);
}
```

### Option 2: Browser Extension
Create a simple browser extension that:
- Detects tunnel URLs (opn.agit8or.net:81XX)
- Injects badge overlay
- Works across all browsers

### Option 3: Server-Side Decompression & Recompression
1. Configure nginx to fully decompress backend responses
2. Apply sub_filter modifications
3. Re-compress before sending to client
4. Requires more complex nginx configuration

### Option 4: OPNsense Theme Modification
Modify the OPNsense theme files to include badge detection:
- Less practical - requires changes to firewall itself
- Would break on theme updates

## Recommendation
**Use Option 1 (Client-Side JavaScript)** - Simplest, most reliable, no nginx complexity.

Implement in `firewall_proxy_ondemand.php` when opening tunnel window:
- Add `<script>` tag that runs after page load
- Check if we're in a tunnel context
- Inject badge if true

## Impact Assessment
**Priority:** LOW - Nice-to-have visual indicator  
**Workaround:** Users know they're in tunnel mode from URL (port 81XX)  
**Risk:** None - tunnels work perfectly without badge  
**Effort:** Medium (2-4 hours for client-side implementation)

## Version History
- **v2.2.0** - Initial badge implementation via sub_filter (didn't work)
- **v2.2.1** - Attempted fixes with gunzip (didn't work)
- **v2.2.2** - Feature deferred, documented issue, reverted to working state

---

**Status:** Deferred to future version  
**Last Updated:** October 20, 2025
