<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Load .env.local (same file as watcher.js uses, no Composer needed)
// ---------------------------------------------------------------------------
$envFile = __DIR__ . '/.env.local';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim(explode('#', $val, 2)[0]); // strip inline comments
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
$CONVEX_URL   = env('CONVEX_URL', 'https://youthful-cobra-798.convex.cloud');
$POLL_SLEEP   = (int) env('POLL_INTERVAL_MS', '3000');   // milliseconds
$MYSQL_HOST   = env('MYSQL_HOST',     '192.168.0.21');
$MYSQL_USER   = env('MYSQL_USER',     'ivan');
$MYSQL_PASS   = env('MYSQL_PASSWORD', 'sotazero');
$MYSQL_DB     = env('MYSQL_DATABASE', 'prazno');

// ---------------------------------------------------------------------------
// MySQL connection (uses libmysqlclient — no auth-plugin issues)
// ---------------------------------------------------------------------------
$db = new mysqli($MYSQL_HOST, $MYSQL_USER, $MYSQL_PASS, $MYSQL_DB);
if ($db->connect_errno) {
    fwrite(STDERR, "[fatal] MySQL connect failed: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');

// ---------------------------------------------------------------------------
// Init: start from current max ID so we don't replay history on startup
// ---------------------------------------------------------------------------
$row = $db->query("SELECT MAX(ID) AS maxId FROM prod_activ")->fetch_assoc();
$lastMaxId = (int)($row['maxId'] ?? 0);
echo "[init] Starting from ID > {$lastMaxId}\n";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function num(?string $v): ?float {
    return $v !== null ? (float)$v : null;
}

/** POST a mutation to Convex and return true on success. */
function convexUpsert(string $baseUrl, array $args): bool {
    // Strip null values — Convex optional fields must be absent, not null
    $args = array_filter($args, fn($v) => $v !== null);

    $body = json_encode([
        'path' => 'prod_activ:upsert',
        'args' => $args,
        'format' => 'json',
    ], JSON_THROW_ON_ERROR);

    $ch = curl_init("{$baseUrl}/api/mutation");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);

    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        fwrite(STDERR, "  [curl error] {$curlErr}\n");
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        fwrite(STDERR, "  [http {$httpCode}] {$resp}\n");
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
    fwrite(STDERR, "[fatal] Prepare failed: {$db->error}\n");
    exit(1);
}

while (true) {
    $stmt->bind_param('i', $lastMaxId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows   = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();

    if (count($rows) > 0) {
        echo "[sync] " . count($rows) . " new row(s) found\n";

        foreach ($rows as $r) {
            $id   = (int)$r['ID'];
            $args = [
                'mysql_id'   => $id,
                'Stoka'      => $r['Stoka']      !== null ? (string)$r['Stoka']     : null,
                'Br'         => $r['Br']         !== null ? num($r['Br'])            : null,
                'Cena'       => $r['Cena']       !== null ? num($r['Cena'])          : null,
                'masa'       => $r['masa']       !== null ? num($r['masa'])          : null,
                'Miasto'     => (string)($r['Miasto'] ?? ''),
                'Servitior'  => $r['Servitior']  !== null ? (string)$r['Servitior'] : null,
                'SmetkaN'    => $r['SmetkaN']    !== null ? num($r['SmetkaN'])       : null,
                'Data'       => $r['Data']       !== null ? (string)$r['Data']      : null,
                'Time'       => $r['Time']       !== null ? (string)$r['Time']      : null,
                'Time2'      => $r['Time2']      !== null ? (string)$r['Time2']     : null,
                'Zaiavka'    => $r['Zaiavka']    !== null ? (string)$r['Zaiavka']   : null,
                'Status'     => $r['Status']     !== null ? num($r['Status'])        : null,
                'Platena'    => $r['Platena']    !== null ? num($r['Platena'])       : null,
                'Otchetena'  => $r['Otchetena']  !== null ? num($r['Otchetena'])    : null,
                'Porcent'    => $r['Porcent']    !== null ? num($r['Porcent'])       : null,
                'IDNap'      => $r['IDNap']      !== null ? num($r['IDNap'])         : null,
                'ID_Stoca'   => $r['ID_Stoca']   !== null ? num($r['ID_Stoca'])      : null,
                'Suplimente' => $r['Suplimente'] !== null ? num($r['Suplimente'])    : null,
            ];

            if (convexUpsert($CONVEX_URL, $args)) {
                echo "  → upserted ID {$id}\n";
                if ($id > $lastMaxId) $lastMaxId = $id;
            } else {
                fwrite(STDERR, "  [error] failed to upsert ID {$id}, will retry next poll\n");
            }
        }
    }

    usleep($POLL_SLEEP * 1000);
}
