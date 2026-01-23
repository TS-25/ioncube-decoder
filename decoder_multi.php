<?php
/**
 * ionCube Decoder - Multi-Method Approach
 *
 * Tries multiple decryption techniques to extract source code.
 * Based on successful decryption patterns.
 *
 * @version 1.1
 */

error_reporting(0);
ini_set('display_errors', 0);

echo "\n";
echo "==========================================\n";
echo "   ionCube Decoder (Multi-Method) v1.1\n";
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

// Check ionCube
if (!extension_loaded('ionCube Loader')) {
    die("ERROR: ionCube Loader not installed!\n");
}
echo "[+] ionCube Loader: OK\n";
echo "[+] uopz: " . (extension_loaded('uopz') ? 'OK' : 'Not loaded') . "\n";
echo "[+] runkit7: " . (extension_loaded('runkit7') ? 'OK' : 'Not loaded') . "\n";

// Common variable names to look for
$payloadVarNames = ['s__t', 'c__t', 'f__t', 'code', 'data', 'payload', 'source', 'encoded', 'str', 'x', 'y', 'z'];
$keyVarNames = ['secret_key', 'key', 'k', 'pass', 'password', 'secret', '_key'];
$decryptFuncNames = ['d__c', 'decrypt', 'decode', 'dec', 'd', '_d'];

$decoded = null;

// Set up environment
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SESSION = [];
@session_start();

echo "\n=== Method 1: Direct Global Variable Extraction ===\n";

// Capture globals before
$globalsBefore = $GLOBALS;

ob_start();
try {
    include $targetFile;
    echo "[+] File loaded successfully\n";
} catch (Throwable $e) {
    echo "[!] Load error: " . $e->getMessage() . "\n";
}
ob_end_clean();

// Find new/changed globals
echo "[*] Scanning globals for encrypted data...\n";

$foundKey = null;
$foundPayload = null;
$foundFunc = null;

// Find decryption function
foreach ($decryptFuncNames as $fname) {
    if (function_exists($fname)) {
        $foundFunc = $fname;
        echo "[+] Found decrypt function: $fname\n";
        break;
    }
}

// Also check user functions
$userFuncs = get_defined_functions()['user'];
foreach ($userFuncs as $func) {
    if ($foundFunc) break;
    try {
        $ref = new ReflectionFunction($func);
        if (count($ref->getParameters()) == 2) {
            $foundFunc = $func;
            echo "[+] Found 2-param function: $func\n";
        }
    } catch (Exception $e) {}
}

// Find key in globals
foreach ($keyVarNames as $kname) {
    if (isset($GLOBALS[$kname]) && is_string($GLOBALS[$kname]) && strlen($GLOBALS[$kname]) > 4) {
        $foundKey = $GLOBALS[$kname];
        echo "[+] Found key in \$$kname: " . substr($foundKey, 0, 20) . "...\n";
        break;
    }
}

// Find payload in globals
$candidates = [];
foreach ($GLOBALS as $name => $value) {
    if (is_string($value) && strlen($value) > 500) {
        $candidates[$name] = strlen($value);
    }
}
arsort($candidates);

foreach ($candidates as $name => $len) {
    if (in_array($name, ['GLOBALS', '_GET', '_POST', '_SERVER', '_ENV', '_SESSION', '_REQUEST', '_FILES', '_COOKIE'])) continue;
    $foundPayload = ['name' => $name, 'value' => $GLOBALS[$name]];
    echo "[+] Found payload candidate \$$name: $len bytes\n";
    break;
}

// Try decryption
if ($foundFunc && $foundKey && $foundPayload) {
    echo "\n[*] Attempting: $foundFunc(\${$foundPayload['name']}, \$key)...\n";
    try {
        $result = @$foundFunc($foundPayload['value'], $foundKey);
        if ($result && strlen($result) > 100 && preg_match('/(function|class|\$\w+\s*=)/', $result)) {
            $decoded = $result;
            echo "[+] SUCCESS! Decrypted " . strlen($result) . " bytes\n";
        }
    } catch (Exception $e) {
        echo "[!] Decryption failed: " . $e->getMessage() . "\n";
    }
}

