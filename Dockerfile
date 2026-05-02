FROM openemr/openemr:latest

WORKDIR /var/www/localhost/htdocs/openemr

# Copy current repository code over the base OpenEMR image so
# Railway deploys this workspace state instead of the upstream image code.
COPY . .

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

# First boot: render-openemr-bootstrap.sh runs contrib/render/openemr-auto-install.php
# (same Installer as setup.php) when MYSQL_HOST is set and MANUAL_SETUP is not "yes".
# Set OPENEMR_SKIP_AUTO_INSTALL=1 to force the web installer instead.
CMD ["/usr/local/bin/render-openemr-bootstrap.sh"]

EXPOSE 80
