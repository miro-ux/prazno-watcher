<?php
// PHP 5.3+ compatible — uses curl.exe for HTTP, single fullSync call per poll

// ---------------------------------------------------------------------------
// Load .env.local
// ---------------------------------------------------------------------------
$envFile = dirname(__FILE__) . '/.env.local';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        $parts = explode('=', $line, 2);
        $key   = trim($parts[0]);
        $tmp   = explode('#', $parts[1], 2);
        $val   = trim($tmp[0]);
        if ($key !== '' && !isset($_ENV[$key])) {
            putenv($key . '=' . $val);
            $_ENV[$key] = $val;
        }
    }
}

function env($key, $default = '') {
    $v = getenv($key);
    return ($v !== false && $v !== '') ? $v : $default;
}

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
$CONVEX_URL = env('CONVEX_URL', 'https://accurate-platypus-907.convex.cloud');
$POLL_SLEEP = (int) env('POLL_INTERVAL_MS', '3000');
$MYSQL_HOST = env('MYSQL_HOST',     '192.168.0.21');
$MYSQL_USER = env('MYSQL_USER',     'ivan');
$MYSQL_PASS = env('MYSQL_PASSWORD', '22coldy22');
$MYSQL_DB   = env('MYSQL_DATABASE', 'prazno');

// ---------------------------------------------------------------------------
// MySQL
// ---------------------------------------------------------------------------
$db = new mysqli($MYSQL_HOST, $MYSQL_USER, $MYSQL_PASS, $MYSQL_DB);
if ($db->connect_errno) {
    fwrite(STDERR, "[fatal] MySQL connect failed: " . $db->connect_error . "\n");
    exit(1);
}
$db->set_charset('utf8mb4');
echo "[db] Connected to " . $MYSQL_DB . " on " . $MYSQL_HOST . " as " . $MYSQL_USER . "\n";
echo "[convex] Target: " . $CONVEX_URL . "\n";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function toNum($v) {
    return ($v !== null) ? (float)$v : null;
}

function nullStr($v) {
    return ($v !== null) ? (string)$v : null;
}

function rowToArgs($r) {
    return array(
        'mysql_id'   => (int)$r['ID'],
        'Stoka'      => nullStr($r['Stoka']),
        'Br'         => toNum($r['Br']),
        'Cena'       => toNum($r['Cena']),
        'masa'       => toNum($r['masa']),
        'Miasto'     => isset($r['Miasto']) ? (string)$r['Miasto'] : '',
        'Servitior'  => nullStr($r['Servitior']),
        'SmetkaN'    => toNum($r['SmetkaN']),
        'Data'       => nullStr($r['Data']),
        'Time'       => nullStr($r['Time']),
        'Time2'      => nullStr($r['Time2']),
        'Zaiavka'    => nullStr($r['Zaiavka']),
        'Status'     => toNum($r['Status']),
        'Platena'    => toNum($r['Platena']),
        'Otchetena'  => toNum($r['Otchetena']),
        'Porcent'    => toNum($r['Porcent']),
        'IDNap'      => toNum($r['IDNap']),
        'ID_Stoca'   => toNum($r['ID_Stoca']),
        'Suplimente' => toNum($r['Suplimente']),
    );
}

