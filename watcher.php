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
$MYSQL_PASS = env('MYSQL_PASSWORD', '22coldy22');
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
echo "[db] Connected to " . $MYSQL_DB . " on " . $MYSQL_HOST . " as " . $MYSQL_USER . "\n";

// ---------------------------------------------------------------------------
// Init: start from 0 — syncs all existing rows first, then keeps polling
// ---------------------------------------------------------------------------
$lastMaxId = 0;
$countResult = $db->query("SELECT COUNT(*) AS cnt, MAX(ID) AS maxId FROM prod_activ");
$countRow = $countResult->fetch_assoc();
$totalRows = isset($countRow['cnt']) ? (int)$countRow['cnt'] : 0;
$maxId = isset($countRow['maxId']) ? (int)$countRow['maxId'] : 0;
echo "[init] MySQL has " . $totalRows . " rows, highest ID = " . $maxId . "\n";
echo "[init] Starting full sync from ID 0...\n";

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

    // Use file_get_contents — works without the curl extension
    $context = stream_context_create(array(
        'http' => array(
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $body,
            'timeout'       => 10,
            'ignore_errors' => true,
        ),
        'ssl' => array(
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ),
    ));

    $resp = @file_get_contents($baseUrl . '/api/mutation', false, $context);

    if ($resp === false) {
        fwrite(STDERR, "  [http error] request failed\n");
        return false;
    }

    // Check HTTP status from response headers
    $status = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $status = (int)$m[1];
            }
        }
    }

    if ($status < 200 || $status >= 300) {
        fwrite(STDERR, "  [http " . $status . "] " . $resp . "\n");
        return false;
    }
    return true;
}

// ---------------------------------------------------------------------------
// Poll loop — uses query() with integer cast; no get_result()/mysqlnd needed
// ---------------------------------------------------------------------------
set_time_limit(0);

$totalSynced = 0;

while (true) {
    $safeId = (int)$lastMaxId;
    $result = $db->query("SELECT * FROM prod_activ WHERE ID > " . $safeId . " ORDER BY ID ASC");

    $rows = array();
    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $rows[] = $r;
        }
        $result->free();
    } else {
        fwrite(STDERR, "[error] Query failed: " . $db->error . "\n");
    }

    if (count($rows) > 0) {
        echo "[sync] " . count($rows) . " new row(s) found (total synced: " . $totalSynced . " / " . $maxId . ")\n";

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
                $totalSynced++;
                echo "  -> upserted ID " . $id . " (total: " . $totalSynced . ")\n";
                if ($id > $lastMaxId) $lastMaxId = $id;
            } else {
                fwrite(STDERR, "  [error] failed to upsert ID " . $id . ", will retry next poll\n");
            }
        }
    }

    usleep($POLL_SLEEP * 1000);
}
