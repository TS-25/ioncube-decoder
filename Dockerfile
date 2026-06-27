# ionCube Decoder Docker Environment
# Supports all PHP versions with matching ionCube Loaders

FROM php:cli

LABEL maintainer="MrOplus"
LABEL description="ionCube Decoder Environment - Multi-version Support"

# Install dependencies and build tools
RUN apt-get update && apt-get install -y \
    wget \
    curl \
    unzip \
    git \
    gnupg \
    lsb-release \
    autoconf \
    g++ \
    make \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions and tools for all versions
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libssl-dev \
    libcurl4-openssl-dev \
    libxml2-dev \
    && docker-php-ext-install zip curl xml \
    && rm -rf /var/lib/apt/lists/*

# Install ionCube Loader dynamically based on PHP version
RUN set -eux; \
    PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;"); \
    IONCUBE_VERSION=""; \
    case "$PHP_VERSION" in \
        5.6) IONCUBE_VERSION="5.6" ;; \
        7.0) IONCUBE_VERSION="7.0" ;; \
        7.1) IONCUBE_VERSION="7.1" ;; \
        7.2) IONCUBE_VERSION="7.2" ;; \
        7.3) IONCUBE_VERSION="7.3" ;; \
        7.4) IONCUBE_VERSION="7.4" ;; \
        8.0) IONCUBE_VERSION="8.0" ;; \
        8.1) IONCUBE_VERSION="8.1" ;; \
        8.2) IONCUBE_VERSION="8.2" ;; \
        8.3) IONCUBE_VERSION="8.3" ;; \
        8.4) IONCUBE_VERSION="8.4" ;; \
        8.5) IONCUBE_VERSION="8.5" ;; \
        *) echo "Unsupported PHP version: $PHP_VERSION"; exit 1 ;; \
    esac; \
    cd /tmp && \
    wget -q https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz && \
    tar xzf ioncube_loaders_lin_x86-64.tar.gz && \
    EXTENSION_DIR=$(php -r "echo ini_get('extension_dir');") && \
    IONCUBE_FILE="ioncube_loader_lin_${IONCUBE_VERSION}.so"; \
    if [ -f "ioncube/${IONCUBE_FILE}" ]; then \
        cp "ioncube/${IONCUBE_FILE}" "${EXTENSION_DIR}/"; \
        echo "zend_extension=${IONCUBE_FILE}" > /usr/local/etc/php/conf.d/00-ioncube.ini; \
    else \
        echo "Warning: ionCube loader for PHP ${PHP_VERSION} not found"; \
        echo "Available loaders:"; \
        ls -la ioncube/; \
    fi && \
    rm -rf /tmp/ioncube*

# Install uopz extension with fallback methods
RUN set -eux; \
    PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;"); \
    UOPZ_INSTALLED=false; \
    \
    # Try different installation methods based on PHP version
    case "$PHP_VERSION" in \
        5.6|7.0|7.1|7.2|7.3|7.4) \
            # Try specific versions for older PHP
            for VERSION in 6.1.2 6.1.1 6.1.0 6.0.2 6.0.1; do \
                if pecl install uopz-${VERSION} 2>/dev/null; then \
                    UOPZ_INSTALLED=true; \
                    break; \
                fi; \
            done; \
            ;; \
        8.0|8.1) \
            for VERSION in 7.0.0 6.1.2 6.1.1; do \
                if pecl install uopz-${VERSION} 2>/dev/null; then \
                    UOPZ_INSTALLED=true; \
                    break; \
                fi; \
            done; \
            ;; \
        8.2|8.3|8.4|8.5) \
            for VERSION in 7.1.0 7.0.0; do \
                if pecl install uopz-${VERSION} 2>/dev/null; then \
                    UOPZ_INSTALLED=true; \
                    break; \
                fi; \
            done; \
            ;; \
        *) \
            # Try latest stable
            if pecl install uopz 2>/dev/null; then \
                UOPZ_INSTALLED=true; \
            fi; \
            ;; \
    esac; \
    \
    # Build from source if PECL fails
    if [ "$UOPZ_INSTALLED" = false ]; then \
        echo "Building uopz from source..."; \
        cd /tmp && \
        git clone --depth 1 https://github.com/krakjoe/uopz.git || \
        git clone --depth 1 https://github.com/php-pecl/uopz.git || \
        wget -q https://github.com/krakjoe/uopz/archive/refs/heads/master.zip -O uopz.zip && \
        unzip -q uopz.zip && \
        cd uopz* && \
        phpize && \
        ./configure && \
        make -j$(nproc) && \
        make install && \
        UOPZ_INSTALLED=true; \
    fi; \
    \
    if [ "$UOPZ_INSTALLED" = true ]; then \
        echo "extension=uopz.so" > /usr/local/etc/php/conf.d/uopz.ini; \
        echo "uopz.exit=1" >> /usr/local/etc/php/conf.d/uopz.ini; \
        echo "uopz.overrides=1" >> /usr/local/etc/php/conf.d/uopz.ini; \
    else \
        echo "Warning: uopz installation failed for PHP ${PHP_VERSION}"; \
    fi

# Install runkit7 extension with fallback methods
RUN set -eux; \
    PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;"); \
    RUNKIT_INSTALLED=false; \
    \
    # Try different installation methods based on PHP version
    case "$PHP_VERSION" in \
        5.6|7.0|7.1|7.2|7.3) \
            # Try specific versions for older PHP
            for VERSION in 3.0.1 3.0.0 2.1.0; do \
                if pecl install runkit7-${VERSION} 2>/dev/null; then \
                    RUNKIT_INSTALLED=true; \
                    break; \
                fi; \
            done; \
            ;; \
        7.4) \
            for VERSION in 4.0.0a4 4.0.0a3 4.0.0a2; do \
                if pecl install runkit7-${VERSION} 2>/dev/null; then \
                    RUNKIT_INSTALLED=true; \
                    break; \
                fi; \
            done; \
            ;; \
        8.0|8.1|8.2|8.3|8.4|8.5) \
            # Build from source for PHP 8+
            RUNKIT_INSTALLED=false; \
            ;; \
        *) \
            RUNKIT_INSTALLED=false; \
            ;; \
    esac; \
    \
    # Build from source if PECL fails
    if [ "$RUNKIT_INSTALLED" = false ]; then \
        echo "Building runkit7 from source..."; \
        cd /tmp && \
        if [ "$PHP_VERSION" = "7.4" ]; then \
            git clone --depth 1 --branch v4.0.0a4 https://github.com/runkit7/runkit7.git || \
            git clone --depth 1 https://github.com/runkit7/runkit7.git; \
        else \
            git clone --depth 1 https://github.com/runkit7/runkit7.git; \
        fi && \
        cd runkit7 && \
        phpize && \
        ./configure && \
        make -j$(nproc) && \
        make install && \
        RUNKIT_INSTALLED=true; \
    fi; \
    \
    if [ "$RUNKIT_INSTALLED" = true ]; then \
        echo "extension=runkit7.so" > /usr/local/etc/php/conf.d/runkit7.ini; \
        echo "runkit.internal_override=1" >> /usr/local/etc/php/conf.d/runkit7.ini; \
    else \
        echo "Warning: runkit7 installation failed for PHP ${PHP_VERSION}"; \
    fi

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
RUN set -eux; \
    php -v | grep -i ioncube || echo "Warning: ionCube not loaded"

# Default command shows help
CMD ["php", "/decoder/decoder.php"]
