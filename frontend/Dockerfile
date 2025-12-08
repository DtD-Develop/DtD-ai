FROM node:20-alpine AS builder
WORKDIR /app

# รับค่าจาก docker-compose เป็น build args
ARG NEXT_PUBLIC_API_URL
ARG NEXT_PUBLIC_API_KEY

# map ชื่อให้ตรงกับที่โค้ดใช้
ENV NEXT_PUBLIC_API_BASE_URL=${NEXT_PUBLIC_API_URL}
ENV NEXT_PUBLIC_API_KEY=${NEXT_PUBLIC_API_KEY}

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build

FROM node:20-alpine AS runner
WORKDIR /app

COPY --from=builder /app/.next ./.next
COPY --from=builder /app/public ./public
COPY package*.json ./
RUN npm ci --omit=dev

EXPOSE 3000
CMD ["npm", "start"]
