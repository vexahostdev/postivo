FROM node:22-alpine
ENV NODE_ENV=production
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev && apk add --no-cache tini
COPY server.js docker-entrypoint.sh ./
RUN chmod +x docker-entrypoint.sh
# Berjalan sebagai root di dalam kontainer agar dapat membaca kunci TLS Caddy (0600) dan bind port 587/465;
# cap_drop ALL + NET_BIND_SERVICE diatur di docker-compose.
EXPOSE 587 465
ENTRYPOINT ["/sbin/tini", "--", "/app/docker-entrypoint.sh"]
