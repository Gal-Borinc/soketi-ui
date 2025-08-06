FROM php:8.2-fpm as composer

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

ADD /composer.json /src/composer.json
ADD /composer.lock /src/composer.lock

RUN cd /src && composer install --no-dev --no-scripts

FROM node:18 as modules

ADD /package.json /src/package.json
ADD /package-lock.json* /src/
ADD /yarn.lock* /src/

RUN cd /src && npm install

FROM node:18 as build

ADD /package.json /src/package.json
ADD /package-lock.json* /src/
ADD /yarn.lock* /src/

COPY --from=modules /src/node_modules /src/node_modules

ADD /public /src/public
ADD /resources /src/resources
ADD /jsconfig.json /src/jsconfig.json
ADD /postcss.config.js /src/postcss.config.js
ADD /tailwind.config.js /src/tailwind.config.js
ADD /vite.config.mjs /src/vite.config.mjs

RUN cd /src && npm run build

FROM jkaninda/nginx-php-fpm:8.2

ADD /nginx.conf /var/www/html/conf/nginx/nginx-site.conf

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

ADD / /var/www/html
COPY --from=build /src/public /var/www/html/public
COPY --from=composer /src/vendor /var/www/html/vendor

RUN chown -R 1000:1000 /var/www/html

# Set working directory
WORKDIR /var/www/html

# Install cron
RUN apt-get update && apt-get install -y cron

# Add cron job for metrics scraping
RUN echo "* * * * * cd /var/www/html && php artisan soketi:scrape-metrics >/dev/null 2>&1" > /etc/cron.d/soketi-scraper
RUN echo "* * * * * cd /var/www/html && sleep 10 && php artisan soketi:scrape-metrics >/dev/null 2>&1" >> /etc/cron.d/soketi-scraper  
RUN echo "* * * * * cd /var/www/html && sleep 20 && php artisan soketi:scrape-metrics >/dev/null 2>&1" >> /etc/cron.d/soketi-scraper
RUN echo "* * * * * cd /var/www/html && sleep 30 && php artisan soketi:scrape-metrics >/dev/null 2>&1" >> /etc/cron.d/soketi-scraper
RUN echo "* * * * * cd /var/www/html && sleep 40 && php artisan soketi:scrape-metrics >/dev/null 2>&1" >> /etc/cron.d/soketi-scraper
RUN echo "* * * * * cd /var/www/html && sleep 50 && php artisan soketi:scrape-metrics >/dev/null 2>&1" >> /etc/cron.d/soketi-scraper

# Laravel scheduler for hourly aggregation
RUN echo "* * * * * cd /var/www/html && php artisan schedule:run >/dev/null 2>&1" >> /etc/cron.d/soketi-scraper

# Set permissions for cron
RUN chmod 0644 /etc/cron.d/soketi-scraper
RUN crontab /etc/cron.d/soketi-scraper

# Copy initialization script for manual execution
COPY docker-init.sh /usr/local/bin/docker-init.sh
RUN chmod +x /usr/local/bin/docker-init.sh
