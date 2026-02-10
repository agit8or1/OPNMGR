# OPNManager v2.1.0 - Setup & Deployment Checklist

## ✅ Completed Implementation

### Version Management
- [x] Centralized version constants in `inc/version.php`
- [x] All pages updated to pull versions from constants
  - [x] `dev_features.php` - now displays 2.1.0
  - [x] `about.php` - uses getVersionInfo()
  - [x] `health_monitor.php` - pulls from database
  - [x] `changelog.php` - database-driven
- [x] Removed hard-coded version strings
- [x] Deleted `version_management.php`
- [x] Updated navigation to remove version mgmt link

### License Management System
- [x] Created `inc/license_utils.php` with functions:
  - generateLicenseKey() → LIC-XXXX-XXXX-XXXX-XXXX format
  - generateAPICredentials() → secure API credentials
  - isValidLicenseKey() → format validation
  - checkLicenseStatus() → license status check
  - recordLicenseCheckIn() → audit logging
  - logLicenseActivity() → activity tracking
  - getLicenseStats() → dashboard statistics
  - exportLicenseKey() → customer export
  - initializeLicenseTables() → one-time setup

- [x] Created database schema with 5 tables:
  - license_tiers (subscription plans)
  - deployed_instances (customer licenses)
  - license_checkins (audit trail)
  - license_activity_log (activity tracking)
  - license_api_keys (API authentication)

- [x] Created `license_init.php` - Setup page
  - Initializes license tables one-time
  - Creates default subscription tiers
  - Admin access only

- [x] Enhanced `license_server.php`
  - Graceful table missing handling
  - Proper license key generation
  - API credential creation
  - Activity logging on all operations
  - Check-in history viewer
  - Statistics dashboard

- [x] Updated navigation
  - Added "License Setup" link
  - "License Server" now functional

### Documentation Created
- [x] `docs/LICENSE_MANAGEMENT.md` (2,000+ lines)
  - Architecture overview
  - Database schema details
  - Setup instructions
  - API integration guide
  - Administration tasks
  - Troubleshooting guide
  - Security considerations
  - Future enhancements

- [x] `docs/VERSION_2.1.0_GUIDE.md` (quick start)
  - What's changed summary
  - Getting started steps
  - Architecture diagram
  - Integration instructions

- [x] `docs/IMPLEMENTATION_SUMMARY_V2.1.0.md` (completion summary)
  - Tasks completed
  - Technical specifications
  - File structure
  - Testing checklist
  - Deployment steps

### Code Quality
- [x] All PHP files syntax verified
- [x] No errors or warnings
- [x] Proper error handling implemented
- [x] PDO prepared statements for security
- [x] Database queries optimized with indexes

## 🚀 Initial Setup (First Time)

### Step 1: Navigate to License Setup
```
URL: https://opn.agit8or.net/license_init.php
```

### Step 2: Initialize License Tables
1. Click "Initialize License Tables" button
2. Wait for confirmation message
3. Verify success

### Step 3: Verify Version System
```
URL: https://opn.agit8or.net/about.php
```
Check that version shows: **2.1.0**

### Step 4: Create Test License
```
URL: https://opn.agit8or.net/license_server.php
```
1. Fill in "Create Instance" form:
   - Instance Name: "Test Company"
   - License Tier: "Professional"
   - Status: "Active (365 days)"
2. Click "Create Instance"
3. Record the generated:
   - License Key
   - API Key

## 📋 Standard Operations

### Creating Customer License
1. Go to License Server (`/license_server.php`)
2. Fill form:
   - Instance Name: Customer name
   - License Tier: Select plan
   - Status: Trial or Active
3. Click "Create Instance"
4. Copy generated credentials
5. Share with customer

### Extending Expiring License
1. Find instance in list
2. Click "Extend" button
3. Enter number of days
4. Click "Extend License"

### Suspending License
1. Click "Edit" on instance
2. Change Status: "Suspended"
3. Click "Update Instance"

### Viewing Activity
1. Scroll to "Recent Activity" section
2. View all create/extend/suspend operations
3. Check "Recent Check-ins" for verification history

## 🔍 Monitoring Dashboard

