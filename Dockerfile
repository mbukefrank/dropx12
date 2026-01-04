# Use PHP CLI (no Apache)
FROM php:8.4-cli

# Install PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Copy app
COPY . /app

# Set working directory
WORKDIR /app

# Start PHP built-in server
CMD php -S 0.0.0.0:$PORT -t .
