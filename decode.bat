@echo off
REM ionCube Decoder - Quick decode script for Windows

if "%~1"=="" (
    echo Usage: decode.bat ^<input_file^> [output_file]
    echo.
    echo Examples:
    echo   decode.bat encrypted.php
    echo   decode.bat C:\path\to\file.php decoded.php
    exit /b 1
)

set INPUT_FILE=%~1
set INPUT_DIR=%~dp1
set INPUT_NAME=%~nx1

if "%~2"=="" (
    set OUTPUT_NAME=decoded_%INPUT_NAME%
    set OUTPUT_DIR=%CD%
) else (
    set OUTPUT_NAME=%~nx2
    set OUTPUT_DIR=%~dp2
    if "%OUTPUT_DIR%"=="" set OUTPUT_DIR=%CD%
)

REM Check if Docker is available
docker --version >nul 2>&1
if errorlevel 1 (
    echo Error: Docker is not installed
    exit /b 1
)

REM Build image if not exists
docker image inspect ioncube-decoder >nul 2>&1
if errorlevel 1 (
    echo [*] Building Docker image...
    docker build -t ioncube-decoder "%~dp0"
)

echo [*] Decoding: %INPUT_FILE%
echo [*] Output: %OUTPUT_DIR%%OUTPUT_NAME%

REM Run decoder (network disabled for security)
docker run --rm --network none -v "%INPUT_DIR%:/input:ro" -v "%OUTPUT_DIR%:/output" ioncube-decoder php /decoder/decoder.php "/input/%INPUT_NAME%" "/output/%OUTPUT_NAME%"

echo.
echo [+] Done! Check: %OUTPUT_DIR%%OUTPUT_NAME%
