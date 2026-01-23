<?php
/**
 * ionCube Decoder - Eval Hook Method
 *
 * Hooks the eval() function to capture decrypted code before execution.
 * Requires uopz extension.
 *
 * @version 1.1
 */

error_reporting(0);

echo "\n";
echo "==========================================\n";
echo "   ionCube Decoder (Eval Hook) v1.1\n";
echo "   For Security Research Only\n";
echo "==========================================\n\n";

if ($argc < 2) {
    echo "Usage: php " . basename($argv[0]) . " <target_file> [output_file]\n";
    exit(1);
}

$targetFile = $argv[1];
$outputFile = isset($argv[2]) ? $argv[2] : '/output/decoded_' . basename($targetFile);

if (!preg_match('/^[\/\\\\]/', $targetFile) && !preg_match('/^[A-Za-z]:/', $targetFile)) {
    $targetFile = getcwd() . '/' . $targetFile;
}

echo "[*] Target: $targetFile\n";
echo "[*] Output: $outputFile\n\n";

// Check extensions
if (!extension_loaded('ionCube Loader')) {
    die("ERROR: ionCube Loader not installed!\n");
}
echo "[+] ionCube Loader: OK\n";

if (!extension_loaded('uopz')) {
    die("ERROR: uopz extension not installed!\n");
}
echo "[+] uopz extension: OK\n";

// Storage for captured eval calls
$capturedEvals = [];
$captureFile = '/tmp/captured_evals_' . uniqid() . '.json';

// We can't directly hook eval(), but we CAN use a different approach:
// 1. Create a custom handler that gets called before functions
// 2. Or use uopz_set_hook on the decryption function

echo "\n[*] Setting up capture mechanism...\n";

// Create a file to store captured data (for cross-include communication)
file_put_contents($captureFile, json_encode([]));

// Set up environment
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SESSION = [];
@session_start();

echo "[*] Loading ionCube file to discover decrypt function...\n";

// First pass: load file to get function names
ob_start();
$funcsBefore = get_defined_functions()['user'];

try {
    include $targetFile;
} catch (Throwable $e) {
    // Expected - file may error out
}

$funcsAfter = get_defined_functions()['user'];
ob_end_clean();

$newFuncs = array_diff($funcsAfter, $funcsBefore);
echo "[+] Discovered functions: " . implode(', ', $newFuncs) . "\n";

// Find decrypt function
$decryptFunc = null;
foreach ($newFuncs as $func) {
    $ref = new ReflectionFunction($func);
    $params = $ref->getParameters();
    // Look for 2-param function (data, key)
    if (count($params) == 2) {
        $decryptFunc = $func;
        echo "[+] Found potential decrypt function: $func\n";
        break;
    }
}

if (!$decryptFunc) {
    // Try known names
    foreach (['d__c', 'decrypt', 'decode'] as $name) {
        if (function_exists($name)) {
            $decryptFunc = $name;
            echo "[+] Found decrypt function by name: $name\n";
            break;
        }
    }
}

if (!$decryptFunc) {
    die("[-] Could not find decrypt function!\n");
}

// Now set up a hook on the decrypt function
echo "\n[*] Installing hook on $decryptFunc()...\n";

// Use uopz_set_hook to run code BEFORE the function executes
uopz_set_hook($decryptFunc, function($code, $key) use ($captureFile, $decryptFunc) {
    // Log the call
    $data = json_decode(file_get_contents($captureFile), true) ?: [];
    $data[] = [
        'function' => $decryptFunc,
        'code_length' => strlen($code),
        'code_preview' => substr($code, 0, 100),
        'key' => $key,
        'timestamp' => microtime(true)
    ];
    file_put_contents($captureFile, json_encode($data, JSON_PRETTY_PRINT));
});

// Also try to capture the return value
// Create a wrapper approach using uopz_set_return with execute flag

$captured_results = [];

uopz_set_return($decryptFunc, function($code, $key) use ($decryptFunc, &$captured_results, $outputFile) {
    // Call the REAL function by temporarily removing our hook
    uopz_unset_return($decryptFunc);

    // Get the result
    $result = $decryptFunc($code, $key);

    // Save it
    $captured_results[] = [
        'key' => $key,
        'input_len' => strlen($code),
        'output_len' => strlen($result),
        'result' => $result
    ];

    // If this looks like PHP code, save it
    if (strlen($result) > 500 && preg_match('/(function\s+\w+|class\s+\w+|\$\w+\s*=)/', $result)) {
        echo "[+] CAPTURED! " . strlen($result) . " bytes of PHP code\n";

        $header = "<?php\n/**\n * Decoded by ionCube Decoder (Eval Hook)\n";
        $header .= " * Date: " . date('Y-m-d H:i:s') . "\n";
        $header .= " * Key used: " . substr($key, 0, 20) . "...\n";
        $header .= " */\n\n";

        $source = preg_replace('/^<\?php\s*/i', '', $result);
        file_put_contents($outputFile, $header . $source);

        echo "[+] Saved to: $outputFile\n";
    }

    // Re-install hook for any subsequent calls
    // (but actually we want the code to run, so return the result)
    return $result;
}, true); // true = execute the closure

echo "[+] Hook installed\n";

// Now re-include the file - this time our hook will capture the decryption
echo "\n[*] Re-loading file with hooks active...\n";

// Clear any previous state
foreach ($newFuncs as $func) {
    if ($func !== $decryptFunc) {
        // Can't easily undefine, but that's OK
    }
}

ob_start();
try {
    // Use a fresh include by creating temp wrapper
    $wrapper = "<?php\n";
    $wrapper .= "error_reporting(0);\n";
    $wrapper .= "\$_SERVER['HTTP_HOST'] = 'localhost';\n";
    $wrapper .= "\$_SERVER['REQUEST_METHOD'] = 'GET';\n";
    $wrapper .= "include '" . addslashes($targetFile) . "';\n";

    $wrapperFile = '/tmp/ioncube_wrapper_' . uniqid() . '.php';
    file_put_contents($wrapperFile, $wrapper);

    include $wrapperFile;
    unlink($wrapperFile);

} catch (Throwable $e) {
    echo "[!] Error during execution: " . $e->getMessage() . "\n";
}
$output = ob_get_clean();

// Check results
echo "\n[*] Checking captured data...\n";

$capturedData = json_decode(file_get_contents($captureFile), true);
if (!empty($capturedData)) {
    echo "[+] Hook captured " . count($capturedData) . " function call(s)\n";
    foreach ($capturedData as $i => $call) {
        echo "    Call $i: {$call['function']}() - {$call['code_length']} bytes input\n";
    }
}

if (!empty($captured_results)) {
    echo "[+] Captured " . count($captured_results) . " decryption result(s)\n";
    foreach ($captured_results as $i => $res) {
        echo "    Result $i: {$res['output_len']} bytes\n";
    }
} else {
    echo "[-] No results captured - the file may have already executed\n";
    echo "[*] Try checking if output file was created anyway...\n";
}

// Cleanup
@unlink($captureFile);

// Check if we got output
if (file_exists($outputFile) && filesize($outputFile) > 100) {
    echo "\n[+] SUCCESS! Decoded file saved to: $outputFile\n";
    echo "[+] Size: " . filesize($outputFile) . " bytes\n";
    exit(0);
} else {
    echo "\n[-] Decoding may have failed. Check the output directory.\n";
    exit(1);
}
