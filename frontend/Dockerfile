FROM node:18-alpine AS builder
WORKDIR /app

# Generate runtime env for build
ARG NEXT_PUBLIC_API_URL
ARG NEXT_PUBLIC_FILE_API_URL

# Write .env.production.local dynamically
RUN echo "NEXT_PUBLIC_API_URL=${NEXT_PUBLIC_API_URL}" > .env.production.local \
  && echo "NEXT_PUBLIC_FILE_API_URL=${NEXT_PUBLIC_FILE_API_URL}" >> .env.production.local

COPY package.json package-lock.json* ./
RUN npm install --legacy-peer-deps
COPY . .
RUN npm run build

FROM node:18-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
COPY --from=builder /app/package.json ./package.json
COPY --from=builder /app/node_modules ./node_modules
COPY --from=builder /app/.next ./.next
# COPY --from=builder /app/public ./public
EXPOSE 3000
CMD ["npm", "start"]
