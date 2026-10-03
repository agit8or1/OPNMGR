# Security review — 2026-10-02

## Changes

- add_user.php and edit_user.php require user.manage before reading or writing account-management data. A logged-in technician/read-only user cannot promote themselves or create an administrator.
- Sessions re-read both active status and role once per request. Disabling/deleting/demoting an account takes effect on the next request. A database failure denies the request without using stale privileges.
- Refresh the browser-tool lockfile and override basic-ftp to the patched compatible 6.2.x line. Dependency categories remain unchanged.

## Validation

PHP 8.3 syntax checks passed. Run the offline account tests:

```sh
for scenario in demoted active disabled missing outage; do
    php tests/account_authorization_test.php "$scenario" || exit 1
done
```

All five scenarios passed. Puppeteer/basic-ftp imports passed with install scripts disabled. The tests mock database rows; they do not exercise a deployed MySQL database or real browser login.

## Rollout and residual dependencies

Deploy the PHP changes and reload PHP-FPM according to your usual procedure. No schema migration is needed. Session checks add one short account query per request.

Puppeteer's extract-zip 2.0.1 still matches GHSA-7pqw-9j4j-h8q3 and GHSA-jmr9-qjv8-65gv; OSV lists no fixed release. It is used by the optional browser capture/download tooling, not by the PHP request handlers. Do not feed it untrusted archives; run capture tooling under an unprivileged isolated account. Do not treat the remaining advisory as resolved by this PR.
