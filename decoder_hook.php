<?php
/**
 * ionCube Decoder with Function Hooking
 *
 * Uses uopz extension to hook decryption functions and capture
 * the decrypted output before it gets eval'd.
 *
 * @version 1.1
 */

error_reporting(E_ALL);

// Storage for captured data
$CAPTURED_DECRYPTIONS = [];
$CAPTURED_EVALS = [];

echo "\n";
echo "======================================\n";
echo "   ionCube Decoder (Hook Mode) v1.1\n";
echo "   For Security Research Only\n";
echo "======================================\n\n";

if ($argc < 2) {
    echo "Usage: php " . basename($argv[0]) . " <target_file> [output_file]\n";
    exit(1);
}

$targetFile = $argv[1];
$outputFile = isset($argv[2]) ? $argv[2] : dirname($targetFile) . '/decoded_' . basename($targetFile);

// Convert to absolute path
if (!preg_match('/^[\/\\\\]/', $targetFile) && !preg_match('/^[A-Za-z]:/', $targetFile)) {
    $targetFile = getcwd() . '/' . $targetFile;
}

echo "[*] Target: $targetFile\n";
echo "[*] Output: $outputFile\n\n";

// Check ionCube
if (!extension_loaded('ionCube Loader')) {
    die("ERROR: ionCube Loader not installed!\n");
}
echo "[+] ionCube Loader detected\n";

// Check uopz
if (!extension_loaded('uopz')) {
    echo "[!] WARNING: uopz extension not loaded - trying alternative method\n";
    $useUopz = false;
} else {
    echo "[+] uopz extension detected\n";
    $useUopz = true;
}

// Hook eval() to capture executed code
if ($useUopz) {
    // We can't hook eval directly, but we can hook the decryption function after it's defined
}

// First, we need to load the file to get the d__c function defined
echo "\n[*] Phase 1: Loading file to discover functions...\n";

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SESSION = [];
@session_start();

// Get functions before
$funcsBefore = get_defined_functions()['user'];

ob_start();
try {
    include $targetFile;
} catch (Throwable $e) {
    echo "[!] Error: " . $e->getMessage() . "\n";
}
ob_end_clean();

// Get functions after
$funcsAfter = get_defined_functions()['user'];
$newFuncs = array_diff($funcsAfter, $funcsBefore);

echo "[+] New functions defined: " . implode(', ', $newFuncs) . "\n";

// Find the decrypt function
$decryptFunc = null;
$knownDecryptNames = ['d__c', 'decrypt', 'decode', 'dec', '_d', 'd'];

foreach ($newFuncs as $func) {
    if (in_array(strtolower($func), $knownDecryptNames)) {
        $decryptFunc = $func;
        break;
    }
    // Check signature
    $ref = new ReflectionFunction($func);
    if (count($ref->getParameters()) == 2) {
        $decryptFunc = $func;
    }
}

if ($decryptFunc) {
    echo "[+] Found decrypt function: $decryptFunc\n";

    // Get function source info
    $ref = new ReflectionFunction($decryptFunc);
    echo "[*] Function file: " . $ref->getFileName() . "\n";
    echo "[*] Parameters: ";
    $params = [];
    foreach ($ref->getParameters() as $p) {
        $params[] = '$' . $p->getName();
    }
    echo implode(', ', $params) . "\n";
}

