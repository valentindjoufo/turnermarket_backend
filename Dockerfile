# ===============================
# Image PHP + Apache officielle
# ===============================
FROM php:8.2-apache

# ==========================================
# Installation des dépendances système requises
# pour PostgreSQL et Composer
# ==========================================
RUN apt-get update && apt-get install -y \
    libpq-dev \
    git \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# ==========================================
# Installation de Composer
# ==========================================
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ==========================================
# Activation des modules Apache nécessaires
# ==========================================
RUN a2enmod rewrite headers

# ==========================================
# Configuration Apache (API backend)
# ==========================================
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# ==========================================
# Définir le répertoire de travail
# ==========================================
WORKDIR /var/www/html

# ==========================================
# Copier d'abord les fichiers de configuration Composer
# (optimisation pour le cache Docker)
# ==========================================
COPY composer.json composer.lock* ./

# ==========================================
# Installer les dépendances PHP avec Composer
# ==========================================
RUN composer install --no-dev --optimize-autoloader

# ==========================================
# Copier tout le reste du projet
# ==========================================
COPY . .

# ==========================================
# Permissions correctes pour Apache
# ==========================================
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# ==========================================
# Port réel utilisé par Apache sur Render
# ==========================================
EXPOSE 80

# ==========================================
# Lancement d’Apache (obligatoire)
# ==========================================
CMD ["apache2-foreground"]