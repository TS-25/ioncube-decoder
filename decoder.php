<?php
/**
 * ============================================
 * ionCube Decoder Tool
 * ============================================
 *
 * A general-purpose tool for decrypting ionCube-protected PHP files
 * that use secondary encryption layers (common in commercial plugins).
 *
 * Requirements:
 * - PHP 7.x with ionCube Loader installed
 * - The target file must be loadable by ionCube
 *
 * Usage:
 *   php decoder.php <target_file> [output_file]
 *
 * How it works:
 * 1. Loads the ionCube file (ionCube Loader decrypts first layer)
 * 2. Captures all global variables set by the file
 * 3. Looks for common decryption functions (d__c, decrypt, decode, etc.)
 * 4. Looks for encrypted payload variables ($s__t, $code, $data, etc.)
 * 5. Attempts decryption using discovered function and key
 * 6. Outputs the decrypted source code
 *
 * @author Security Research Tool
 * @version 1.1
 */

error_reporting(0);
ini_set('display_errors', 0);

class IonCubeDecoder {

    private $targetFile;
    private $outputFile;
    private $logFile;
    private $capturedGlobals = [];
    private $decryptionFunction = null;
    private $secretKey = null;
    private $encryptedPayload = null;
    private $decodedSource = null;

    // Common variable names used for encrypted payloads
    private $payloadVarNames = [
        's__t', 'c__t', 'f__t', 'code', 'data', 'payload', 'source',
        'encoded', 'encrypted', 'content', 'str', 'src', 'eval_code',
        '_code', '_data', '_str', 'x', 'y', 'z', 'a', 'b', 'c'
    ];

    // Common variable names used for encryption keys
    private $keyVarNames = [
        'secret_key', 'key', 'k', 'pass', 'password', 'secret',
        'encryption_key', 'decrypt_key', 'enc_key', 'dec_key',
        '_key', '_k', 'salt', 'iv'
    ];

    // Common function names used for decryption
    private $decryptFuncNames = [
        'd__c', 'decrypt', 'decode', 'dec', 'd', 'deobfuscate',
        'unpack_code', 'unpack', 'uncompress', 'decompress',
        '_d', '_dec', '_decrypt', 'x', 'y', 'z'
    ];

    public function __construct($targetFile, $outputFile = null) {
        $this->targetFile = $targetFile;
        $this->outputFile = $outputFile ?: dirname($targetFile) . '/decoded_' . basename($targetFile);
        $this->logFile = dirname($targetFile) . '/decoder.log';
    }

    private function log($message) {
        $timestamp = date('H:i:s');
        $logLine = "[$timestamp] $message\n";
        file_put_contents($this->logFile, $logLine, FILE_APPEND);
        echo $message . "\n";
    }

