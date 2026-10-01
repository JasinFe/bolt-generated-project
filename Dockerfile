FROM node:20-alpine
WORKDIR /app
COPY package.json ./
COPY server ./server
COPY public ./public
COPY data ./data
ENV NODE_ENV=production PORT=3000
EXPOSE 3000
USER node
CMD ["node", "server/index.js"]
