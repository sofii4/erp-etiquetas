# ============================================
# PHP-FPM 8.3 + Oracle Instant Client + OCI8
#
# Base Debian (não alpine): o Instant Client é compilado contra glibc.
# ============================================
FROM php:8.3-fpm

ENV ORACLE_HOME=/opt/oracle/instantclient \
    LD_LIBRARY_PATH=/opt/oracle/instantclient \
    # Cliente em UTF-8: o Oracle converte do charset do banco para este.
    # AMERICAN de propósito: com BRAZILIAN o OCI8 devolve NUMBER como "1,5" e o
    # PHP lê errado. Formatação para pt-BR fica na camada de apresentação.
    NLS_LANG=AMERICAN_AMERICA.AL32UTF8 \
    TZ=America/Sao_Paulo

# Debian 13 renomeou libaio1 → libaio1t64; o Instant Client procura libaio.so.1.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libaio1t64 unzip curl ca-certificates \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev \
    && ln -sf /usr/lib/x86_64-linux-gnu/libaio.so.1t64 /usr/lib/x86_64-linux-gnu/libaio.so.1 \
    && rm -rf /var/lib/apt/lists/*

# Instant Client Basic Lite + SDK (headers para compilar o OCI8)
RUN mkdir -p /opt/oracle \
    && cd /opt/oracle \
    && curl -fsSLo basic.zip https://download.oracle.com/otn_software/linux/instantclient/instantclient-basiclite-linuxx64.zip \
    && curl -fsSLo sdk.zip   https://download.oracle.com/otn_software/linux/instantclient/instantclient-sdk-linuxx64.zip \
    && unzip -q basic.zip && unzip -q -o sdk.zip \
    && rm basic.zip sdk.zip \
    && mv instantclient_* instantclient \
    && echo /opt/oracle/instantclient > /etc/ld.so.conf.d/oracle-instantclient.conf \
    && ldconfig

# OCI8 3.3.x é a linha para PHP 8.3 (3.4+ exige PHP 8.4)
RUN echo "instantclient,/opt/oracle/instantclient" | pecl install oci8-3.3.0 \
    && docker-php-ext-enable oci8 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd zip

# Composer dentro da imagem: assim o `composer install` enxerga oci8/gd de verdade.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

EXPOSE 9000
CMD ["php-fpm"]