// Try all combinations
if (!$decoded && $foundFunc) {
    echo "\n[*] Trying all variable combinations...\n";

    foreach ($GLOBALS as $payloadName => $payloadVal) {
        if (!is_string($payloadVal) || strlen($payloadVal) < 500) continue;
        if (in_array($payloadName, ['GLOBALS', '_GET', '_POST', '_SERVER'])) continue;

        foreach ($GLOBALS as $keyName => $keyVal) {
            if (!is_string($keyVal) || strlen($keyVal) < 4 || strlen($keyVal) > 200) continue;

            try {
                $result = @$foundFunc($payloadVal, $keyVal);
                if ($result && strlen($result) > 100 && preg_match('/(function\s+\w+|class\s+\w+|\$\w+\s*=)/', $result)) {
                    $decoded = $result;
                    echo "[+] SUCCESS! \$$payloadName decoded with \$$keyName\n";
                    break 2;
                }
            } catch (Exception $e) {}
        }
    }
}

echo "\n=== Method 2: Common Encoding Patterns ===\n";

if (!$decoded) {
    $methods = [
        'base64' => function($d) { return base64_decode($d); },
        'base64+gz' => function($d) { return @gzuncompress(base64_decode($d)); },
        'base64+inflate' => function($d) { return @gzinflate(base64_decode($d)); },
        'base64+gzdecode' => function($d) { return @gzdecode(base64_decode($d)); },
        'double_base64' => function($d) { return base64_decode(base64_decode($d)); },
    ];

    foreach ($candidates as $varName => $len) {
        if (in_array($varName, ['GLOBALS', '_GET', '_POST', '_SERVER'])) continue;
        $data = $GLOBALS[$varName];

        foreach ($methods as $mname => $method) {
            $result = @$method($data);
            if ($result && strlen($result) > 100 && preg_match('/(function|class|\$\w+\s*=)/', $result)) {
                $decoded = $result;
                echo "[+] SUCCESS! \$$varName decoded with $mname\n";
                break 2;
            }
        }
    }
}

echo "\n=== Method 3: Function Hook via Runkit ===\n";

