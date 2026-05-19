FROM php:8.2-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install mysqli mbstring \
    && apt-get purge -y --auto-remove libonig-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY . /app

RUN mkdir -p /app/logs /app/uploads \
    && chmod 775 /app/logs /app/uploads

CMD ["sh", "-lc", "php -d display_errors=0 -S 0.0.0.0:${PORT:-8000}"]
