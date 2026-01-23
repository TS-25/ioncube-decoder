# ionCube Decoder Docker Environment
# Provides PHP 7.4 with ionCube Loader for decryption

FROM php:7.4-cli

LABEL maintainer="MrOplus"
LABEL description="ionCube Decoder Environment"

# Install dependencies
RUN apt-get update && apt-get install -y \
    wget \
    curl \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install ionCube Loader
RUN cd /tmp && \
    wget -q https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz && \
    tar xzf ioncube_loaders_lin_x86-64.tar.gz && \
    cp ioncube/ioncube_loader_lin_7.4.so $(php -r "echo ini_get('extension_dir');")/ && \
    echo "zend_extension=ioncube_loader_lin_7.4.so" > /usr/local/etc/php/conf.d/00-ioncube.ini && \
    rm -rf /tmp/ioncube*

# Install uopz extension for function hooking
RUN pecl install uopz-6.1.2 && \
    echo "extension=uopz.so" > /usr/local/etc/php/conf.d/uopz.ini && \
    echo "uopz.exit=1" >> /usr/local/etc/php/conf.d/uopz.ini

# Install runkit7 for function manipulation
RUN pecl install runkit7-4.0.0a3 && \
    echo "extension=runkit7.so" > /usr/local/etc/php/conf.d/runkit7.ini && \
    echo "runkit.internal_override=1" >> /usr/local/etc/php/conf.d/runkit7.ini

# Create working directories
RUN mkdir -p /decoder /input /output

# Copy decoder scripts
COPY decoder.php /decoder/decoder.php
COPY decoder_hook.php /decoder/decoder_hook.php
COPY decoder_eval_hook.php /decoder/decoder_eval_hook.php
COPY decoder_multi.php /decoder/decoder_multi.php

# Set working directory
WORKDIR /decoder

# Verify ionCube is loaded
RUN php -v | grep -i ioncube

# Default command shows help
CMD ["php", "/decoder/decoder.php"]