/** POST JSON to Convex via curl.exe */
function convexPost($url, $payload) {
    $body = json_encode($payload);
    if ($body === false) {
        fwrite(STDERR, "  [json error] " . json_last_error_msg() . "\n");
        return false;
    }

    $tmpIn = tempnam(sys_get_temp_dir(), 'cvx');
    file_put_contents($tmpIn, $body);
    $tmpInWin = str_replace('/', '\\', $tmpIn);

    $cmd = 'curl.exe -s '
         . '-X POST '
         . '-H "Content-Type: application/json" '
         . '-d @' . escapeshellarg($tmpInWin) . ' '
         . '--max-time 30 '
         . '-w "|||%{http_code}" '
         . escapeshellarg($url);

    $raw = shell_exec($cmd);
    @unlink($tmpIn);

    if ($raw === null) $raw = '';

    $delimPos = strrpos($raw, '|||');
    if ($delimPos !== false) {
        $respBody = substr($raw, 0, $delimPos);
        $httpCode = (int)trim(substr($raw, $delimPos + 3));
    } else {
        $respBody = $raw;
        $httpCode = 0;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        fwrite(STDERR, "  [http " . $httpCode . "] " . trim($respBody) . "\n");
        return false;
    }

    $decoded = json_decode($respBody, true);

    if (is_array($decoded) && isset($decoded['status']) && $decoded['status'] === 'error') {
        $msg = isset($decoded['errorMessage']) ? $decoded['errorMessage'] : 'unknown error';
        fwrite(STDERR, "  [convex error] " . $msg . "\n");
        return false;
    }

    return ($decoded !== null) ? $decoded : true;
}

// ---------------------------------------------------------------------------
// Test connectivity
// ---------------------------------------------------------------------------
echo "[init] Testing Convex...\n";
$test = convexPost($CONVEX_URL . '/api/query', array(
    'path'   => 'prod_activ:debugInfo',
    'args'   => (object)array(),
    'format' => 'json',
));
if ($test === false) {
    fwrite(STDERR, "[fatal] Cannot reach Convex\n");
    exit(1);
}
$info = isset($test['value']) ? $test['value'] : array();
echo "[init] Convex OK — "
   . (isset($info['total']) ? $info['total'] : '?') . " total, "
   . (isset($info['active']) ? $info['active'] : '?') . " active, "
   . (isset($info['archived']) ? $info['archived'] : '?') . " archived\n";

// ---------------------------------------------------------------------------
// Poll loop — only call fullSync when MySQL data actually changed
// ---------------------------------------------------------------------------
set_time_limit(0);
$pollCount = 0;
$lastHash  = '';

while (true) {
    $pollCount++;

    // Read ALL current rows from MySQL
    $result = $db->query("SELECT * FROM prod_activ ORDER BY ID ASC");
    $rows = array();
    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $rows[] = $r;
        }
        $result->free();
    } else {
        fwrite(STDERR, "[error] MySQL query failed: " . $db->error . "\n");
        usleep($POLL_SLEEP * 1000);
        continue;
    }

    // Build args array (strip nulls from each row)
    $allArgs = array();
    foreach ($rows as $r) {
        $args = rowToArgs($r);
        $cleaned = array();
        foreach ($args as $k => $v) {
            if ($v !== null) $cleaned[$k] = $v;
        }
        $allArgs[] = $cleaned;
    }

    // Hash the data — skip Convex call if nothing changed
    $currentHash = md5(json_encode($allArgs));
    if ($currentHash === $lastHash) {
        usleep($POLL_SLEEP * 1000);
        continue;
    }

    // One call: upsert all + archive anything not in MySQL
    $t0 = microtime(true);
    $res = convexPost($CONVEX_URL . '/api/mutation', array(
        'path'   => 'prod_activ:fullSync',
        'args'   => array('rows' => $allArgs),
        'format' => 'json',
    ));
    $elapsed = round((microtime(true) - $t0) * 1000);

    if ($res !== false) {
        $lastHash = $currentHash;  // Only update hash on success
        $v = isset($res['value']) ? $res['value'] : array();
        $ins = isset($v['inserted']) ? $v['inserted'] : 0;
        $upd = isset($v['updated']) ? $v['updated'] : 0;
        $arc = isset($v['archived']) ? $v['archived'] : 0;

        echo "[sync #" . $pollCount . "] " . count($rows) . " MySQL rows -> "
           . $ins . " new, " . $upd . " updated, " . $arc . " archived"
           . " (" . $elapsed . "ms)\n";
    } else {
        fwrite(STDERR, "[sync #" . $pollCount . "] FAILED after " . $elapsed . "ms\n");
    }

    usleep($POLL_SLEEP * 1000);
}
