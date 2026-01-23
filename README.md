# ionCube Decoder Tool

A general-purpose tool for decrypting ionCube-protected PHP files that use secondary encryption layers (common in commercial plugins and malware).

## Overview

Many ionCube-protected files use a dual-layer encryption:
1. **Layer 1**: ionCube encryption (handled by ionCube Loader)
2. **Layer 2**: Custom encryption using functions like `d__c()`, `decrypt()`, etc.

This tool automatically:
- Loads the ionCube file using the ionCube Loader
- Captures global variables set during execution
- Identifies decryption functions, keys, and encrypted payloads
- Attempts decryption using discovered components
- Falls back to common encoding patterns (base64, gzip, etc.)

## Requirements

- Docker (recommended) OR
- PHP 7.x with ionCube Loader installed

## Quick Start with Docker

```bash
# Build the Docker image
docker build -t ioncube-decoder .

# Decode a file
docker run --rm -v "$(pwd)/input:/input" -v "$(pwd)/output:/output" \
    ioncube-decoder php /decoder/decoder.php /input/encrypted.php /output/decoded.php
```

## Quick Start with Docker Compose

```bash
# Place encrypted file in ./input directory
cp /path/to/encrypted.php ./input/

# Run decoder
docker-compose run decoder php /decoder/decoder.php /input/encrypted.php /output/decoded.php

# Check output
cat ./output/decoded.php
```

## Direct Usage (requires ionCube Loader)

```bash
php decoder.php <target_file> [output_file]
```

### Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| target_file | Yes | Path to ionCube-encrypted PHP file |
| output_file | No | Path for decoded output (default: decoded_<filename>) |

### Examples

```bash
# Basic usage
php decoder.php encrypted.php

# Specify output file
php decoder.php encrypted.php clean_source.php

# Using absolute paths
php decoder.php /var/www/plugins/license.php /tmp/decoded.php
```

## How It Works

### Step 1: Load ionCube File
The tool includes the target file, allowing ionCube Loader to decrypt the first layer.

### Step 2: Capture Globals
After loading, it captures all new global variables that were set.

### Step 3: Find Decryption Components

**Decryption Functions** (searched in order):
- `d__c`, `decrypt`, `decode`, `dec`, `d`
- `deobfuscate`, `unpack_code`, `unpack`
- `uncompress`, `decompress`
- Any function with 2 parameters (data + key signature)

**Secret Keys** (variable names):
- `$secret_key`, `$key`, `$k`, `$pass`
- `$password`, `$secret`, `$encryption_key`
- Any base64-like string (8-64 chars)

**Encrypted Payloads** (variable names):
- `$s__t`, `$c__t`, `$f__t`, `$code`
- `$data`, `$payload`, `$source`, `$encoded`
- Any large string variable (>1000 bytes)

### Step 4: Attempt Decryption

1. Use discovered function with discovered key
2. Try common patterns:
   - `base64_decode()`
   - `gzuncompress(base64_decode())`
   - `gzinflate(base64_decode())`
   - `gzdecode(base64_decode())`
   - Double base64
   - ROT13 + base64
3. Brute-force all key/payload combinations

### Step 5: Validate & Save
Output is validated for PHP syntax patterns before saving.

## Output Files

| File | Description |
|------|-------------|
| `decoded_<filename>.php` | Decrypted source code |
| `captured_globals.json` | All captured global variables |
| `decoder.log` | Detailed execution log |

## Directory Structure

```
ion_decoder/
├── decoder.php          # Main decoder script
├── Dockerfile           # Docker build file
├── docker-compose.yml   # Docker Compose config
├── README.md            # This file
├── input/               # Place encrypted files here
└── output/              # Decoded files appear here
```

## Security Notes

- Run in Docker with `network_mode: none` for malware analysis
- The tool executes the target file - use isolation for untrusted code
- Decoded files may contain malicious code - analyze carefully
- C2 callbacks are blocked when network is disabled

## Troubleshooting

### "ionCube Loader not installed"
Install ionCube Loader or use the Docker environment.

### "No decryption function found"
The file may use a non-standard function name. Check `captured_globals.json` for clues.

### "All decryption attempts failed"
- Check if ionCube loaded the file successfully
- Review the log file for captured variables
- The encryption may use a unique algorithm

### File self-deletes or crashes
Some malware has anti-analysis features. Use Docker with network disabled.

## License

For security research and educational purposes only.