// Now try to hook the decrypt function
if ($useUopz && $decryptFunc) {
    echo "\n[*] Phase 2: Hooking $decryptFunc function...\n";

    // Create a wrapper that captures input/output
    $originalFunc = $decryptFunc;

    uopz_set_return($decryptFunc, function($code, $key) use ($originalFunc, &$CAPTURED_DECRYPTIONS) {
        global $CAPTURED_DECRYPTIONS;

        // Call original function
        $result = $originalFunc($code, $key);

        // Capture the result
        $CAPTURED_DECRYPTIONS[] = [
            'key' => $key,
            'input_length' => strlen($code),
            'output_length' => strlen($result),
            'output' => $result
        ];

        // Log it
        file_put_contents('/tmp/captured_decrypt.log',
            "=== Decryption captured ===\n" .
            "Key: $key\n" .
            "Input: " . strlen($code) . " bytes\n" .
            "Output: " . strlen($result) . " bytes\n" .
            "Result:\n$result\n\n",
            FILE_APPEND
        );

        return $result;
    }, true);

    echo "[+] Hook installed\n";

    // Re-include the file to trigger the decryption
    echo "[*] Re-loading file to capture decryption...\n";

    ob_start();
    try {
        // We need a fresh include - use a wrapper
        $wrapperCode = '<?php include "' . addslashes($targetFile) . '";';
        $tmpFile = '/tmp/wrapper_' . uniqid() . '.php';
        file_put_contents($tmpFile, $wrapperCode);
        include $tmpFile;
        unlink($tmpFile);
    } catch (Throwable $e) {
        echo "[!] Error: " . $e->getMessage() . "\n";
    }
    ob_end_clean();

    // Check if we captured anything
    if (!empty($CAPTURED_DECRYPTIONS)) {
        echo "[+] Captured " . count($CAPTURED_DECRYPTIONS) . " decryption(s)!\n";

        foreach ($CAPTURED_DECRYPTIONS as $i => $cap) {
            echo "\n[*] Decryption #$i:\n";
            echo "    Key: " . substr($cap['key'], 0, 30) . "...\n";
            echo "    Output: " . $cap['output_length'] . " bytes\n";

            // Save the largest one (likely the main code)
            if ($cap['output_length'] > 1000) {
                $source = $cap['output'];

                // Clean up
                $header = "<?php\n/**\n * Decoded by ionCube Decoder (Hook Mode)\n";
                $header .= " * Original: " . basename($targetFile) . "\n";
                $header .= " * Date: " . date('Y-m-d H:i:s') . "\n */\n\n";

                $source = preg_replace('/^<\?php\s*/i', '', $source);
                file_put_contents($outputFile, $header . $source);

                echo "\n[+] SUCCESS! Saved to: $outputFile\n";
                echo "[+] Size: " . strlen($source) . " bytes\n";
            }
        }
    } else {
        echo "[-] No decryptions captured via hook\n";
    }
}

// Alternative method: Read the file and try to extract variables
echo "\n[*] Phase 3: Alternative extraction method...\n";

// The ionCube file, when decoded by the loader, might have the code in a specific format
// Let's try to find it by looking at defined variables with var_export

// Read the raw file
$rawContent = file_get_contents($targetFile);
echo "[*] Raw file size: " . strlen($rawContent) . " bytes\n";

// Check if there's a pattern we can exploit
// Many ionCube files have a structure like: eval(d__c($s__t, $secret_key));
// The $s__t and $secret_key are embedded in the decoded ionCube content

// Try to get the function body if possible
if ($decryptFunc && function_exists($decryptFunc)) {
    echo "[*] Attempting to analyze $decryptFunc function...\n";

    $ref = new ReflectionFunction($decryptFunc);

    // Try to call the function with test data to understand it
    // We need to find the actual encrypted data and key

    // Search for any large base64-like strings in globals
    $potentialPayloads = [];
    $potentialKeys = [];

    foreach ($GLOBALS as $name => $value) {
        if (is_string($value) && strlen($value) > 100) {
            if (preg_match('/^[A-Za-z0-9+\/=\s]+$/', $value)) {
                $potentialPayloads[$name] = $value;
                echo "[?] Potential payload: \$$name (" . strlen($value) . " bytes)\n";
            }
        }
        if (is_string($value) && strlen($value) >= 5 && strlen($value) <= 100) {
            $potentialKeys[$name] = $value;
        }
    }

    // Try combinations
    if (!empty($potentialPayloads)) {
        echo "[*] Trying payload/key combinations...\n";
        foreach ($potentialPayloads as $pName => $payload) {
            foreach ($potentialKeys as $kName => $key) {
                try {
                    $result = @$decryptFunc($payload, $key);
                    if ($result && strlen($result) > 100 && preg_match('/function|class|\$\w+\s*=/', $result)) {
                        echo "[+] SUCCESS with \$$pName + \$$kName!\n";

                        $header = "<?php\n/**\n * Decoded by ionCube Decoder\n";
                        $header .= " * Payload var: \$$pName\n";
                        $header .= " * Key var: \$$kName\n";
                        $header .= " * Date: " . date('Y-m-d H:i:s') . "\n */\n\n";

                        $result = preg_replace('/^<\?php\s*/i', '', $result);
                        file_put_contents($outputFile, $header . $result);

                        echo "[+] Saved to: $outputFile\n";
                        exit(0);
                    }
                } catch (Throwable $e) {}
            }
        }
    }
}

echo "\n[*] Decoding complete. Check output files.\n";
