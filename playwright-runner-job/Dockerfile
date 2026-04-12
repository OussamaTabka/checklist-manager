FROM mcr.microsoft.com/playwright:v1.59.1-noble

WORKDIR /app

COPY package.json ./
RUN npm install

COPY tsconfig.json ./
COPY src ./src
COPY examples ./examples
COPY README.md ./README.md

RUN npm run build && npm prune --omit=dev

ENV NODE_ENV=production
ENV RUN_JSON_PATH=/work/run.json
ENV RESULT_JSON_PATH=/work/result.json
ENV ARTIFACTS_DIR=/work/artifacts

CMD ["node", "dist/main.js"]
