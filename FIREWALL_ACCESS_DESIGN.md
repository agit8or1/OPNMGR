# OPNsense Firewall Remote Access Design

## Architecture Decision: FORWARD TUNNEL (Not Reverse)

### Why NOT Reverse Tunnel
- Complicated to maintain
- Requires tunnel user setup
- Port management issues
- Agent bugs with subshells

### Current Design: Forward Tunnel via SSH

**Flow:**
1. **Agent enables SSH access on firewall**
   - SSH service already running (`/usr/local/etc/rc.d/openssh start`)
   - Agent adds firewall rule to allow manager IP: `184.175.206.229`
   - Rule: `pass in quick on igc0 inet proto tcp from 184.175.206.229 to any port 22`

2. **Manager connects TO firewall**
   - From manager: `ssh -L 8443:localhost:443 root@73.35.46.112`
   - This creates LOCAL port forward: manager's localhost:8443 → firewall's localhost:443
   - No reverse tunnel needed!

3. **Access web GUI**
   - Open browser on manager: `https://localhost:8443`
   - Connects to firewall's web GUI through SSH tunnel

### Implementation Status

**Working:**
- ✅ SSH service running on firewall (confirmed with `sockstat -4 -l | grep :22`)
- ✅ Agent v3.4.3 executing commands successfully
- ✅ Firewall rule exists in `opnsense` anchor
- ✅ Rule on correct interface (igc0, not igb0)
- ✅ Rule allows manager IP (184.175.206.229)

**Broken:**
- ❌ Anchor `opnsense` is NOT invoked in main pfctl ruleset
- ❌ Rules in anchor are never evaluated
- ❌ SSH connection from manager times out
- ❌ Other blocking rules run first (no anchor invocation to short-circuit)

### The Problem

OPNsense's pfctl has an anchor named `opnsense` but it's not referenced in the main ruleset:

```bash
# Anchor exists
$ pfctl -sA
  opnsense

# Rule is in anchor
$ pfctl -sr -a opnsense
pass in quick on igc0 inet proto tcp from 184.175.206.229 to any port = ssh flags S/SA keep state

# But anchor is NEVER invoked in main rules
$ pfctl -sr | grep anchor
(no output)
```

Without `anchor "opnsense"` in the main ruleset, the rules inside the anchor are never evaluated.

### Solutions Attempted

1. ❌ `pfctl -a opnsense -f -` - Adds rule but anchor not invoked
2. ❌ `/usr/local/etc/rc.filter_configure` - Reloads config but still no anchor invocation
3. ❌ `configctl firewall filter reload` - Action not allowed
4. ❌ Adding rule to main ruleset directly - Syntax errors (wrong rule order)

### Blocking Rules in Main Ruleset

These rules block before our anchor can pass:

```
Line 4: block drop in log on ! igc0 inet from 73.35.46.0/24 to any
Line 5: block drop in log inet from 73.35.46.112 to any  ← Blocks FROM firewall IP
Line 9: block drop in log inet all label "02f4bab031b57d1e30553ce08e0ec131"
```

Even with `quick` in our pass rule, it doesn't matter because the anchor isn't invoked.

### Next Steps to Try

1. **Edit OPNsense config.xml directly**
   ```bash
   vim /conf/config.xml
   # Add firewall rule in <filter> section
   /usr/local/etc/rc.filter_configure
   ```

2. **Use OPNsense's web interface** (Catch-22: need access to enable access)

3. **Add rule through OPNsense's internal API**
   ```bash
   # Find the right command
   ls -la /usr/local/opnsense/scripts/firewall/
   ```

4. **Modify the main pfctl config file**
   ```bash
   # Find where pfctl loads rules from
   pfctl -vv
   # Edit that file to include anchor invocation
   ```

5. **Alternative: Use agent to run SSH port forward FROM firewall**
   - Have agent run: `ssh -R 8443:localhost:443 manager@opn.agit8or.net`
   - But this is a reverse tunnel (we wanted to avoid)

### Command Reference

**Check SSH access:**
```bash
# On firewall
sockstat -4 -l | grep :22
service openssh status

# From manager
timeout 5 ssh -o ConnectTimeout=3 root@73.35.46.112 "echo test"
```

**Check firewall rules:**
```bash
# List all anchors
pfctl -sA

# Check rules in anchor
pfctl -sr -a opnsense

# Check if anchor invoked
pfctl -sr | grep anchor

# View blocking rules
pfctl -sr | head -20
```

**Add firewall rule (current method - doesn't work):**
```bash
# Clear and add rule to anchor
pfctl -a opnsense -F rules
echo "pass in quick on igc0 inet proto tcp from 184.175.206.229 to any port 22" | pfctl -a opnsense -f -

# Reload firewall config
/usr/local/etc/rc.filter_configure
```

### Why This Design is Better

1. **Simpler**: Standard SSH port forwarding
2. **More secure**: Only allows manager IP, not public
3. **No special users**: Uses root SSH (already configured)
4. **Standard tools**: No custom tunnel management
5. **Easy to test**: Just `ssh -L` command

### When It Works

Once the firewall rule is properly applied (anchor invoked or rule in main ruleset):

```bash
# From manager
ssh -L 8443:localhost:443 root@73.35.46.112

# Then in browser
https://localhost:8443
```

Done!

---

**Last Updated:** October 14, 2025  
**Status:** Blocked on pfctl anchor invocation issue
