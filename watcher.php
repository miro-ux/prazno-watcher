<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Load .env.local
// ---------------------------------------------------------------------------
$envFile = __DIR__ . '/.env.local';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim(explode('#', $val, 2)[0]);
        if ($key !== '' && !isset($_ENV[$key])) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}

function env(string $key, string $default = ''): string {
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
    fwrite(STDERR, "[fatal] MySQL connect failed: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');
echo "[db] Connected to {$MYSQL_DB} on {$MYSQL_HOST} as {$MYSQL_USER}\n";

// ---------------------------------------------------------------------------
// Persistent curl handle — reuses TCP + TLS across all requests
// ---------------------------------------------------------------------------
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_TCP_KEEPALIVE  => 1,
]);

function convexPost(string $url, array $payload): array|false {
    global $ch;

    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($resp === false) {
        fwrite(STDERR, "  [curl] " . curl_error($ch) . "\n");
        return false;
    }

    $decoded = json_decode($resp, true);

    if ($code < 200 || $code >= 300) {
        $msg = $decoded['errorMessage'] ?? $resp;
        fwrite(STDERR, "  [http {$code}] {$msg}\n");
        return false;
    }

    if (is_array($decoded) && ($decoded['status'] ?? '') === 'error') {
        fwrite(STDERR, "  [convex] " . ($decoded['errorMessage'] ?? 'unknown error') . "\n");
        return false;
    }

    return $decoded ?? [];
}

function convexMutation(string $baseUrl, string $path, array $args): array|false {
    $args = array_filter($args, fn($v) => $v !== null);
    return convexPost("{$baseUrl}/api/mutation", [
        'path'   => $path,
        'args'   => $args,
        'format' => 'json',
    ]);
}

function convexQuery(string $baseUrl, string $path, array $args = []): array|false {
    return convexPost("{$baseUrl}/api/query", [
        'path'   => $path,
        'args'   => (object) $args,
        'format' => 'json',
    ]);
}

// ---------------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------------
echo "[convex] Target: {$CONVEX_URL}\n";

$countRow  = $db->query("SELECT COUNT(*) AS cnt, MAX(ID) AS maxId FROM prod_activ")->fetch_assoc();
$totalRows = (int) ($countRow['cnt'] ?? 0);
$maxId     = (int) ($countRow['maxId'] ?? 0);

echo "[init] MySQL has {$totalRows} rows, highest ID = {$maxId}\n";

// Test connectivity
$test = convexQuery($CONVEX_URL, 'prod_activ:listActiveMysqlIds');
if ($test === false) {
    fwrite(STDERR, "[fatal] Cannot reach Convex — check CONVEX_URL\n");
    exit(1);
}
echo "[init] Convex OK (" . count($test['value'] ?? []) . " active rows in Convex)\n";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function toNum(?string $v): ?float {
    return $v !== null ? (float) $v : null;
}

function rowToArgs(array $r): array {
    return [
        'mysql_id'   => (int) $r['ID'],
        'Stoka'      => $r['Stoka'],
        'Br'         => toNum($r['Br']),
        'Cena'       => toNum($r['Cena']),
        'masa'       => toNum($r['masa']),
        'Miasto'     => (string) ($r['Miasto'] ?? ''),
        'Servitior'  => $r['Servitior'],
        'SmetkaN'    => toNum($r['SmetkaN']),
        'Data'       => $r['Data'],
        'Time'       => $r['Time'],
        'Time2'      => $r['Time2'],
        'Zaiavka'    => $r['Zaiavka'],
        'Status'     => toNum($r['Status']),
        'Platena'    => toNum($r['Platena']),
        'Otchetena'  => toNum($r['Otchetena']),
        'Porcent'    => toNum($r['Porcent']),
        'IDNap'      => toNum($r['IDNap']),
        'ID_Stoca'   => toNum($r['ID_Stoca']),
        'Suplimente' => toNum($r['Suplimente']),
    ];
}

// ---------------------------------------------------------------------------
// Poll loop
// ---------------------------------------------------------------------------
set_time_limit(0);

$lastMaxId         = 0;
$totalSynced       = 0;
$pollCount         = 0;
$FULL_SYNC_EVERY   = (int) env('FULL_SYNC_EVERY', '10');
$DELETE_CHECK_EVERY = (int) env('DELETE_CHECK_EVERY', '10');
$initialSyncDone   = false;

while (true) {
    $pollCount++;

    // --- 1. Full table sync (boot + every N polls to catch updates) ---
    if (!$initialSyncDone || $pollCount % $FULL_SYNC_EVERY === 0) {
        $label  = $initialSyncDone ? 'resync' : 'init-sync';
        $result = $db->query("SELECT * FROM prod_activ ORDER BY ID ASC");
        $rows   = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        if (count($rows) > 0) {
            $t0 = microtime(true);
            $count = count($rows);
            echo "[{$label}] {$count} row(s) to upsert...\n";
            $ok = 0;
            foreach ($rows as $r) {
                $id = (int) $r['ID'];
                if (convexMutation($CONVEX_URL, 'prod_activ:upsert', rowToArgs($r)) !== false) {
                    $ok++;
                    if ($id > $lastMaxId) $lastMaxId = $id;
                } else {
                    fwrite(STDERR, "  [error] upsert ID {$id}\n");
                }
            }
            $elapsed = round((microtime(true) - $t0) * 1000);
            $totalSynced += $ok;
            echo "[{$label}] Done: {$ok}/{$count} in {$elapsed}ms (lastMaxId = {$lastMaxId})\n";
        }
        $initialSyncDone = true;

    } else {
        // --- 2. Incremental: only new rows ---
        $result = $db->query("SELECT * FROM prod_activ WHERE ID > {$lastMaxId} ORDER BY ID ASC");
        $rows   = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        if (count($rows) > 0) {
            echo "[sync] " . count($rows) . " new row(s)\n";
            foreach ($rows as $r) {
                $id = (int) $r['ID'];
                if (convexMutation($CONVEX_URL, 'prod_activ:upsert', rowToArgs($r)) !== false) {
                    $totalSynced++;
                    echo "  -> upserted ID {$id}\n";
                    if ($id > $lastMaxId) $lastMaxId = $id;
                } else {
                    fwrite(STDERR, "  [error] upsert ID {$id}, will retry\n");
                }
            }
        }
    }

    // --- 3. Soft-delete check ---
    if ($pollCount % $DELETE_CHECK_EVERY === 0) {
        $convexResult = convexQuery($CONVEX_URL, 'prod_activ:listActiveMysqlIds');
        $activeInConvex = $convexResult['value'] ?? null;

        if (is_array($activeInConvex) && count($activeInConvex) > 0) {
            $mysqlIds = [];
            $idResult = $db->query("SELECT ID FROM prod_activ");
            while ($row = $idResult->fetch_assoc()) {
                $mysqlIds[(int) $row['ID']] = true;
            }
            $idResult->free();

            $archived = 0;
            foreach ($activeInConvex as $cid) {
                $cid = (int) $cid;
                if ($cid === 0) continue;
                if (!isset($mysqlIds[$cid])) {
                    if (convexMutation($CONVEX_URL, 'prod_activ:softDelete', ['mysql_id' => $cid]) !== false) {
                        $archived++;
                        echo "  [archive] mysql_id {$cid}\n";
                    }
                }
            }
            if ($archived > 0) echo "[delete-sync] Archived {$archived} row(s)\n";
        }
    }

    usleep($POLL_SLEEP * 1000);
}
