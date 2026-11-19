FROM alpine:3.23
# Use the runner's trusted CA bundle, including any organisation CA. TLS stays enabled.
COPY trusted-ca.crt /etc/ssl/certs/ca-certificates.crt
RUN apk add --no-cache pulseaudio pulseaudio-utils \
    && addgroup -g 1000 validation \
    && adduser -D -u 1000 -G validation validation \
    && mkdir -p /validation/bin
USER 1000:1000
ENV PATH="/validation/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
WORKDIR /tmp