    public function decode() {
        $this->log("=== ionCube Decoder Started ===");
        $this->log("Target: " . $this->targetFile);

        // Check if file exists
        if (!file_exists($this->targetFile)) {
            $this->log("ERROR: Target file not found!");
            return false;
        }

        // Check if ionCube Loader is available
        if (!extension_loaded('ionCube Loader')) {
            $this->log("ERROR: ionCube Loader not installed!");
            $this->log("Install ionCube Loader for your PHP version first.");
            return false;
        }

        $this->log("[+] ionCube Loader detected");

        // Verify it's an ionCube file
        $content = file_get_contents($this->targetFile);
        if (strpos($content, 'ionCube') === false) {
            $this->log("WARNING: File may not be ionCube encrypted");
        }

        // Capture globals before loading
        $globalsBefore = array_keys($GLOBALS);

        // Set up minimal environment
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SESSION = [];
        @session_start();

        $this->log("[*] Loading ionCube protected file...");

        // Load the file
        ob_start();
        try {
            include $this->targetFile;
            $this->log("[+] File loaded successfully");
        } catch (Throwable $e) {
            $this->log("[!] Error loading file: " . $e->getMessage());
        }
        ob_end_clean();

        // Capture new globals
        $globalsAfter = array_keys($GLOBALS);
        $newGlobals = array_diff($globalsAfter, $globalsBefore);

        $this->log("[*] Found " . count($newGlobals) . " new global variables");

        // Store captured globals (new ones)
        foreach ($newGlobals as $varName) {
            $this->capturedGlobals[$varName] = $GLOBALS[$varName];
        }

        // ALSO check ALL globals for known variable names (they might not appear as "new")
        $this->log("[*] Checking ALL globals for known patterns...");
        $knownVars = array_merge($this->payloadVarNames, $this->keyVarNames);
        foreach ($knownVars as $varName) {
            if (isset($GLOBALS[$varName]) && !isset($this->capturedGlobals[$varName])) {
                $this->capturedGlobals[$varName] = $GLOBALS[$varName];
                $val = $GLOBALS[$varName];
                $info = is_string($val) ? strlen($val) . ' bytes' : gettype($val);
                $this->log("[+] Found known var \$$varName in GLOBALS: $info");
            }
        }

        // Search for ANY large strings in ALL globals
        foreach ($GLOBALS as $name => $value) {
            if (is_string($value) && strlen($value) > 500 && !isset($this->capturedGlobals[$name])) {
                // Skip system variables
                if (in_array($name, ['GLOBALS', '_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_ENV', '_SESSION', '_REQUEST', 'HTTP_RAW_POST_DATA'])) {
                    continue;
                }
                $this->capturedGlobals[$name] = $value;
                $this->log("[+] Found large string \$$name: " . strlen($value) . " bytes");
            }
        }

        $this->log("[*] Total captured variables: " . count($this->capturedGlobals));

        // Find decryption function
        $this->findDecryptionFunction();

        // Find secret key
        $this->findSecretKey();

        // Find encrypted payload
        $this->findEncryptedPayload();

        // Attempt decryption
        $this->attemptDecryption();

        // Save results
        $this->saveResults();

        return $this->decodedSource !== null;
    }

    private function findDecryptionFunction() {
        $this->log("[*] Searching for decryption function...");

        $userFuncs = get_defined_functions()['user'];

        foreach ($userFuncs as $funcName) {
            // Check against known names
            if (in_array(strtolower($funcName), $this->decryptFuncNames)) {
                $this->decryptionFunction = $funcName;
                $this->log("[+] Found decryption function: $funcName");
                return;
            }

            // Check function signature (2 params: data + key)
            try {
                $ref = new ReflectionFunction($funcName);
                $params = $ref->getParameters();
                if (count($params) == 2) {
                    $this->log("[?] Potential decrypt function: $funcName (2 params)");
                    if ($this->decryptionFunction === null) {
                        $this->decryptionFunction = $funcName;
                    }
                }
            } catch (Exception $e) {}
        }

        if ($this->decryptionFunction === null) {
            $this->log("[-] No obvious decryption function found");
        }
    }

    private function findSecretKey() {
        $this->log("[*] Searching for secret key...");

        // First check captured globals
        foreach ($this->keyVarNames as $keyName) {
            if (isset($this->capturedGlobals[$keyName])) {
                $value = $this->capturedGlobals[$keyName];
                if (is_string($value) && strlen($value) > 4) {
                    $this->secretKey = $value;
                    $this->log("[+] Found secret key in \$$keyName: " . substr($value, 0, 20) . "...");
                    return;
                }
            }
        }

        // Also check directly in $GLOBALS (in case they were missed)
        foreach ($this->keyVarNames as $keyName) {
            if (isset($GLOBALS[$keyName]) && !isset($this->capturedGlobals[$keyName])) {
                $value = $GLOBALS[$keyName];
                if (is_string($value) && strlen($value) > 4) {
                    $this->secretKey = $value;
                    $this->capturedGlobals[$keyName] = $value;
                    $this->log("[+] Found secret key in GLOBALS[\$$keyName]: " . substr($value, 0, 20) . "...");
                    return;
                }
            }
        }

        // Check defined constants
        $constants = get_defined_constants(true);
        if (isset($constants['user'])) {
            foreach ($constants['user'] as $name => $value) {
                $nameLower = strtolower($name);
                if (strpos($nameLower, 'key') !== false || strpos($nameLower, 'secret') !== false) {
                    if (is_string($value) && strlen($value) > 4) {
                        $this->secretKey = $value;
                        $this->log("[+] Found secret key in constant $name: " . substr($value, 0, 20) . "...");
                        return;
                    }
                }
            }
        }

        // Search all globals for potential keys (base64-like strings)
        foreach ($this->capturedGlobals as $name => $value) {
            if (is_string($value) && strlen($value) >= 8 && strlen($value) <= 64) {
                if (preg_match('/^[A-Za-z0-9+\/=]+$/', $value)) {
                    $this->log("[?] Potential key in \$$name: $value");
                    if ($this->secretKey === null) {
                        $this->secretKey = $value;
                    }
                }
            }
        }

        if ($this->secretKey === null) {
            $this->log("[-] No secret key found");
        }
    }