### Key Statistics
- Total Instances
- Active Instances
- Trial Instances
- Expired Instances
- Total Firewall Capacity
- Total Firewalls Used

### What to Watch For
- Licenses expiring soon (within 7 days)
- Expired licenses
- Inactive instances (no check-in > 7 days)
- Over-capacity usage

## 🛠️ Troubleshooting

### License tables don't exist
**Error:** "License system not initialized"
**Fix:** Navigate to `/license_init.php` and click "Initialize License Tables"

### License key generation fails
**Check:**
- MySQL service running
- Database credentials in `config.php`
- User has CREATE TABLE permissions

### Version still shows old number
**Fix:**
1. Hard refresh browser (Ctrl+Shift+R)
2. Clear cache
3. Verify `inc/version.php` has correct APP_VERSION constant

### Check-in failures
**Debug:**
1. Verify license key format (LIC-XXXX-XXXX-XXXX-XXXX)
2. Check instance status in admin panel
3. Verify API key not deleted
4. Check server time synchronization

## 📚 Documentation Reference

| Document | Purpose | Location |
|----------|---------|----------|
| LICENSE_MANAGEMENT.md | Technical reference | `/var/www/opnsense/docs/` |
| VERSION_2.1.0_GUIDE.md | Quick start guide | `/var/www/opnsense/docs/` |
| IMPLEMENTATION_SUMMARY | Completion summary | `/var/www/opnsense/docs/` |
| About Page | System info | `/about.php` |
| Changelog | Version history | `/changelog.php` |
| Health Monitor | System status | `/health_monitor.php` |

## 🔐 Security Checklist

- [x] License keys use cryptographically secure randomness
- [x] API credentials generated securely
- [x] All operations logged with audit trail
- [x] Database access restricted to authenticated users
- [x] Prepared statements prevent SQL injection
- [x] Proper error handling (no info leakage)
- [ ] Rate limiting on check-in endpoint (future)
- [ ] IP whitelisting option (future)
- [ ] API secret hashing (future)

## 📈 Subscription Tiers

| Tier | Firewalls | Users | API Keys | Price |
|------|-----------|-------|----------|-------|
| Trial | 5 | 3 | 2 | FREE (30 days) |
| Professional | 20 | 10 | 5 | $99.99/month |
| Enterprise | 100 | 50 | 20 | $499.99/month |
| Ultimate | 500 | 200 | 100 | $1,999.99/month |

All tiers auto-created during initialization.

## 🎯 Next Steps (v2.2.0+)

- [ ] License key revocation system
- [ ] Tier downgrade with prorated refunds
- [ ] Payment processor integration (Stripe/PayPal)
- [ ] Automatic renewal
- [ ] White-label licensing
- [ ] License transfer
- [ ] Usage-based billing
- [ ] Reseller programs

## ✨ Features Enabled

### Version System
- [x] Single source of truth
- [x] Consistent versioning across all pages
- [x] Automatic version propagation

### License Management
- [x] Subscription tier management
- [x] Instance creation with automatic key generation
- [x] API authentication credentials
- [x] License expiration tracking
- [x] Firewall usage monitoring
- [x] Check-in audit trail
- [x] Activity logging
- [x] Statistics dashboard

## 🎓 Training Resources

### For Admins
1. Read: `docs/LICENSE_MANAGEMENT.md` (Full reference)
2. Setup: Initialize license tables via `/license_init.php`
3. Test: Create test instance and verify

### For Customers
1. Receive license key from admin
2. Configure in OPNManager agent
3. Perform license check-in
4. Verify status: active/trial/suspended/expired

## 📞 Support

### Reporting Issues
1. Check `docs/LICENSE_MANAGEMENT.md` troubleshooting section
2. Review application logs in `/var/log/`
3. Check database for corrupted entries
4. Contact development team if needed

### Getting Help
- License docs: `/var/www/opnsense/docs/LICENSE_MANAGEMENT.md`
- Quick start: `/var/www/opnsense/docs/VERSION_2.1.0_GUIDE.md`
- System info: Visit `/about.php`

---

**Version**: 2.1.0  
**Status**: ✅ Production Ready  
**Last Updated**: October 28, 2025  
**Deployed**: Yes
