FROM nginx:1.27-alpine

# Rendered to /etc/nginx/conf.d/default.conf on container start by the base
# image's built-in envsubst-on-templates entrypoint script, substituting
# ${APP_HTTPS_PORT} from this container's environment (docker-compose.yml).
COPY default.conf.template /etc/nginx/templates/default.conf.template
