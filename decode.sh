#!/bin/bash
# ionCube Decoder - Universal decoder script for Linux/Mac
# Supports all PHP versions with automatic version detection

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Default values
PHP_VERSION=""
DOCKER_IMAGE="ioncube-decoder"
FORCE_REBUILD=false
VERBOSE=false
DECODER_SCRIPT="decoder.php"
INPUT_FILE=""
OUTPUT_FILE=""

# Help message
show_help() {
    cat << EOF
${BLUE}ionCube Decoder - Universal Decoder${NC}

Usage: ./decode.sh [OPTIONS] <input_file> [output_file]

Options:
    -p, --php VERSION    Specify PHP version (5.6, 7.0-7.4, 8.0-8.5)
                         Auto-detects from file if not specified
    -i, --image NAME     Docker image name (default: ioncube-decoder)
    -d, --decoder FILE   Decoder script to use (decoder.php, decoder_multi.php)
    -f, --force-rebuild  Force rebuild Docker image
    -v, --verbose        Verbose output
    -h, --help           Show this help message

Examples:
    ./decode.sh encrypted.php
    ./decode.sh -p 8.2 encrypted.php decoded.php
    ./decode.sh -p 7.4 -d decoder_multi.php encrypted.php
    ./decode.sh --php 8.1 --decoder decoder_multi.php file.php output.php

EOF
}

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -p|--php)
            PHP_VERSION="$2"
            shift 2
            ;;
        -i|--image)
            DOCKER_IMAGE="$2"
            shift 2
            ;;
        -d|--decoder)
            DECODER_SCRIPT="$2"
            shift 2
            ;;
        -f|--force-rebuild)
            FORCE_REBUILD=true
            shift
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        -h|--help)
            show_help
            exit 0
            ;;
        -*)
            echo -e "${RED}Error: Unknown option $1${NC}"
            show_help
            exit 1
            ;;
        *)
            if [ -z "$INPUT_FILE" ]; then
                INPUT_FILE="$1"
            elif [ -z "$OUTPUT_FILE" ]; then
                OUTPUT_FILE="$1"
            else
                echo -e "${RED}Error: Too many arguments${NC}"
                show_help
                exit 1
            fi
            shift
            ;;
    esac
done

# Validate input
if [ -z "$INPUT_FILE" ]; then
    echo -e "${RED}Error: Input file is required${NC}"
    show_help
    exit 1
fi

if [ ! -f "$INPUT_FILE" ]; then
    echo -e "${RED}Error: Input file not found: $INPUT_FILE${NC}"
    exit 1
fi

# Set default output file
if [ -z "$OUTPUT_FILE" ]; then
    OUTPUT_FILE="decoded_$(basename "$INPUT_FILE")"
fi

# Auto-detect PHP version from file
detect_php_version() {
    local file="$1"
    local version=""
    
    # Try to detect from file content using various methods
    if command -v file &> /dev/null; then
        # Check for PHP version in file metadata
        local file_info=$(file "$file")
        if [[ "$file_info" =~ PHP[[:space:]]+([0-9]+\.[0-9]+) ]]; then
            version="${BASH_REMATCH[1]}"
        fi
    fi
    
    # Try to detect from ionCube signature
    if [ -z "$version" ]; then
        # Look for ionCube loader version in file
        local ioncube_info=$(strings "$file" 2>/dev/null | grep -i "ioncube" | head -1)
        if [[ "$ioncube_info" =~ ([0-9]+\.[0-9]+) ]]; then
            version="${BASH_REMATCH[1]}"
        fi
    fi
    
    # Default to PHP 7.4 if detection fails
    if [ -z "$version" ]; then
        version="7.4"
        echo -e "${YELLOW}Warning: Could not detect PHP version, using default 7.4${NC}" >&2
    fi
    
    echo "$version"
}

# Check if Docker is available
if ! command -v docker &> /dev/null; then
    echo -e "${RED}Error: Docker is not installed${NC}"
    echo "Please install Docker from: https://docs.docker.com/get-docker/"
    exit 1
fi

# Detect PHP version if not specified
if [ -z "$PHP_VERSION" ]; then
    PHP_VERSION=$(detect_php_version "$INPUT_FILE")
    echo -e "${BLUE}[*] Auto-detected PHP version: $PHP_VERSION${NC}"
