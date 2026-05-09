# Keep base image pinned for reproducible/faster deploys.
# Update this ARG value intentionally when you want a newer OpenEMR base.
ARG OPENEMR_BASE_IMAGE=openemr/openemr:latest@sha256:1d8073d3acfc15b53e771f264b84c0be41a0f0c998f413af39811e1c2e5d8061

FROM composer:2 AS composer-bin

FROM ${OPENEMR_BASE_IMAGE}

WORKDIR /var/www/localhost/htdocs/openemr

# Reuse Composer from a pinned upstream image instead of downloading it each build.
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer

# Cache-friendly dependency layering:
# 1) copy lockfiles/manifests
# 2) install deps
# 3) copy app source
COPY composer.json composer.lock ./
COPY ccdaservice/package.json ccdaservice/package-lock.json ./ccdaservice/

# Production PHP dependencies (`vendor/` is listed in `.dockerignore`).
RUN set -eux; \
    if command -v apt-get >/dev/null 2>&1; then \
      export DEBIAN_FRONTEND=noninteractive; \
      apt-get update; \
      apt-get install -y --no-install-recommends ca-certificates git unzip; \
    elif command -v apk >/dev/null 2>&1; then \
      apk add --no-cache ca-certificates git unzip; \
    else \
      echo 'No apt-get or apk; install git, unzip, and Composer prerequisites for this base image.' >&2; \
      exit 1; \
    fi; \
    COMPOSER_ALLOW_SUPERUSER=1 composer install --ignore-platform-reqs --no-interaction --prefer-dist --no-scripts --no-dev; \
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

# Copy current repository code over the base OpenEMR image after dependency layers
# so regular source changes do not invalidate dependency cache.
COPY . .

# OpenEMR installer expects this file to be writable during first-time setup.
RUN mkdir -p sites/default \
    && printf '<?php\n// Placeholder — openemr-auto-install.php writes the real config at runtime.\n$config = 0;\n' > sites/default/sqlconf.php \
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
