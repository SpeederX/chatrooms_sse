FROM mcr.microsoft.com/playwright:v1.48.0-jammy

WORKDIR /e2e

COPY e2e/package.json ./
RUN npm install

COPY e2e/ ./
