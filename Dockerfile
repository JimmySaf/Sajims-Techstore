
# Stage 1: Build the React frontend
FROM node:22-bookworm-slim AS frontend-build

WORKDIR /app

# Copy package manager files
COPY package.json package-lock.json ./
COPY pnpm-workspace.yaml ./
COPY .npmrc ./

# Allow package installation scripts during the Docker build.
# This overrides ignore-scripts=true from the project's .npmrc.
RUN npm ci --ignore-scripts=false

# Copy application source and build configuration
COPY resources ./resources
COPY public ./public
COPY vite.config.ts tsconfig.json ./

# Build React and generate the Vite manifest
RUN npm run build

# Fail the image build if the manifest is missing
RUN test -f public/build/manifest.json

# Stage 2: Run Laravel
FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libonig-dev \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        bcmath \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

# Copy the compiled frontend into the Laravel application
COPY --from=frontend-build /app/public/build ./public/build

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production
ENV APP_DEBUG=false

EXPOSE 10000

CMD ["sh", "-c", "php artisan config:clear && php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"]