    private function findEncryptedPayload() {
        $this->log("[*] Searching for encrypted payload...");

        $candidates = [];

        // Check known payload variable names in captured globals
        foreach ($this->payloadVarNames as $varName) {
            if (isset($this->capturedGlobals[$varName])) {
                $value = $this->capturedGlobals[$varName];
                if (is_string($value) && strlen($value) > 100) {
                    $candidates[$varName] = strlen($value);
                    $this->log("[?] Potential payload in \$$varName: " . strlen($value) . " bytes");
                }
            }
        }

        // Also check directly in $GLOBALS for known names
        foreach ($this->payloadVarNames as $varName) {
            if (isset($GLOBALS[$varName]) && !isset($candidates[$varName])) {
                $value = $GLOBALS[$varName];
                if (is_string($value) && strlen($value) > 100) {
                    $candidates[$varName] = strlen($value);
                    $this->capturedGlobals[$varName] = $value;
                    $this->log("[?] Potential payload in GLOBALS[\$$varName]: " . strlen($value) . " bytes");
                }
            }
        }

        // Also search for any large string variables
        foreach ($this->capturedGlobals as $name => $value) {
            if (is_string($value) && strlen($value) > 1000) {
                if (!isset($candidates[$name])) {
                    $candidates[$name] = strlen($value);
                    $this->log("[?] Large string in \$$name: " . strlen($value) . " bytes");
                }
            }
        }

        // Search ALL globals for large strings we might have missed
        foreach ($GLOBALS as $name => $value) {
            if (is_string($value) && strlen($value) > 1000 && !isset($candidates[$name])) {
                if (in_array($name, ['GLOBALS', '_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_ENV', '_SESSION', '_REQUEST'])) {
                    continue;
                }
                $candidates[$name] = strlen($value);
                $this->capturedGlobals[$name] = $value;
                $this->log("[?] Large string in GLOBALS[\$$name]: " . strlen($value) . " bytes");
            }
        }

        // Use the largest one as primary candidate
        if (!empty($candidates)) {
            arsort($candidates);
            $bestCandidate = key($candidates);
            $this->encryptedPayload = [
                'name' => $bestCandidate,
                'value' => $this->capturedGlobals[$bestCandidate]
            ];
            $this->log("[+] Selected payload: \$$bestCandidate (" . $candidates[$bestCandidate] . " bytes)");
        } else {
            $this->log("[-] No encrypted payload found");
        }
    }

