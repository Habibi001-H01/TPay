FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    curl \
    git \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install mysqli pdo pdo_mysql gd \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite
RUN sed -i 's/80/7860/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

WORKDIR /var/www/html
COPY . /var/www/html/

# Install package.json di root atau subfolder mana pun yang ada
RUN find /var/www/html -maxdepth 2 -name "package.json" -execdir npm install \;

RUN mkdir -p /var/www/html/assets/uploads && chmod -R 777 /var/www/html

EXPOSE 7860

RUN chmod +x /var/www/html/start.sh
CMD ["/var/www/html/start.sh"]