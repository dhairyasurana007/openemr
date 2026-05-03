FROM nginx:1.27-alpine

RUN apk add --no-cache openssl \
    && openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout /etc/ssl/private/nginx-selfsigned.key \
        -out /etc/ssl/certs/nginx-selfsigned.crt \
        -subj "/CN=localhost/O=Clinical Co-Pilot Dev"

COPY default.conf /etc/nginx/conf.d/default.conf
