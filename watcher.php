<?php
// PHP 5.3+ compatible

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
$CONVEX_URL = env('CONVEX_URL', 'https://youthful-cobra-798.convex.cloud');
$POLL_SLEEP = (int) env('POLL_INTERVAL_MS', '3000');
$MYSQL_HOST = env('MYSQL_HOST',     '192.168.0.21');
$MYSQL_USER = env('MYSQL_USER',     'ivan');
$MYSQL_PASS = env('MYSQL_PASSWORD', 'sotazero');
$MYSQL_DB   = env('MYSQL_DATABASE', 'prazno');

// ---------------------------------------------------------------------------
// MySQL connection
// ---------------------------------------------------------------------------
$db = new mysqli($MYSQL_HOST, $MYSQL_USER, $MYSQL_PASS, $MYSQL_DB);
if ($db->connect_errno) {
    fwrite(STDERR, "[fatal] MySQL connect failed: " . $db->connect_error . "\n");
    exit(1);
}
$db->set_charset('utf8mb4');

// ---------------------------------------------------------------------------
// Init: start from current max ID
// ---------------------------------------------------------------------------
$initResult = $db->query("SELECT MAX(ID) AS maxId FROM prod_activ");
$initRow    = $initResult->fetch_assoc();
$lastMaxId  = isset($initRow['maxId']) ? (int)$initRow['maxId'] : 0;
echo "[init] Starting from ID > " . $lastMaxId . "\n";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function toNum($v) {
    return ($v !== null) ? (float)$v : null;
}

function nullStr($v) {
    return ($v !== null) ? (string)$v : null;
}

function convexUpsert($baseUrl, $args) {
    // Remove null values — Convex optional fields must be absent, not null
    foreach ($args as $k => $v) {
        if ($v === null) unset($args[$k]);
    }

    $body = json_encode(array(
        'path'   => 'prod_activ:upsert',
        'args'   => $args,
        'format' => 'json',
    ));

    if ($body === false) {
        fwrite(STDERR, "  [json error] " . json_last_error_msg() . "\n");
        return false;
    }

    $ch = curl_init($baseUrl . '/api/mutation');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_TIMEOUT,        10);

    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        fwrite(STDERR, "  [curl error] " . $curlErr . "\n");
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        fwrite(STDERR, "  [http " . $httpCode . "] " . $resp . "\n");
        return false;
    }
    return true;
}

// ---------------------------------------------------------------------------
// Poll loop
// ---------------------------------------------------------------------------
set_time_limit(0);

$stmt = $db->prepare("SELECT * FROM prod_activ WHERE ID > ? ORDER BY ID ASC");
if (!$stmt) {
    fwrite(STDERR, "[fatal] Prepare failed: " . $db->error . "\n");
    exit(1);
}

while (true) {
    $stmt->bind_param('i', $lastMaxId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = array();
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $result->free();

    if (count($rows) > 0) {
        echo "[sync] " . count($rows) . " new row(s) found\n";

        foreach ($rows as $r) {
            $id   = (int)$r['ID'];
            $args = array(
                'mysql_id'   => $id,
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

            if (convexUpsert($CONVEX_URL, $args)) {
                echo "  -> upserted ID " . $id . "\n";
                if ($id > $lastMaxId) $lastMaxId = $id;
            } else {
                fwrite(STDERR, "  [error] failed to upsert ID " . $id . ", will retry next poll\n");
            }
        }
    }

    usleep($POLL_SLEEP * 1000);
}