if (!$decoded && extension_loaded('runkit7') && $foundFunc) {
    echo "[*] Attempting to hook $foundFunc with runkit7...\n";

    $capturedResult = null;

    // Backup and replace the function
    try {
        $hookCode = '
            global $capturedResult;
            $capturedResult = call_user_func_array("' . $foundFunc . '_backup", func_get_args());
            file_put_contents("/tmp/captured_decrypt.txt", $capturedResult);
            return $capturedResult;
        ';

        if (runkit7_function_copy($foundFunc, $foundFunc . '_backup')) {
            if (runkit7_function_redefine($foundFunc, '$code,$key', $hookCode)) {
                echo "[+] Hook installed\n";

                // Re-run the file
                ob_start();
                try {
                    // Create wrapper to re-trigger
                    $wrapperFile = '/tmp/rerun_' . uniqid() . '.php';
                    file_put_contents($wrapperFile, "<?php include '$targetFile';");
                    include $wrapperFile;
                    unlink($wrapperFile);
                } catch (Exception $e) {}
                ob_end_clean();

                if (file_exists('/tmp/captured_decrypt.txt')) {
                    $captured = file_get_contents('/tmp/captured_decrypt.txt');
                    if (strlen($captured) > 100 && preg_match('/(function|class)/', $captured)) {
                        $decoded = $captured;
                        echo "[+] SUCCESS via hook! " . strlen($captured) . " bytes\n";
                    }
                    unlink('/tmp/captured_decrypt.txt');
                }
            }
        }
    } catch (Exception $e) {
        echo "[!] Runkit hook failed: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Method 4: Reflection-Based Extraction ===\n";

if (!$decoded) {
    echo "[*] Extracting function/class signatures...\n";

    $extraction = "<?php\n/**\n * Extracted from ionCube file (signatures only)\n";
    $extraction .= " * Full body extraction requires additional techniques\n";
    $extraction .= " * Date: " . date('Y-m-d H:i:s') . "\n */\n\n";

    // Extract functions
    foreach (get_defined_functions()['user'] as $func) {
        try {
            $ref = new ReflectionFunction($func);
            $params = [];
            foreach ($ref->getParameters() as $p) {
                $ps = '$' . $p->getName();
                if ($p->isOptional()) {
                    try { $ps .= ' = ' . var_export($p->getDefaultValue(), true); }
                    catch (Exception $e) { $ps .= ' = null'; }
                }
                $params[] = $ps;
            }
            $extraction .= "function $func(" . implode(', ', $params) . ") {\n";
            $extraction .= "    // Body not extracted\n}\n\n";
        } catch (Exception $e) {}
    }

    // Extract classes
    foreach (get_declared_classes() as $class) {
        $ref = new ReflectionClass($class);
        if (!$ref->isUserDefined()) continue;

        $extraction .= "class $class {\n";
        foreach ($ref->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) continue;
            $mods = [];
            if ($method->isPublic()) $mods[] = 'public';
            if ($method->isPrivate()) $mods[] = 'private';
            if ($method->isProtected()) $mods[] = 'protected';
            if ($method->isStatic()) $mods[] = 'static';

            $params = [];
            foreach ($method->getParameters() as $p) {
                $params[] = '$' . $p->getName();
            }
            $extraction .= "    " . implode(' ', $mods) . " function " . $method->getName();
            $extraction .= "(" . implode(', ', $params) . ") { }\n";
        }
        $extraction .= "}\n\n";
    }

    // If no full decode, at least save the signatures
    if (!$decoded) {
        file_put_contents($outputFile . '.signatures.php', $extraction);
        echo "[*] Saved signatures to: $outputFile.signatures.php\n";
    }
}

// Save results
echo "\n=== Saving Results ===\n";

if ($decoded) {
    $header = "<?php\n/**\n * Decoded by ionCube Decoder (Multi-Method)\n";
    $header .= " * Original: " . basename($targetFile) . "\n";
    $header .= " * Date: " . date('Y-m-d H:i:s') . "\n */\n\n";

    $source = preg_replace('/^<\?php\s*/i', '', $decoded);
    file_put_contents($outputFile, $header . $source);

    echo "[+] SUCCESS! Decoded source saved to: $outputFile\n";
    echo "[+] Size: " . strlen($decoded) . " bytes\n";
    exit(0);
} else {
    // Save debug info
    $debug = [
        'found_function' => $foundFunc,
        'found_key' => $foundKey ? substr($foundKey, 0, 20) . '...' : null,
        'found_payload' => $foundPayload ? $foundPayload['name'] . ' (' . strlen($foundPayload['value']) . ' bytes)' : null,
        'user_functions' => get_defined_functions()['user'],
        'large_globals' => array_keys(array_filter($GLOBALS, function($v) {
            return is_string($v) && strlen($v) > 500;
        }))
    ];
    file_put_contents($outputFile . '.debug.json', json_encode($debug, JSON_PRETTY_PRINT));

    echo "[-] Full decryption failed\n";
    echo "[*] Debug info saved to: $outputFile.debug.json\n";
    echo "[*] Found function: " . ($foundFunc ?: 'none') . "\n";
    echo "[*] Found key: " . ($foundKey ? 'yes' : 'no') . "\n";
    echo "[*] Found payload: " . ($foundPayload ? 'yes' : 'no') . "\n";
    exit(1);
}
