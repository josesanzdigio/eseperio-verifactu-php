# syntax=docker/dockerfile:1
FROM php:8.1-cli

# Install system deps and PHP extensions required by the project
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       git \
       unzip \
       ca-certificates \
       libxml2-dev \
       libpng-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j$(nproc) soap gd \
    && php -m | grep -E "(soap|gd|dom|libxml|openssl)" || true

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

RUN git config --system --add safe.directory /app

# Default command runs PHPUnit; can be overridden by docker-compose
CMD ["php", "-v"]
