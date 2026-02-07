# Utiliser une image PHP avec Apache
FROM php:8.2-apache

# Copier tout le projet dans le conteneur
COPY . /var/www/html/

# Exposer le port utilisé par Render
EXPOSE 10000
