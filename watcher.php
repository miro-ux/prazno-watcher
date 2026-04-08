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
echo "[convex] Target: " . $CONVEX_URL . "\n";
echo "[init] MySQL has " . $totalRows . " rows, highest ID = " . $maxId . "\n";
echo "[init] Starting full sync from ID 0...\n";

// Sanity check: test Convex connectivity with a quick POST
echo "[init] Testing Convex connection...\n";
$testRaw = shell_exec('curl.exe -s -w "|||%{http_code}" -X POST -H "Content-Type: application/json" -d "{\"path\":\"prod_activ:listActiveMysqlIds\",\"args\":{},\"format\":\"json\"}" ' . escapeshellarg($CONVEX_URL . '/api/query'));
echo "[init] Raw Convex response: " . $testRaw . "\n";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function toNum($v) {
    return ($v !== null) ? (float)$v : null;
}

function nullStr($v) {
    return ($v !== null) ? (string)$v : null;
}

/** POST JSON to Convex via Windows curl.exe. Returns decoded response or false. */
function convexPost($url, $payload) {
    static $debugFirst = true;

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
         . '--max-time 15 '
         . '-w "|||%{http_code}" '
         . escapeshellarg($url);

    // Log first call for debugging
    if ($debugFirst) {
        echo "[debug] curl cmd: " . $cmd . "\n";
        echo "[debug] body: " . substr($body, 0, 300) . "\n";
        $debugFirst = false;
    }

    $raw = shell_exec($cmd);
    @unlink($tmpIn);

    if ($raw === null) $raw = '';

    // Split response body from http code using ||| delimiter
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

    // Check for Convex-level errors inside the JSON body
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
        'args'   => $args,
        'format' => 'json',
    ));
}

// ---------------------------------------------------------------------------
// Poll loop
// ---------------------------------------------------------------------------
set_time_limit(0);

$totalSynced        = 0;
$pollCount          = 0;
$FULL_SYNC_EVERY    = (int) env('FULL_SYNC_EVERY', '10');     // full table re-sync every N polls
$DELETE_CHECK_EVERY = (int) env('DELETE_CHECK_EVERY', '10');   // check deletes every N polls
$initialSyncDone    = false;

// Build payload from a MySQL row
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

while (true) {
    $pollCount++;

    // --- 1. Full table sync (initial boot + every N polls to catch updates) ---
    if (!$initialSyncDone || $pollCount % $FULL_SYNC_EVERY === 0) {
        $label = $initialSyncDone ? 'resync' : 'init-sync';
        $result = $db->query("SELECT * FROM prod_activ ORDER BY ID ASC");

        $rows = array();
        if ($result) {
            while ($r = $result->fetch_assoc()) {
                $rows[] = $r;
            }
            $result->free();
        }

        if (count($rows) > 0) {
            echo "[" . $label . "] " . count($rows) . " row(s) to upsert\n";
            foreach ($rows as $r) {
                $id   = (int)$r['ID'];
                $args = rowToArgs($r);
                if (convexMutation($CONVEX_URL, 'prod_activ:upsert', $args)) {
                    $totalSynced++;
                    if ($id > $lastMaxId) $lastMaxId = $id;
                } else {
                    fwrite(STDERR, "  [error] failed to upsert ID " . $id . "\n");
                }
            }
            echo "[" . $label . "] Done (" . count($rows) . " rows, lastMaxId = " . $lastMaxId . ")\n";
        }
        $initialSyncDone = true;

    } else {
        // --- 2. Incremental: only new rows since last seen ID ---
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
            echo "[sync] " . count($rows) . " new row(s)\n";
            foreach ($rows as $r) {
                $id   = (int)$r['ID'];
                $args = rowToArgs($r);
                if (convexMutation($CONVEX_URL, 'prod_activ:upsert', $args)) {
                    $totalSynced++;
                    echo "  -> upserted ID " . $id . "\n";
                    if ($id > $lastMaxId) $lastMaxId = $id;
                } else {
                    fwrite(STDERR, "  [error] failed to upsert ID " . $id . ", will retry next poll\n");
                }
            }
        }
    }

    // --- 3. Soft-delete: check every N polls for rows deleted from MySQL ---
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

            $archiveCount = 0;
            foreach ($activeInConvex as $cid) {
                $cid = (int)$cid;
                if ($cid === 0) continue; // skip legacy zero-ID rows
                if (!isset($mysqlIds[$cid])) {
                    $res = convexMutation($CONVEX_URL, 'prod_activ:softDelete', array('mysql_id' => $cid));
                    if ($res !== false) {
                        $archiveCount++;
                        echo "  [archive] soft-deleted mysql_id " . $cid . "\n";
                    }
                }
            }
            if ($archiveCount > 0) {
                echo "[delete-sync] Archived " . $archiveCount . " row(s) not found in MySQL\n";
            }
        }
    }

    usleep($POLL_SLEEP * 1000);
}
