# ---- shared base ----
FROM node:22-alpine AS base
WORKDIR /app

# ---- dev: source bind-mounted, deps installed on first boot ----
FROM base AS dev
ENV NODE_ENV=development
ENV HOST=0.0.0.0
ENV PORT=3000
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
 && chmod +x /usr/local/bin/docker-entrypoint.sh
EXPOSE 3000
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["npm", "run", "dev"]

# ---- build: full install and Nitro build ----
FROM base AS build
ENV NODE_ENV=development
COPY package.json package-lock.json ./
# --legacy-peer-deps: nuxt@3.21.11 ships an optional peer (@nuxt/cli -> @nuxt/schema@^4.4.6)
# that conflicts with the pinned @nuxt/schema@3.21.11 nuxt itself requires. The lockfile was
# generated the same way (see package-lock.json / task report); plain `npm ci` rejects it.
RUN npm ci --legacy-peer-deps
COPY . .
RUN npm run build

# ---- prod: built output only ----
FROM node:22-alpine AS prod
WORKDIR /app
ENV NODE_ENV=production
ENV HOST=0.0.0.0
ENV PORT=3000
COPY --from=build /app/.output ./.output
EXPOSE 3000
CMD ["node", ".output/server/index.mjs"]
