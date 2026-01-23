#!/bin/bash
# ionCube Decoder - Quick decode script for Linux/Mac

set -e

if [ -z "$1" ]; then
    echo "Usage: ./decode.sh <input_file> [output_file]"
    echo ""
    echo "Examples:"
    echo "  ./decode.sh encrypted.php"
    echo "  ./decode.sh /path/to/file.php decoded.php"
    exit 1
fi

INPUT_FILE="$1"
OUTPUT_FILE="${2:-decoded_$(basename "$INPUT_FILE")}"

# Check if Docker is available
if ! command -v docker &> /dev/null; then
    echo "Error: Docker is not installed"
    exit 1
fi

# Build image if not exists
if ! docker image inspect ioncube-decoder &> /dev/null; then
    echo "[*] Building Docker image..."
    docker build -t ioncube-decoder "$(dirname "$0")"
fi

# Get absolute paths
INPUT_DIR="$(cd "$(dirname "$INPUT_FILE")" && pwd)"
INPUT_NAME="$(basename "$INPUT_FILE")"
OUTPUT_DIR="$(cd "$(dirname "$OUTPUT_FILE")" 2>/dev/null && pwd || pwd)"
OUTPUT_NAME="$(basename "$OUTPUT_FILE")"

echo "[*] Decoding: $INPUT_FILE"
echo "[*] Output: $OUTPUT_DIR/$OUTPUT_NAME"

# Run decoder
docker run --rm \
    --network none \
    -v "$INPUT_DIR:/input:ro" \
    -v "$OUTPUT_DIR:/output" \
    ioncube-decoder \
    php /decoder/decoder.php "/input/$INPUT_NAME" "/output/$OUTPUT_NAME"

echo ""
echo "[+] Done! Check: $OUTPUT_DIR/$OUTPUT_NAME"
