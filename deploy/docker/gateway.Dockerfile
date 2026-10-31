FROM python:3.12-alpine AS config
COPY gateway /source
COPY deploy/scripts/prepare-gateway.py /prepare-gateway.py
RUN python /prepare-gateway.py /source /generated

FROM nginxinc/nginx-unprivileged:stable-alpine
COPY --from=config /generated/nginx.conf /etc/nginx/nginx.conf
COPY --from=config /generated/trusted-proxies.conf /etc/nginx/trusted-proxies.conf
COPY --from=config /generated/services /etc/nginx/services
COPY --from=config /generated/docs.html /usr/share/nginx/html/docs.html
USER 101:101
EXPOSE 8080
