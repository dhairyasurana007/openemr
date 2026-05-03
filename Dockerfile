FROM openemr/openemr:latest

WORKDIR /var/www/localhost/htdocs/openemr

# Copy current repository code over the base OpenEMR image so
# Railway deploys this workspace state instead of the upstream image code.
COPY . .

# Production PHP dependencies (`vendor/` is listed in `.dockerignore`).
RUN set -eux; \
    if command -v apt-get >/dev/null 2>&1; then \
      export DEBIAN_FRONTEND=noninteractive; \
      apt-get update; \
      apt-get install -y --no-install-recommends ca-certificates curl git unzip; \
    elif command -v apk >/dev/null 2>&1; then \
      apk add --no-cache ca-certificates curl git unzip; \
    else \
      echo 'No apt-get or apk; install curl, git, unzip, and Composer prerequisites for this base image.' >&2; \
      exit 1; \
    fi; \
    curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer; \
    COMPOSER_ALLOW_SUPERUSER=1 composer install --ignore-platform-reqs --no-interaction --prefer-dist --no-scripts --no-dev; \
    rm -f /usr/local/bin/composer; \
    rm -rf /root/.composer; \
    if command -v apt-get >/dev/null 2>&1; then \
      apt-get purge -y git unzip; \
      apt-get autoremove -y; \
      rm -rf /var/lib/apt/lists/*; \
    elif command -v apk >/dev/null 2>&1; then \
      apk del git unzip; \
    fi

# ccdaservice/node_modules is omitted from the Docker build context (.dockerignore). Reinstall so
# flex `openemr.sh` permission passes and nested paths (e.g. oe-schematron-service) exist.
RUN if command -v npm >/dev/null 2>&1 && [ -f ccdaservice/package.json ]; then \
    cd ccdaservice && npm install --unsafe-perm --omit=dev && cd /var/www/localhost/htdocs/openemr; \
    fi

# OpenEMR installer expects this file to be writable during first-time setup.
RUN mkdir -p sites/default \
    && touch sites/default/sqlconf.php \
    && chmod 666 sites/default/sqlconf.php \
    && mkdir -p sites/default/documents/letter_templates \
    && mkdir -p sites/default/documents/edi \
    && mkdir -p sites/default/documents/logs_and_misc/methods \
    && mkdir -p sites/default/documents/era \
    && mkdir -p sites/default/documents/procedure_results \
    && mkdir -p sites/default/documents/couchdb \
    && mkdir -p sites/default/documents/custom_menus/patient_menus \
    && mkdir -p sites/default/documents/certificates \
    && mkdir -p sites/default/documents/onsite_portal_documents/templates \
    && mkdir -p sites/default/documents/temp \
    && chmod -R 777 sites/default/documents

# Render: sync sqlconf.php $host from MYSQL_HOST before openemr.sh (fixes stale openemr-mysql).
# Path must stay outside docker/ — that directory is listed in .dockerignore.
COPY contrib/render/render-openemr-bootstrap.sh /usr/local/bin/render-openemr-bootstrap.sh
RUN chmod 500 /usr/local/bin/render-openemr-bootstrap.sh

# openemr.sh does `chmod 600` on app files then `chown apache:apache` only when
# /var/www/localhost/htdocs/auto_configure.php exists (upstream flex layout). This image
# often lacks that file, so code stays root:root + 600 and httpd cannot read index.php.
RUN chown -R apache:apache /var/www/localhost/htdocs/openemr

# First boot: render-openemr-bootstrap.sh runs contrib/render/openemr-auto-install.php
# (same Installer as setup.php) when MYSQL_HOST is set and MANUAL_SETUP is not "yes".
# Set OPENEMR_SKIP_AUTO_INSTALL=1 to force the web installer instead.
CMD ["/usr/local/bin/render-openemr-bootstrap.sh"]

EXPOSE 80
