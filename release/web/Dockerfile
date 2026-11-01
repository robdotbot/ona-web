# The prebuilt web UI (web/dist) served by nginx, which also proxies /api to
# the ONA container so the session cookie stays on one origin.
FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY dist /usr/share/nginx/html
