FROM dunglas/frankenphp:1-php8.3

# Extensions PHP pour Symfony et les appels HTTP.
RUN install-php-extensions \
    intl \
    mbstring \
    zip \
    opcache \
    curl \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite

# Installation de Composer.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV APP_ENV=prod
ENV APP_DEBUG=0

# Copie du projet, sans les fichiers exclus par .dockerignore.
COPY . /app

# Installation des dépendances PHP.
# Les scripts Symfony sont lancés au démarrage,
# lorsque les variables d'environnement sont disponibles.
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-scripts \
    --optimize-autoloader

# Dossiers de fonctionnement de Symfony.
RUN mkdir -p /app/var/cache /app/var/log

# L'IA sur CPU peut mettre du temps à répondre.
RUN printf '%s\n' \
    'max_execution_time=180' \
    > /usr/local/etc/php/conf.d/app.ini

# Configuration du serveur web intégrée à l'image.
# Aucun fichier Caddyfile séparé n'est nécessaire.
RUN printf '%s\n' \
    '{' \
    '    frankenphp' \
    '    auto_https off' \
    '}' \
    '' \
    ':80 {' \
    '    root * /app/public' \
    '    encode zstd gzip' \
    '    php_server' \
    '}' \
    > /etc/frankenphp/Caddyfile

EXPOSE 80

CMD ["sh", "-c", "php bin/console cache:clear --env=prod --no-debug && exec frankenphp run --config /etc/frankenphp/Caddyfile"]