    private function attemptDecryption() {
        $this->log("\n[*] Attempting decryption...");

        if ($this->encryptedPayload === null) {
            $this->log("[-] No payload to decrypt");
            return;
        }

        $payload = $this->encryptedPayload['value'];
        $payloadName = $this->encryptedPayload['name'];

        // Method 1: Use discovered decryption function
        if ($this->decryptionFunction !== null && $this->secretKey !== null) {
            $this->log("[*] Trying {$this->decryptionFunction}(\${$payloadName}, \$secret_key)...");
            try {
                $func = $this->decryptionFunction;
                $result = @$func($payload, $this->secretKey);
                if ($this->isValidPHP($result)) {
                    $this->decodedSource = $result;
                    $this->log("[+] SUCCESS! Decrypted " . strlen($result) . " bytes");
                    return;
                }
            } catch (Exception $e) {
                $this->log("[!] Function call failed: " . $e->getMessage());
            }
        }

        // Method 2: Try common decryption patterns
        $this->log("[*] Trying common decryption patterns...");

        $methods = [
            'base64_decode' => function($data) { return base64_decode($data); },
            'base64+gzuncompress' => function($data) { return @gzuncompress(base64_decode($data)); },
            'base64+gzinflate' => function($data) { return @gzinflate(base64_decode($data)); },
            'base64+gzdecode' => function($data) { return @gzdecode(base64_decode($data)); },
            'double_base64' => function($data) { return base64_decode(base64_decode($data)); },
            'rot13+base64' => function($data) { return base64_decode(str_rot13($data)); },
        ];

        foreach ($methods as $name => $method) {
            $result = @$method($payload);
            if ($this->isValidPHP($result)) {
                $this->decodedSource = $result;
                $this->log("[+] SUCCESS with $name! Decrypted " . strlen($result) . " bytes");
                return;
            }
        }

        // Method 3: Try all captured keys with decryption function
        if ($this->decryptionFunction !== null) {
            $this->log("[*] Trying all potential keys...");
            foreach ($this->capturedGlobals as $keyName => $keyValue) {
                if (is_string($keyValue) && strlen($keyValue) >= 4 && strlen($keyValue) <= 100) {
                    try {
                        $func = $this->decryptionFunction;
                        $result = @$func($payload, $keyValue);
                        if ($this->isValidPHP($result)) {
                            $this->decodedSource = $result;
                            $this->log("[+] SUCCESS with key from \$$keyName!");
                            return;
                        }
                    } catch (Exception $e) {}
                }
            }
        }

        // Method 4: Try all large variables with all potential keys
        $this->log("[*] Brute-forcing combinations...");
        $largeVars = [];
        $potentialKeys = [];

        foreach ($this->capturedGlobals as $name => $value) {
            if (is_string($value)) {
                if (strlen($value) > 500) {
                    $largeVars[$name] = $value;
                } elseif (strlen($value) >= 4 && strlen($value) <= 100) {
                    $potentialKeys[$name] = $value;
                }
            }
        }

        if ($this->decryptionFunction !== null) {
            $func = $this->decryptionFunction;
            foreach ($largeVars as $varName => $varValue) {
                foreach ($potentialKeys as $keyName => $keyValue) {
                    $result = @$func($varValue, $keyValue);
                    if ($this->isValidPHP($result)) {
                        $this->decodedSource = $result;
                        $this->log("[+] SUCCESS! \$$varName decrypted with \$$keyName");
                        return;
                    }
                }
            }
        }

        $this->log("[-] All decryption attempts failed");
    }

