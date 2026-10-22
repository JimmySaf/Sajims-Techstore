
# Stage 1: Build React and Vite frontend assets
FROM node:22-bookworm-slim AS frontend-build

WORKDIR /app

# Copy dependency files first for better Docker caching
COPY package.json package-lock.json ./
COPY .npmrc ./
COPY pnpm-workspace.yaml ./

RUN npm ci

# Copy frontend source files and Vite configuration
COPY resources ./resources
COPY public ./public
COPY vite.config.ts tsconfig.json ./

# Build the frontend
RUN npm run build

# Verify the Vite manifest exists
RUN test -f public/build/manifest.json

# Stage 2: Install and run Laravel
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

# Copy Laravel application
COPY . .

# Copy the compiled frontend into Laravel's public directory
COPY --from=frontend-build /app/public/build ./public/build

# Install production PHP dependencies
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

# Prepare Laravel writable directories
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
