<?php
// PHP 5.3+ compatible — uses curl.exe for HTTP, batch mutations for speed

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

// ---------------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------------
$countResult = $db->query("SELECT COUNT(*) AS cnt, MAX(ID) AS maxId FROM prod_activ");
$countRow = $countResult->fetch_assoc();
$totalRows = isset($countRow['cnt']) ? (int)$countRow['cnt'] : 0;
$maxId = isset($countRow['maxId']) ? (int)$countRow['maxId'] : 0;
echo "[convex] Target: " . $CONVEX_URL . "\n";
echo "[init] MySQL has " . $totalRows . " rows, highest ID = " . $maxId . "\n";

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

/** POST JSON to Convex via curl.exe. Returns decoded response or false. */
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

function convexMutation($baseUrl, $path, $args) {
    foreach ($args as $k => $v) {
        if ($v === null) unset($args[$k]);
    }
    return convexPost($baseUrl . '/api/mutation', array(
        'path'   => $path,
        'args'   => $args,
        'format' => 'json',
    ));
}

function convexQuery($baseUrl, $path, $args) {
    return convexPost($baseUrl . '/api/query', array(
        'path'   => $path,
        'args'   => (object)$args,
        'format' => 'json',
    ));
}

// Test connectivity
echo "[init] Testing Convex...\n";
$test = convexQuery($CONVEX_URL, 'prod_activ:listActiveMysqlIds', array());
if ($test === false) {
    fwrite(STDERR, "[fatal] Cannot reach Convex — check CONVEX_URL\n");
    exit(1);
}
$convexCount = 0;
if (is_array($test) && isset($test['value']) && is_array($test['value'])) {
    $convexCount = count($test['value']);
}
echo "[init] Convex OK (" . $convexCount . " active rows)\n";

// ---------------------------------------------------------------------------
// Batch helper: strip nulls from each row, send all at once
// ---------------------------------------------------------------------------
function batchUpsert($baseUrl, $rows) {
    if (count($rows) === 0) return true;

    $cleaned = array();
    foreach ($rows as $r) {
        $row = array();
        foreach ($r as $k => $v) {
            if ($v !== null) $row[$k] = $v;
        }
        $cleaned[] = $row;
    }

    return convexPost($baseUrl . '/api/mutation', array(
        'path'   => 'prod_activ:batchUpsert',
        'args'   => array('rows' => $cleaned),
        'format' => 'json',
    ));
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

    // --- 1. Full table sync (boot + every N polls) ---
    if (!$initialSyncDone || $pollCount % $FULL_SYNC_EVERY === 0) {
        $label  = $initialSyncDone ? 'resync' : 'init-sync';
        $result = $db->query("SELECT * FROM prod_activ ORDER BY ID ASC");

        $rows = array();
        if ($result) {
            while ($r = $result->fetch_assoc()) {
                $rows[] = $r;
            }
            $result->free();
        }

        if (count($rows) > 0) {
            $t0 = microtime(true);
            $allArgs = array();
            foreach ($rows as $r) {
                $allArgs[] = rowToArgs($r);
                $id = (int)$r['ID'];
                if ($id > $lastMaxId) $lastMaxId = $id;
            }

            echo "[" . $label . "] " . count($rows) . " row(s) — batch upsert...\n";
            $res = batchUpsert($CONVEX_URL, $allArgs);
            $elapsed = round((microtime(true) - $t0) * 1000);

            if ($res !== false) {
                $ins = 0; $upd = 0;
                if (is_array($res) && isset($res['value'])) {
                    $v = $res['value'];
                    $ins = isset($v['inserted']) ? $v['inserted'] : 0;
                    $upd = isset($v['updated']) ? $v['updated'] : 0;
                }
                $totalSynced += count($rows);
                echo "[" . $label . "] Done in " . $elapsed . "ms — " . $ins . " inserted, " . $upd . " updated\n";
            } else {
                fwrite(STDERR, "[" . $label . "] Batch upsert FAILED after " . $elapsed . "ms\n");
            }
        }
        $initialSyncDone = true;

    } else {
        // --- 2. Incremental: only new rows ---
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
            $allArgs = array();
            foreach ($rows as $r) {
                $allArgs[] = rowToArgs($r);
                $id = (int)$r['ID'];
                if ($id > $lastMaxId) $lastMaxId = $id;
            }

            echo "[sync] " . count($rows) . " new row(s) — batch upsert...\n";
            $t0 = microtime(true);
            $res = batchUpsert($CONVEX_URL, $allArgs);
            $elapsed = round((microtime(true) - $t0) * 1000);

            if ($res !== false) {
                $totalSynced += count($rows);
                echo "[sync] Done in " . $elapsed . "ms\n";
            } else {
                fwrite(STDERR, "[sync] Batch upsert FAILED, will retry\n");
            }
        }
    }

    // --- 3. Soft-delete check ---
    if ($pollCount % $DELETE_CHECK_EVERY === 0) {
        $convexIds = convexQuery($CONVEX_URL, 'prod_activ:listActiveMysqlIds', array());

        if (is_array($convexIds) && isset($convexIds['value']) && is_array($convexIds['value'])) {
            $activeInConvex = $convexIds['value'];
        } elseif (is_array($convexIds) && !isset($convexIds['value'])) {
            $activeInConvex = $convexIds;
        } else {
            $activeInConvex = null;
        }

        if (is_array($activeInConvex) && count($activeInConvex) > 0) {
            $mysqlIdResult = $db->query("SELECT ID FROM prod_activ");
            $mysqlIds = array();
            if ($mysqlIdResult) {
                while ($row = $mysqlIdResult->fetch_assoc()) {
                    $mysqlIds[(int)$row['ID']] = true;
                }
                $mysqlIdResult->free();
            }

            $toDelete = array();
            foreach ($activeInConvex as $cid) {
                $cid = (int)$cid;
                if ($cid === 0) continue;
                if (!isset($mysqlIds[$cid])) {
                    $toDelete[] = $cid;
                }
            }

            if (count($toDelete) > 0) {
                echo "[delete-sync] " . count($toDelete) . " row(s) to archive — batch...\n";
                $res = convexMutation($CONVEX_URL, 'prod_activ:batchSoftDelete', array('mysql_ids' => $toDelete));
                if ($res !== false) {
                    $cnt = 0;
                    if (is_array($res) && isset($res['value']) && isset($res['value']['archived'])) {
                        $cnt = $res['value']['archived'];
                    }
                    echo "[delete-sync] Archived " . $cnt . " row(s)\n";
                }
            }
        }
    }

    usleep($POLL_SLEEP * 1000);
}
