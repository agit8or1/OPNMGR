# Build environment for Anthropic's OSS Scanner (https://github.com/anthropics/oss-scanner).
#
# The scanner builds this with the repository as the build context, then runs the image with NO network. Everything
# the application and its test suites need is installed here. The image mirrors a production install: PHP 8.3 behind
# nginx + php-fpm with deploy/nginx-opnmgr-hardening.conf included, and MariaDB holding database/schema.sql plus every
# migration.
#
# Credentials below exist only inside this throwaway image. They are not used by, and do not match, any real
# installation.
#
# Inside the finished image:
#   opnmgr-start              start MariaDB, php-fpm and nginx (app on http://127.0.0.1:8080/)
#   opnmgr-test               start MariaDB and run the regression suites CI runs
#   login: scanner-admin / scanner-admin-password-0001   (role: admin)
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates curl git unzip openssh-client python3 shellcheck procps \
      php8.3-cli php8.3-fpm php8.3-mysql php8.3-xml php8.3-curl php8.3-mbstring php8.3-ldap \
      composer mariadb-server mariadb-client nginx \
    && rm -rf /var/lib/apt/lists/*

# scanner contract: the checkout lives inside the image, at /src
COPY . /src
WORKDIR /src

# Runtime dependencies (vendor/ is not tracked). Fetched now; there is no network later.
RUN composer install --no-dev --no-interaction --no-progress

# nginx vhost as deployed: document root is the checkout, hardening snippet included.
RUN cp deploy/nginx-opnmgr-hardening.conf /etc/nginx/snippets/opnmgr-hardening.conf \
    && rm -f /etc/nginx/sites-enabled/default \
    && printf '%s\n' \
      'server {' \
      '    listen 127.0.0.1:8080;' \
      '    root /src;' \
      '    index index.php;' \
      '    include /etc/nginx/snippets/opnmgr-hardening.conf;' \
      '    location / { try_files $uri $uri/ =404; }' \
      '    location ~ \.php$ {' \
      '        include snippets/fastcgi-php.conf;' \
      '        fastcgi_pass unix:/run/php/php8.3-fpm.sock;' \
      '    }' \
      '}' > /etc/nginx/sites-enabled/opnmgr \
    && nginx -t

RUN printf '%s\n' \
      '#!/bin/sh' \
      '# Start MariaDB (and, unless "db" is given, php-fpm and nginx). Needs no network.' \
      'set -e' \
      'service mariadb start >/dev/null' \
      'until mysqladmin ping --silent 2>/dev/null; do sleep 1; done' \
      '[ "$1" = db ] && exit 0' \
      'service php8.3-fpm start >/dev/null' \
      'service nginx start >/dev/null' \
      'echo "OPNManager on http://127.0.0.1:8080/  (scanner-admin / scanner-admin-password-0001)"' \
      > /usr/local/bin/opnmgr-start \
    && printf '%s\n' \
      '#!/bin/sh' \
      '# Run the suites CI runs (.github/workflows/ci.yml). Prints a summary; never aborts on a failure.' \
      'opnmgr-start db' \
      'cd /src' \
      'failed=""' \
      'for t in security msp health_drift alerting fleet ai_redaction backup_retention agent_command_retry \' \
      '         command_sweep reboot_state schema_columns twofactor auth_ordering referenced_files firewall_policy \' \
      '         server_identity agent_package scheduled_jobs notification_health undefined_functions settings_audit \' \
      '         account_status endpoint_authz smtp_settings mfa_requirement config_restore agent_signing \' \
      '         injection_guard telemetry_retention agent_request_signing; do' \
      '  php "tests/${t}_test.php" >"/tmp/test_${t}.log" 2>&1 || failed="$failed $t"' \
      'done' \
      'if [ -n "$failed" ]; then echo "FAILED suites:$failed (logs in /tmp/test_*.log)"; else echo "All suites passed."; fi' \
      > /usr/local/bin/opnmgr-test \
    && chmod 755 /usr/local/bin/opnmgr-start /usr/local/bin/opnmgr-test

# Configure the app, load the published schema, apply migrations, create an admin. MariaDB must be running for these
# steps, so they share one RUN; its data directory is kept in the image.
RUN set -eux; \
    printf '%s\n' \
      'DB_HOST=127.0.0.1' 'DB_NAME=opnsense_fw' 'DB_USER=opnmgr' 'DB_PASS=scanner-local-only' \
      'APP_ENV=testing' 'APP_DEBUG=false' 'APP_URL=http://127.0.0.1:8080' > .env; \
    php scripts/generate_master_key.php; \
    php scripts/sign_release.php --generate-key; \
    opnmgr-start db; \
    mysql < database/schema.sql; \
    mysql -e "CREATE USER 'opnmgr'@'127.0.0.1' IDENTIFIED BY 'scanner-local-only'; \
              CREATE USER 'opnmgr'@'localhost' IDENTIFIED BY 'scanner-local-only'; \
              GRANT ALL PRIVILEGES ON *.* TO 'opnmgr'@'127.0.0.1'; \
              GRANT ALL PRIVILEGES ON *.* TO 'opnmgr'@'localhost'; FLUSH PRIVILEGES;"; \
    php scripts/migrate.php; \
    php scripts/create_admin.php scanner-admin scanner-admin@example.invalid scanner-admin-password-0001; \
    chown -R www-data:www-data /src; \
    mysqladmin shutdown

# Run the suites so a broken image is visible in the build log, without failing the build on a test failure.
RUN opnmgr-test; mysqladmin shutdown || true