    private function isValidPHP($content) {
        if (!is_string($content) || strlen($content) < 50) {
            return false;
        }

        // Check for PHP code patterns
        $patterns = [
            '/function\s+\w+\s*\(/i',
            '/class\s+\w+/i',
            '/\$\w+\s*=/i',
            '/<\?php/i',
            '/if\s*\(/i',
            '/foreach\s*\(/i',
            '/while\s*\(/i',
            '/return\s+/i',
        ];

        $matches = 0;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $matches++;
            }
        }

        return $matches >= 2;
    }

    private function saveResults() {
        $this->log("\n[*] Saving results...");

        if ($this->decodedSource !== null) {
            // Clean up the source
            $source = $this->decodedSource;

            // Add header comment
            $header = "<?php\n";
            $header .= "/**\n";
            $header .= " * Decoded by ionCube Decoder Tool\n";
            $header .= " * Original file: " . basename($this->targetFile) . "\n";
            $header .= " * Decoded: " . date('Y-m-d H:i:s') . "\n";
            $header .= " */\n\n";

            // Remove existing <?php if present
            $source = preg_replace('/^<\?php\s*/i', '', $source);

            $finalSource = $header . $source;

            file_put_contents($this->outputFile, $finalSource);
            $this->log("[+] Decoded source saved to: " . $this->outputFile);
            $this->log("[+] Size: " . strlen($finalSource) . " bytes");
        } else {
            $this->log("[-] No decoded source to save");
        }

        // Save captured globals for analysis
        $globalsFile = dirname($this->outputFile) . '/captured_globals.json';
        $globalsData = [];
        foreach ($this->capturedGlobals as $name => $value) {
            if (is_string($value) && strlen($value) > 10000) {
                $globalsData[$name] = '[' . strlen($value) . ' bytes]';
            } else {
                $globalsData[$name] = $value;
            }
        }
        file_put_contents($globalsFile, json_encode($globalsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->log("[+] Captured globals saved to: $globalsFile");

        // If decryption failed, save ALL globals for debugging
        if ($this->decodedSource === null) {
            $allGlobalsFile = dirname($this->outputFile) . '/all_globals_debug.json';
            $allGlobalsData = [];
            foreach ($GLOBALS as $name => $value) {
                if (in_array($name, ['GLOBALS', '_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_ENV', '_SESSION', '_REQUEST'])) {
                    continue;
                }
                if (is_string($value)) {
                    if (strlen($value) > 500) {
                        $allGlobalsData[$name] = [
                            'type' => 'string',
                            'length' => strlen($value),
                            'preview' => substr($value, 0, 200) . '...',
                            'is_base64' => (bool)preg_match('/^[A-Za-z0-9+\/=]+$/', substr($value, 0, 100))
                        ];
                    } else {
                        $allGlobalsData[$name] = ['type' => 'string', 'value' => $value];
                    }
                } elseif (is_array($value)) {
                    $allGlobalsData[$name] = ['type' => 'array', 'count' => count($value)];
                } elseif (is_object($value)) {
                    $allGlobalsData[$name] = ['type' => 'object', 'class' => get_class($value)];
                } else {
                    $allGlobalsData[$name] = ['type' => gettype($value), 'value' => $value];
                }
            }
            file_put_contents($allGlobalsFile, json_encode($allGlobalsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->log("[+] All globals debug dump saved to: $allGlobalsFile");

            // Also list all user functions
            $funcsFile = dirname($this->outputFile) . '/user_functions.json';
            $userFuncs = get_defined_functions()['user'];
            $funcData = [];
            foreach ($userFuncs as $func) {
                try {
                    $ref = new ReflectionFunction($func);
                    $params = [];
                    foreach ($ref->getParameters() as $p) {
                        $params[] = '$' . $p->getName();
                    }
                    $funcData[$func] = [
                        'params' => implode(', ', $params),
                        'file' => $ref->getFileName(),
                        'line' => $ref->getStartLine()
                    ];
                } catch (Exception $e) {
                    $funcData[$func] = ['error' => $e->getMessage()];
                }
            }
            file_put_contents($funcsFile, json_encode($funcData, JSON_PRETTY_PRINT));
            $this->log("[+] User functions saved to: $funcsFile");
        }

        $this->log("\n=== Decoding Complete ===");
    }

    public function getDecodedSource() {
        return $this->decodedSource;
    }

    public function getCapturedGlobals() {
        return $this->capturedGlobals;
    }
}

// CLI interface
if (php_sapi_name() === 'cli') {
    echo "\n";
    echo "======================================\n";
    echo "   ionCube Decoder Tool v1.1\n";
    echo "   For Security Research Only\n";
    echo "======================================\n\n";

    if ($argc < 2) {
        echo "Usage: php " . basename($argv[0]) . " <target_file> [output_file]\n\n";
        echo "Arguments:\n";
        echo "  target_file   Path to ionCube-encrypted PHP file\n";
        echo "  output_file   (Optional) Path for decoded output\n\n";
        echo "Example:\n";
        echo "  php " . basename($argv[0]) . " /path/to/encrypted.php\n";
        echo "  php " . basename($argv[0]) . " encrypted.php decoded.php\n\n";
        exit(1);
    }

    $targetFile = $argv[1];
    $outputFile = isset($argv[2]) ? $argv[2] : null;

    // Convert to absolute path if needed
    if (!preg_match('/^[\/\\\\]/', $targetFile) && !preg_match('/^[A-Za-z]:/', $targetFile)) {
        $targetFile = getcwd() . '/' . $targetFile;
    }

    $decoder = new IonCubeDecoder($targetFile, $outputFile);
    $success = $decoder->decode();

    exit($success ? 0 : 1);
}