fi

# Validate PHP version
if [[ ! "$PHP_VERSION" =~ ^(5\.6|7\.[0-4]|8\.[0-5])$ ]]; then
    echo -e "${RED}Error: Unsupported PHP version: $PHP_VERSION${NC}"
    echo "Supported versions: 5.6, 7.0-7.4, 8.0-8.5"
    exit 1
fi

# Build image if needed
IMAGE_TAG="${DOCKER_IMAGE}:php${PHP_VERSION}"
if ! docker image inspect "$IMAGE_TAG" &> /dev/null || [ "$FORCE_REBUILD" = true ]; then
    echo -e "${BLUE}[*] Building Docker image for PHP $PHP_VERSION...${NC}"
    if [ "$VERBOSE" = true ]; then
        docker build --build-arg PHP_VERSION="$PHP_VERSION" -t "$IMAGE_TAG" "$(dirname "$0")"
    else
        docker build --build-arg PHP_VERSION="$PHP_VERSION" -t "$IMAGE_TAG" "$(dirname "$0")" > /dev/null 2>&1
    fi
    if [ $? -ne 0 ]; then
        echo -e "${RED}Error: Failed to build Docker image${NC}"
        exit 1
    fi
    echo -e "${GREEN}[+] Image built successfully${NC}"
fi

# Get absolute paths
INPUT_DIR="$(cd "$(dirname "$INPUT_FILE")" && pwd)"
INPUT_NAME="$(basename "$INPUT_FILE")"

# Create output directory if it doesn't exist
OUTPUT_DIR="$(dirname "$OUTPUT_FILE")"
if [ ! -d "$OUTPUT_DIR" ] && [ "$OUTPUT_DIR" != "." ]; then
    mkdir -p "$OUTPUT_DIR"
fi
OUTPUT_DIR="$(cd "$OUTPUT_DIR" 2>/dev/null && pwd || pwd)"
OUTPUT_NAME="$(basename "$OUTPUT_FILE")"

echo -e "${BLUE}[*] Decoding: $INPUT_FILE${NC}"
echo -e "${BLUE}[*] PHP Version: $PHP_VERSION${NC}"
echo -e "${BLUE}[*] Decoder: $DECODER_SCRIPT${NC}"
echo -e "${BLUE}[*] Output: $OUTPUT_DIR/$OUTPUT_NAME${NC}"

# Run decoder with appropriate flags
DOCKER_RUN_CMD="docker run --rm --network none"

if [ "$VERBOSE" = true ]; then
    DOCKER_RUN_CMD="$DOCKER_RUN_CMD -e DECODER_VERBOSE=1"
fi

# Check if decoder_script exists in container
DECODER_PATH="/decoder/$DECODER_SCRIPT"
if ! docker run --rm "$IMAGE_TAG" test -f "$DECODER_PATH" 2>/dev/null; then
    echo -e "${YELLOW}Warning: $DECODER_SCRIPT not found, using decoder.php${NC}"
    DECODER_PATH="/decoder/decoder.php"
fi

# Run the decoder
$DOCKER_RUN_CMD \
    -v "$INPUT_DIR:/input:ro" \
    -v "$OUTPUT_DIR:/output" \
    "$IMAGE_TAG" \
    php "$DECODER_PATH" "/input/$INPUT_NAME" "/output/$OUTPUT_NAME"

if [ $? -eq 0 ]; then
    echo ""
    echo -e "${GREEN}[+] Success! Decoded file: $OUTPUT_DIR/$OUTPUT_NAME${NC}"
    
    # Show file size comparison
    if [ -f "$INPUT_FILE" ] && [ -f "$OUTPUT_DIR/$OUTPUT_NAME" ]; then
        INPUT_SIZE=$(du -h "$INPUT_FILE" | cut -f1)
        OUTPUT_SIZE=$(du -h "$OUTPUT_DIR/$OUTPUT_NAME" | cut -f1)
        echo -e "${BLUE}[i] Input size: $INPUT_SIZE, Output size: $OUTPUT_SIZE${NC}"
    fi
else
    echo -e "${RED}Error: Decoding failed${NC}"
    exit 1
fi
