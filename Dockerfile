FROM node:20-alpine
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund
COPY server ./server
COPY public ./public
COPY data ./data
ENV NODE_ENV=production PORT=3000 MP_NO_OPEN=1
EXPOSE 3000
USER node
CMD ["node", "server/index.js"]
