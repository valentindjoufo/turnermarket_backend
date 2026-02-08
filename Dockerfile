# ===============================
# Image PHP + Apache officielle
# ===============================
FROM php:8.2-apache

# ==========================================
# Installation des dépendances système requises
# pour PostgreSQL (OBLIGATOIRE)
# ==========================================
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# ==========================================
# Activation des modules Apache nécessaires
# ==========================================
RUN a2enmod rewrite headers

# ==========================================
# Configuration Apache (API backend)
# ==========================================
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# ==========================================
# Copie du projet dans le conteneur
# ==========================================
COPY . /var/www/html/

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
