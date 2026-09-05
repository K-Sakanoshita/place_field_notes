FROM php:8.4-cli-bookworm
RUN apt-get update \
 && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev libxml2-dev libonig-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd mbstring simplexml \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /app
CMD ["php","-S","0.0.0.0:8080","/app/index.php"]
