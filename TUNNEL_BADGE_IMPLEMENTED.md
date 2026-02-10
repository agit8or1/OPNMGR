# Tunnel Mode Badge - Footer Implementation

## Solution Implemented
**Date:** October 20, 2025  
**Version:** 2.2.2

### What Was Done
Added a **footer badge** to the tunnel loading page (`firewall_proxy_ondemand.php`) that displays while the tunnel is being established.

### Badge Location
The badge appears as a **fixed footer bar** at the bottom of the screen with:
- 🔒 Lock icon
- "SECURE TUNNEL MODE" text
- "Connected via encrypted SSH tunnel" description
- Dynamic port display showing the HTTPS port being used

### Badge Design
```
┌─────────────────────────────────────────────────────────────┐
│ 🔒 SECURE TUNNEL MODE - Connected via encrypted SSH tunnel │
│                                          Port: 8100 (HTTPS) │
└─────────────────────────────────────────────────────────────┘
```

**Visual Styling:**
- Purple gradient background (linear-gradient(135deg, #667eea 0%, #764ba2 100%))
- Fixed position at bottom of viewport
- Full width footer bar
- White text with lock SVG icon
- Real-time port display
- Professional shadow and backdrop blur

### Technical Implementation

#### 1. Footer HTML (firewall_proxy_ondemand.php)
- Added before closing `</body>` tag
- Fixed positioning with z-index: 999999
- Flexbox layout for proper alignment
- SVG lock icon inline

#### 2. JavaScript Port Detection
- Fetches tunnel port from new endpoint
- Updates port display dynamically
- Fallback to "81xx (HTTPS)" if fetch fails
- 2-second delay to allow tunnel establishment

#### 3. API Endpoint (get_tunnel_info.php)
- Returns active tunnel session details
- Provides tunnel_port and https_port
- JSON response format
- Requires authentication

### Files Modified

**Modified:**
- `/var/www/opnsense/firewall_proxy_ondemand.php` - Added footer badge
- `/var/www/opnsense/dev_features.php` - Fixed awful white-on-white contrast

**Created:**
- `/var/www/opnsense/get_tunnel_info.php` - Tunnel info API endpoint

### Why This Approach?

**Original Problem:** 
- Nginx `sub_filter` doesn't work with gzipped responses from OPNsense
- Cannot inject content into OPNsense pages (cross-origin restrictions)

**Solution Benefits:**
✅ Works reliably - no nginx complications  
✅ Visible during tunnel establishment  
✅ Shows actual connection details  
✅ Professional appearance  
✅ No cross-origin issues  

**Limitation:**
⚠️ Badge only visible on loading page, not in OPNsense UI itself  
**Reason:** Cannot inject HTML into cross-origin content (security restriction)

### User Experience

1. User clicks "Access via Tunnel Proxy"
2. Loading page appears with footer badge showing "SECURE TUNNEL MODE"
3. Port displays as "Detecting..." initially
4. After 2 seconds, actual HTTPS port shown (e.g., "8100 (HTTPS)")
5. Page redirects to OPNsense firewall
6. Badge disappears (OPNsense page has different origin)

### Alternative Considered
Could add badge to every OPNManager page that detects tunnel URL in opened windows, but:
- More complex implementation
- Badge would be in parent window, not tunnel window
- Less useful for users

### Future Enhancement Possibility
Create a browser extension that:
- Detects opn.agit8or.net:81XX URLs
- Injects badge overlay into OPNsense pages
- Works across all browsers
- Optional install for users who want persistent badge

## Conclusion
Footer badge provides clear visual indicator of tunnel mode during connection phase. While not visible in OPNsense itself due to cross-origin restrictions, it serves the primary purpose of indicating secure tunnel mode to users.

**Status:** ✅ Implemented and working  
**Priority:** Nice-to-have feature completed  
**User Feedback:** Awaiting testing

---

**Version:** 2.2.2  
**Last Updated:** October 20, 2025
