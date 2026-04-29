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

EXPOSE 80
