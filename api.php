<?php
// Founders Walk API: one JSON endpoint for attendees and the host.
// Attendee actions use a per-person token; host actions need the X-Host-Pin header.
declare(strict_types=1);

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"ok":false,"error":"Setup isn\'t finished: copy config.sample.php to config.php."}';
    exit;
}
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

const ROLES = ['Founder', 'Operator', 'Investor', 'Creator', 'Other'];

// ---------- helpers ----------

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    out(['ok' => false, 'error' => $msg], $code);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = dirname(FW_DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $pdo = new PDO('sqlite:' . FW_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS walks (
        id INTEGER PRIMARY KEY,
        code TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        place TEXT NOT NULL DEFAULT \'\',
        walk_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'open\',
        rounds TEXT NOT NULL DEFAULT \'[]\',
        late TEXT NOT NULL DEFAULT \'[]\',
        created_at INTEGER NOT NULL,
        ended_at INTEGER
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS people (
        id INTEGER PRIMARY KEY,
        walk_id INTEGER NOT NULL REFERENCES walks(id) ON DELETE CASCADE,
        token TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        oneliner TEXT NOT NULL DEFAULT \'\',
        role TEXT NOT NULL DEFAULT \'\',
        link TEXT NOT NULL DEFAULT \'\',
        email TEXT NOT NULL DEFAULT \'\',
        progress TEXT NOT NULL DEFAULT \'{}\',
        joined_at INTEGER NOT NULL,
        done_at INTEGER,
        emailed_at INTEGER
    )');
    // Added for sign-up-before / check-in-on-the-day.
    $cols = fn($t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    if (!in_array('checkin_key', $cols('walks'), true)) $pdo->exec("ALTER TABLE walks ADD COLUMN checkin_key TEXT NOT NULL DEFAULT ''");
    if (!in_array('checked_in_at', $cols('people'), true)) $pdo->exec('ALTER TABLE people ADD COLUMN checked_in_at INTEGER');
    if (!in_array('gen', $cols('walks'), true)) $pdo->exec('ALTER TABLE walks ADD COLUMN gen INTEGER NOT NULL DEFAULT 0');
    if (!in_array('business', $cols('people'), true)) $pdo->exec("ALTER TABLE people ADD COLUMN business TEXT NOT NULL DEFAULT ''");
    if (!in_array('listed', $cols('people'), true)) $pdo->exec('ALTER TABLE people ADD COLUMN listed INTEGER NOT NULL DEFAULT 0');
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function input(): array
{
    static $in = null;
    if ($in === null) {
        $raw = file_get_contents('php://input', false, null, 0, 64 * 1024) ?: '';
        $in = json_decode($raw, true);
        if (!is_array($in)) $in = [];
    }
    return $in;
}

function param(string $key): string
{
    $v = input()[$key] ?? $_GET[$key] ?? '';
    return is_scalar($v) ? (string)$v : '';
}

function clean(string $s, int $max): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

function cleanLink(string $s): string
{
    $s = clean($s, 200);
    if ($s === '') return '';
    if (!preg_match('~^https?://~i', $s)) $s = 'https://' . $s;
    $parts = parse_url($s);
    if (!$parts || empty($parts['host']) || !str_contains($parts['host'], '.')) return '';
    return filter_var($s, FILTER_VALIDATE_URL) ? $s : '';
}

function cleanEmail(string $s): string
{
    $s = clean($s, 120);
    return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : '';
}

function isHost(): bool
{
    $pin = $_SERVER['HTTP_X_HOST_PIN'] ?? '';
    if (FW_HOST_PIN === 'change-me' || strlen(FW_HOST_PIN) < 6) {
        fail('Set FW_HOST_PIN in config.php before using the host screen.', 503);
    }
    return is_string($pin) && hash_equals(FW_HOST_PIN, $pin);
}

function requireHost(): void
{
    if (!isHost()) {
        usleep(800000); // slow down PIN guessing
        fail('That PIN is not right.', 401);
    }
    purgeOld();
}

function purgeOld(): void
{
    q('DELETE FROM walks WHERE created_at < ?', [time() - FW_RETENTION_DAYS * 86400]);
}

function walkByCode(string $code): array
{
    $code = strtoupper(clean($code, 8));
    $w = q('SELECT * FROM walks WHERE code = ?', [$code])->fetch();
    if (!$w) fail('We could not find a walk with that code. Check the code on the host\'s screen.', 404);
    $w['rounds'] = json_decode($w['rounds'], true) ?: [];
    $w['late'] = json_decode($w['late'], true) ?: [];
    if ($w['checkin_key'] === '') {
        $w['checkin_key'] = newKey();
        q('UPDATE walks SET checkin_key = ? WHERE id = ?', [$w['checkin_key'], $w['id']]);
    }
    return $w;
}

/** The secret in the QR code on the day: scanning it is what proves someone is at the walk. */
function newKey(): string
{
    return bin2hex(random_bytes(4));
}

function keyMatches(array $walk, string $key): bool
{
    return $key !== '' && hash_equals($walk['checkin_key'], clean($key, 32));
}

/** Mark someone as arrived; if matching has already started, slot them into a pair. */
function checkIn(array &$walk, array $person): void
{
    if ($person['checked_in_at']) return;
    q('UPDATE people SET checked_in_at = ? WHERE id = ?', [time(), $person['id']]);
    if ($walk['status'] === 'matched' && $walk['rounds']) {
        assignLate($walk, (int)$person['id']);
        saveRounds($walk);
    }
}

function personByToken(array $walk, string $token): array
{
    $p = q('SELECT * FROM people WHERE walk_id = ? AND token = ?', [$walk['id'], clean($token, 64)])->fetch();
    if (!$p) fail('We could not find you on this walk. Join again to get matched.', 404);
    return $p;
}

function walkInfo(array $w): array
{
    return [
        'code' => $w['code'],
        'title' => $w['title'],
        'place' => $w['place'],
        'date' => $w['walk_date'],
        'status' => $w['status'],
        'rounds' => count($w['rounds']),
        'nextWalkUrl' => FW_NEXT_WALK_URL,
        'mailOn' => FW_MAIL_MODE === 'mail',
        // Goes up each time the host reshuffles or restarts, so phones drop their old progress.
        'gen' => (int)($w['gen'] ?? 0),
    ];
}

function publicPerson(array $p): array
{
    return ['id' => (int)$p['id'], 'name' => $p['name'], 'role' => $p['role'], 'business' => $p['business'] ?? '', 'oneliner' => $p['oneliner'], 'link' => $p['link']];
}

function peopleById(int $walkId): array
{
    $rows = q('SELECT * FROM people WHERE walk_id = ? ORDER BY joined_at, id', [$walkId])->fetchAll();
    $by = [];
    foreach ($rows as $r) $by[(int)$r['id']] = $r;
    return $by;
}

function counts(int $walkId): array
{
    $r = q('SELECT COUNT(*) AS signed, COUNT(checked_in_at) AS here FROM people WHERE walk_id = ?', [$walkId])->fetch();
    return ['count' => (int)$r['here'], 'signedUp' => (int)$r['signed']];
}

/** For one person: who they walk with in each round. */
function matchesFor(array $walk, int $personId, array $byId): array
{
    $out = [];
    foreach ($walk['rounds'] as $i => $groups) {
        foreach ($groups as $g) {
            if (!in_array($personId, $g, true)) continue;
            $others = [];
            foreach ($g as $id) {
                if ($id !== $personId && isset($byId[$id])) $others[] = publicPerson($byId[$id]);
            }
            $out[] = ['round' => $i + 1, 'people' => $others];
        }
    }
    return $out;
}

function saveRounds(array $walk): void
{
    q('UPDATE walks SET rounds = ?, late = ? WHERE id = ?', [
        json_encode(array_values($walk['rounds'])),
        json_encode(array_values($walk['late'])),
        $walk['id'],
    ]);
}

// ---------- matching ----------

function shuffled(array $a): array
{
    for ($i = count($a) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
    }
    return $a;
}

/** Pairs in order; an odd one out joins the last pair as a three. */
function groupUp(array $ids): array
{
    $groups = [];
    $n = count($ids);
    for ($i = 0; $i + 1 < $n; $i += 2) $groups[] = [$ids[$i], $ids[$i + 1]];
    if ($n % 2 === 1) {
        if ($groups) $groups[count($groups) - 1][] = $ids[$n - 1];
        else $groups[] = [$ids[$n - 1]];
    }
    return $groups;
}

function pairKeys(array $groups): array
{
    $keys = [];
    foreach ($groups as $g) {
        foreach ($g as $a) foreach ($g as $b) if ($a < $b) $keys["$a-$b"] = true;
    }
    return $keys;
}

/** Round 1, then (with 4+ people) a round 2 that repeats as few pairs as possible. */
function makeRounds(array $ids): array
{
    $n = count($ids);
    if ($n < 2) return [];
    $r1 = groupUp(shuffled($ids));
    if ($n < 4) return [$r1];
    $seen = pairKeys($r1);
    $best = null;
    $bestOverlap = PHP_INT_MAX;
    for ($t = 0; $t < 500 && $bestOverlap > 0; $t++) {
        $g = groupUp(shuffled($ids));
        $overlap = count(array_intersect_key(pairKeys($g), $seen));
        if ($overlap < $bestOverlap) [$best, $bestOverlap] = [$g, $overlap];
    }
    return [$r1, $best];
}

/** Someone joined after matching: pair them with an earlier latecomer, or make a three. */
function assignLate(array &$walk, int $newId): void
{
    $met = [];
    foreach ($walk['rounds'] as $r => &$groups) {
        $placed = false;
        // Keep groups at 2-3: someone from a three splits off and pairs with the new person.
        // Earlier latecomers go first, so the original pairs stay together where possible.
        foreach ([true, false] as $latecomersOnly) {
            foreach ($groups as $gi => $g) {
                if (count($g) < 3) continue;
                foreach ($g as $lid) {
                    if ($latecomersOnly && !in_array($lid, $walk['late'], true)) continue;
                    if (in_array($lid, $met, true)) continue;
                    $groups[$gi] = array_values(array_diff($g, [$lid]));
                    $groups[] = [$lid, $newId];
                    $met[] = $lid;
                    $placed = true;
                    break 3;
                }
            }
        }
        if (!$placed) {
            $options = [];
            foreach ($groups as $gi => $g) {
                if (!array_intersect($g, $met)) $options[$gi] = count($g);
            }
            if (!$options) foreach ($groups as $gi => $g) $options[$gi] = count($g);
            $min = min($options);
            $smallest = array_keys(array_filter($options, fn($c) => $c === $min));
            $gi = $smallest[random_int(0, count($smallest) - 1)];
            $met = array_merge($met, $groups[$gi]);
            $groups[$gi][] = $newId;
        }
    }
    unset($groups);
    $walk['late'][] = $newId;
}

/** Someone was removed: take them out of every round, and re-home anyone left alone. */
function removeFromRounds(array &$walk, int $id): void
{
    foreach ($walk['rounds'] as &$groups) {
        $groups = array_values(array_filter(array_map(
            fn($g) => array_values(array_diff($g, [$id])),
            $groups
        ), fn($g) => count($g) > 0));
        foreach ($groups as $gi => $g) {
            if (count($g) !== 1 || count($groups) < 2) continue;
            $lone = $g[0];
            unset($groups[$gi]);
            $groups = array_values($groups);
            $sizes = array_map('count', $groups);
            $target = array_search(min($sizes), $sizes, true);
            $groups[$target][] = $lone;
            break;
        }
    }
    unset($groups);
    $walk['rounds'] = array_values(array_filter($walk['rounds'], fn($groups) => count($groups) > 0));
    $walk['late'] = array_values(array_diff($walk['late'], [$id]));
}

// ---------- progress & email ----------

function cleanProgress($p): array
{
    $out = ['rounds' => [], 'done' => false];
    if (!is_array($p)) return $out;
    $out['done'] = !empty($p['done']);
    foreach ((array)($p['rounds'] ?? []) as $r => $round) {
        if (!in_array((string)$r, ['1', '2'], true) || !is_array($round)) continue;
        $state = in_array($round['state'] ?? '', ['met', 'skipped'], true) ? $round['state'] : '';
        $cards = [];
        foreach (array_slice((array)($round['cards'] ?? []), 0, 3) as $c) {
            if (!is_array($c)) continue;
            $cards[] = [
                'cat' => clean((string)($c['cat'] ?? ''), 20),
                'i' => (int)($c['i'] ?? 0),
                'label' => clean((string)($c['label'] ?? ''), 30),
                'text' => clean((string)($c['text'] ?? ''), 200),
            ];
        }
        $out['rounds'][(string)$r] = ['state' => $state, 'cards' => $cards];
    }
    return $out;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function prettyDate(string $ymd): string
{
    $t = strtotime($ymd);
    return $t ? date('j F Y', $t) : $ymd;
}

function sendMail(string $to, string $subject, string $html, string $text): bool
{
    $to = cleanEmail($to);
    if ($to === '') return false;
    $subject = clean($subject, 150);
    $boundary = 'fw' . bin2hex(random_bytes(8));
    $fromName = '=?UTF-8?B?' . base64_encode(FW_FROM_NAME) . '?=';
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'From: ' . $fromName . ' <' . FW_FROM_ADDRESS . '>',
        'Reply-To: ' . FW_HOST_EMAIL,
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ]);
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
        . $text . "\r\n--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
        . $html . "\r\n--$boundary--\r\n";
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    if (FW_MAIL_MODE === 'log') {
        $dir = dirname(FW_DB_PATH) . '/outbox';
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
        return (bool)file_put_contents($file, "To: $to\r\nSubject: $subject\r\n$headers\r\n\r\n$body");
    }
    return mail($to, $encodedSubject, $body, $headers, '-f' . FW_FROM_ADDRESS);
}

function emailShell(string $inner): string
{
    return '<!doctype html><html><body style="margin:0;background:#f3efe6;padding:24px 12px;font-family:Helvetica,Arial,sans-serif;color:#16140f">'
        . '<div style="max-width:560px;margin:0 auto;background:#fafaf7;border:1px solid #e6e2d9;border-radius:12px;padding:28px 24px">'
        . '<div style="font-family:Menlo,monospace;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#8a8478">Uncommon Collective</div>'
        . $inner
        . '</div></body></html>';
}

function sendSummary(array $walk, array $person, array $byId): bool
{
    $progress = cleanProgress(json_decode($person['progress'], true));
    $matches = matchesFor($walk, (int)$person['id'], $byId);
    $date = prettyDate($walk['walk_date']);
    $rows = '';
    $text = "Hi {$person['name']},\n\nThanks for walking with us on $date. Here's who you were matched with, so you can stay in touch.\n";
    foreach ($matches as $m) {
        $r = (string)$m['round'];
        $state = $progress['rounds'][$r]['state'] ?? '';
        $label = $state === 'met' ? 'You met' : ($state === 'skipped' ? 'Skipped, still worth a hello' : 'Matched');
        $rows .= '<div style="margin-top:22px;padding-top:18px;border-top:1px solid #e6e2d9">'
            . '<div style="font-family:Menlo,monospace;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#8a8478">Match ' . $r . ' · ' . e($label) . '</div>';
        $text .= "\nMatch $r ($label)\n";
        foreach ($m['people'] as $p) {
            $rows .= '<p style="margin:10px 0 0;font-size:16px"><strong style="font-family:Georgia,serif;font-size:19px">' . e($p['name']) . '</strong>'
                . ($p['role'] ? ' <span style="color:#57534a">· ' . e($p['role']) . '</span>' : '')
                . ($p['business'] ? ' <span style="color:#57534a">· ' . e($p['business']) . '</span>' : '')
                . ($p['oneliner'] ? '<br><span style="color:#34301f">' . e($p['oneliner']) . '</span>' : '')
                . ($p['link'] ? '<br><a href="' . e($p['link']) . '" style="color:#3b48a8">' . e(preg_replace('~^https?://(www\.)?~i', '', $p['link'])) . '</a>' : '')
                . '</p>';
            $text .= "- {$p['name']}" . ($p['role'] ? " ({$p['role']})" : '') . ($p['business'] ? ", {$p['business']}" : '') . ($p['oneliner'] ? ": {$p['oneliner']}" : '') . ($p['link'] ? "\n  LinkedIn: {$p['link']}" : '') . "\n";
        }
        $cards = $progress['rounds'][$r]['cards'] ?? [];
        if ($cards) {
            $rows .= '<p style="margin:14px 0 4px;font-size:13px;color:#57534a">Questions you had:</p><ul style="margin:0;padding-left:18px;color:#34301f;font-size:14px;line-height:1.5">';
            $text .= "  Questions you had:\n";
            foreach ($cards as $c) {
                $rows .= '<li>' . e($c['text']) . '</li>';
                $text .= "  * {$c['text']}\n";
            }
            $rows .= '</ul>';
        }
        $rows .= '</div>';
    }
    if (!$matches) {
        $rows = '<p style="margin-top:18px">You joined after matching wrapped up, so there were no matches this time. We\'d love to see you at the next one.</p>';
        $text .= "\nYou joined after matching wrapped up, so there were no matches this time.\n";
    }
    $html = emailShell(
        '<h1 style="font-family:Georgia,serif;font-weight:500;font-size:28px;margin:10px 0 6px">Good walk, ' . e($person['name']) . '.</h1>'
        . '<p style="margin:0;color:#57534a;font-size:15px;line-height:1.5">Thanks for joining ' . e($walk['title']) . ' on ' . e($date) . '. Here\'s who you were matched with, so you can stay in touch.</p>'
        . $rows
        . '<p style="margin-top:26px;font-size:15px">Thanks for choosing Founders Walk.</p>'
        . '<p style="margin-top:22px;font-size:12px;color:#8a8478;line-height:1.5">You got this because you joined Founders Walk and left your email. We delete walk details after ' . FW_RETENTION_DAYS . ' days.</p>'
    );
    $text .= "\nThanks for choosing Founders Walk.\n\nWe delete walk details after " . FW_RETENTION_DAYS . " days.\n";
    return sendMail($person['email'], "Your Founders Walk matches, $date", $html, $text);
}

function sendRollup(array $walk, array $byId): bool
{
    $date = prettyDate($walk['walk_date']);
    $rows = '';
    $text = "{$walk['title']} on $date: " . count($byId) . " people\n\n";
    foreach ($byId as $id => $p) {
        $progress = cleanProgress(json_decode($p['progress'], true));
        $bits = [];
        if (!$p['checked_in_at']) $bits[] = 'Signed up, didn\'t check in';
        foreach (matchesFor($walk, (int)$id, $byId) as $m) {
            $state = $progress['rounds'][(string)$m['round']]['state'] ?? '';
            $mark = $state === 'met' ? 'met' : ($state === 'skipped' ? 'skipped' : 'not marked');
            $bits[] = 'Match ' . $m['round'] . ': ' . implode(' & ', array_column($m['people'], 'name')) . " ($mark)";
        }
        $rows .= '<tr><td style="padding:8px 6px;border-top:1px solid #e6e2d9;vertical-align:top"><strong>' . e($p['name']) . '</strong><br><span style="color:#57534a">' . e($p['role']) . '</span></td>'
            . '<td style="padding:8px 6px;border-top:1px solid #e6e2d9;vertical-align:top">' . e(trim($p['business'] . ' · ' . $p['oneliner'], ' ·'))
            . ($p['link'] ? '<br><a href="' . e($p['link']) . '" style="color:#3b48a8">' . e($p['link']) . '</a>' : '')
            . ($p['email'] ? '<br>' . e($p['email']) : '') . '</td>'
            . '<td style="padding:8px 6px;border-top:1px solid #e6e2d9;vertical-align:top;color:#34301f">' . e(implode("\n", $bits)) . '</td></tr>';
        $text .= "{$p['name']} ({$p['role']}) {$p['business']} {$p['oneliner']} {$p['link']} {$p['email']}\n  " . implode("\n  ", $bits) . "\n";
    }
    $html = emailShell(
        '<h1 style="font-family:Georgia,serif;font-weight:500;font-size:26px;margin:10px 0 6px">' . e($walk['title']) . ' roll-up</h1>'
        . '<p style="margin:0 0 14px;color:#57534a">' . e($date) . ($walk['place'] ? ' · ' . e($walk['place']) : '') . ' · ' . count($byId) . ' people</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:13px;line-height:1.45;white-space:pre-line">' . $rows . '</table>'
    );
    return sendMail(FW_HOST_EMAIL, "Founders Walk roll-up, $date (" . count($byId) . ' people)', $html, $text);
}

// ---------- routes ----------

$a = $_GET['a'] ?? '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

try {
    switch ($a) {
        // ----- attendee -----
        case 'walk': {
            $w = walkByCode(param('code'));
            out(['ok' => true, 'walk' => walkInfo($w)] + counts((int)$w['id']));
        }

        case 'join': {
            if (!$isPost) fail('Use POST.', 405);
            $w = walkByCode(param('code'));
            if ($w['status'] === 'ended') fail('This walk has finished. See you at the next one.', 409);
            $name = clean(param('name'), 60);
            if ($name === '') fail('Add your name so your match can find you.');
            if (empty(input()['consent'])) fail('Tick the box so we can share your details with your matches.');
            $role = in_array(param('role'), ROLES, true) ? param('role') : '';
            $email = param('email') === '' ? '' : cleanEmail(param('email'));
            if (param('email') !== '' && $email === '') fail('That email doesn\'t look right. Check it, or leave it blank.');
            $details = [$name, clean(param('business'), 60), clean(param('oneliner'), 90), $role, cleanLink(param('link')), empty(input()['listed']) ? 0 : 1];
            // Signed up before on another phone or browser? Pick up that sign-up instead of making a second one.
            $existing = $email === '' ? null
                : q('SELECT * FROM people WHERE walk_id = ? AND lower(email) = lower(?)', [$w['id'], $email])->fetch();
            if ($existing) {
                q('UPDATE people SET name = ?, business = ?, oneliner = ?, role = ?, link = ?, listed = ? WHERE id = ?', [...$details, $existing['id']]);
                $person = $existing;
            } else {
                q('INSERT INTO people (walk_id, token, name, business, oneliner, role, link, listed, email, joined_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$w['id'], bin2hex(random_bytes(16)), ...$details, $email, time()]);
                $person = q('SELECT * FROM people WHERE id = ?', [db()->lastInsertId()])->fetch();
            }
            if (keyMatches($w, param('key'))) checkIn($w, $person);
            out(['ok' => true, 'token' => $person['token'], 'returning' => (bool)$existing]);
        }

        case 'checkin': {
            if (!$isPost) fail('Use POST.', 405);
            $w = walkByCode(param('code'));
            if ($w['status'] === 'ended') fail('This walk has finished. See you at the next one.', 409);
            $p = personByToken($w, param('token'));
            if (!keyMatches($w, param('key'))) fail('Scan the QR code on the host\'s phone to check in.', 403);
            checkIn($w, $p);
            out(['ok' => true]);
        }

        case 'me': {
            $w = walkByCode(param('code'));
            $p = personByToken($w, param('token'));
            $byId = peopleById((int)$w['id']);
            out([
                'ok' => true,
                'walk' => walkInfo($w),
                ...counts((int)$w['id']),
                'me' => publicPerson($p) + ['email' => $p['email'], 'emailed' => (bool)$p['emailed_at'], 'checkedIn' => (bool)$p['checked_in_at'], 'listed' => (bool)$p['listed']],
                'matches' => $w['status'] === 'open' || !$p['checked_in_at'] ? [] : matchesFor($w, (int)$p['id'], $byId),
                // The lobby: who else is here, shown only to people who are here, before matching
                'lobby' => $w['status'] !== 'open' || !$p['checked_in_at'] ? [] : array_values(array_map(
                    fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'role' => $x['role']],
                    array_filter($byId, fn($x) => $x['checked_in_at']))),
                // Everyone at the walk who opted in to the attendee list, once matching has started
                'attendees' => $w['status'] === 'open' || !$p['checked_in_at'] ? [] : array_values(array_map('publicPerson',
                    array_filter($byId, fn($x) => $x['checked_in_at'] && $x['listed'] && (int)$x['id'] !== (int)$p['id']))),
                'progress' => cleanProgress(json_decode($p['progress'], true)),
            ]);
        }

        case 'listme': {
            if (!$isPost) fail('Use POST.', 405);
            $w = walkByCode(param('code'));
            $p = personByToken($w, param('token'));
            q('UPDATE people SET listed = ? WHERE id = ?', [empty(input()['listed']) ? 0 : 1, $p['id']]);
            out(['ok' => true]);
        }

        case 'progress': {
            if (!$isPost) fail('Use POST.', 405);
            $w = walkByCode(param('code'));
            $p = personByToken($w, param('token'));
            $progress = cleanProgress(input()['progress'] ?? []);
            $email = $p['email'];
            if (param('email') !== '') {
                $email = cleanEmail(param('email'));
                if ($email === '') fail('That email doesn\'t look right.');
            }
            $doneAt = $p['done_at'] ?: ($progress['done'] ? time() : null);
            q('UPDATE people SET progress = ?, email = ?, done_at = ? WHERE id = ?', [json_encode($progress), $email, $doneAt, $p['id']]);
            $emailed = (bool)$p['emailed_at'];
            if ($progress['done'] && $email !== '' && !$emailed) {
                $p = q('SELECT * FROM people WHERE id = ?', [$p['id']])->fetch();
                if (sendSummary($w, $p, peopleById((int)$w['id']))) {
                    q('UPDATE people SET emailed_at = ? WHERE id = ?', [time(), $p['id']]);
                    $emailed = true;
                }
            }
            out(['ok' => true, 'emailed' => $emailed]);
        }

        // ----- host -----
        case 'walks': {
            requireHost();
            $rows = q('SELECT w.code, w.title, w.place, w.walk_date AS date, w.status, (SELECT COUNT(*) FROM people p WHERE p.walk_id = w.id) AS count, (SELECT COUNT(checked_in_at) FROM people p WHERE p.walk_id = w.id) AS here
                       FROM walks w ORDER BY w.created_at DESC LIMIT 20')->fetchAll();
            out(['ok' => true, 'walks' => $rows]);
        }

        case 'create': {
            if (!$isPost) fail('Use POST.', 405);
            requireHost();
            $title = clean(param('title'), 60) ?: 'Founders Walk';
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', param('date')) ? param('date') : date('Y-m-d');
            $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ';
            do {
                $code = '';
                for ($i = 0; $i < 4; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            } while (q('SELECT 1 FROM walks WHERE code = ?', [$code])->fetch());
            q('INSERT INTO walks (code, title, place, walk_date, created_at, checkin_key) VALUES (?,?,?,?,?,?)', [$code, $title, clean(param('place'), 60), $date, time(), newKey()]);
            out(['ok' => true, 'code' => $code]);
        }

        case 'host': {
            requireHost();
            $w = walkByCode(param('code'));
            $byId = peopleById((int)$w['id']);
            $people = [];
            foreach ($byId as $p) {
                $people[] = publicPerson($p) + [
                    'email' => $p['email'],
                    'done' => (bool)$p['done_at'],
                    'emailed' => (bool)$p['emailed_at'],
                    'progress' => cleanProgress(json_decode($p['progress'], true)),
                    'late' => in_array((int)$p['id'], $w['late'], true),
                    'checkedIn' => (bool)$p['checked_in_at'],
                ];
            }
            out(['ok' => true, 'walk' => walkInfo($w) + ['key' => $w['checkin_key']], 'people' => $people, 'groups' => $w['rounds']]);
        }

        case 'host_checkin': {
            if (!$isPost) fail('Use POST.', 405);
            requireHost();
            $w = walkByCode(param('code'));
            $p = q('SELECT * FROM people WHERE id = ? AND walk_id = ?', [(int)param('id'), $w['id']])->fetch();
            if (!$p) fail('That person isn\'t on this walk any more.', 404);
            checkIn($w, $p);
            out(['ok' => true]);
        }

        case 'start':
        case 'reshuffle': {
            if (!$isPost) fail('Use POST.', 405);
            requireHost();
            $w = walkByCode(param('code'));
            if ($w['status'] === 'ended') fail('This walk has already ended.', 409);
            $ids = array_map('intval', q('SELECT id FROM people WHERE walk_id = ? AND checked_in_at IS NOT NULL ORDER BY id', [$w['id']])->fetchAll(PDO::FETCH_COLUMN));
            if (count($ids) < 2) fail('Matching needs at least 2 people checked in.');
            $w['rounds'] = makeRounds($ids);
            $w['late'] = [];
            saveRounds($w);
            q('UPDATE walks SET status = \'matched\' WHERE id = ?', [$w['id']]);
            // A fresh shuffle means fresh progress.
            if ($a === 'reshuffle') {
                q('UPDATE people SET progress = \'{}\', done_at = NULL WHERE walk_id = ? AND emailed_at IS NULL', [$w['id']]);
                q('UPDATE walks SET gen = gen + 1 WHERE id = ?', [$w['id']]);
            }
            out(['ok' => true]);
        }

        case 'restart':   // back to the lobby: matches cleared, everyone stays checked in
        case 'clear': {   // start over: everyone removed, same code and QR
            if (!$isPost) fail('Use POST.', 405);
            requireHost();
            $w = walkByCode(param('code'));
            if ($a === 'clear') q('DELETE FROM people WHERE walk_id = ?', [$w['id']]);
            else q('UPDATE people SET progress = \'{}\', done_at = NULL, emailed_at = NULL WHERE walk_id = ?', [$w['id']]);
            q('UPDATE walks SET status = \'open\', rounds = \'[]\', late = \'[]\', ended_at = NULL, gen = gen + 1 WHERE id = ?', [$w['id']]);
            out(['ok' => true]);
        }

        case 'remove': {
            if (!$isPost) fail('Use POST.', 405);
            requireHost();
            $w = walkByCode(param('code'));
            $id = (int)param('id');
            q('DELETE FROM people WHERE id = ? AND walk_id = ?', [$id, $w['id']]);
            removeFromRounds($w, $id);
            saveRounds($w);
            out(['ok' => true]);
        }

        case 'end': {
            if (!$isPost) fail('Use POST.', 405);
            requireHost();
            $w = walkByCode(param('code'));
            q('UPDATE walks SET status = \'ended\', ended_at = ? WHERE id = ?', [time(), $w['id']]);
            $w['status'] = 'ended';
            $byId = peopleById((int)$w['id']);
            $sent = 0;
            foreach ($byId as $p) {
                if ($p['email'] === '' || $p['emailed_at'] || !$p['checked_in_at']) continue;
                if (sendSummary($w, $p, $byId)) {
                    q('UPDATE people SET emailed_at = ? WHERE id = ?', [time(), $p['id']]);
                    $sent++;
                }
            }
            $rollup = sendRollup($w, $byId);
            out(['ok' => true, 'sent' => $sent, 'rollup' => $rollup]);
        }

        case 'csv': {
            requireHost();
            $w = walkByCode(param('code'));
            $byId = peopleById((int)$w['id']);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="founders-walk-' . $w['walk_date'] . '.csv"');
            $f = fopen('php://output', 'w');
            fputcsv($f, ['Name', 'Role', 'Business', 'One line', 'LinkedIn', 'Email', 'Match 1', 'Match 1 status', 'Match 2', 'Match 2 status', 'Checked in', 'Done'], ',', '"', '');
            foreach ($byId as $id => $p) {
                $progress = cleanProgress(json_decode($p['progress'], true));
                $cols = [];
                foreach ([1, 2] as $r) {
                    $m = array_values(array_filter(matchesFor($w, (int)$id, $byId), fn($m) => $m['round'] === $r))[0] ?? null;
                    $cols[] = $m ? implode(' & ', array_column($m['people'], 'name')) : '';
                    $cols[] = $progress['rounds'][(string)$r]['state'] ?? '';
                }
                // Neutralise spreadsheet formulas in user-entered cells.
                $row = array_map(fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v,
                    [$p['name'], $p['role'], $p['business'], $p['oneliner'], $p['link'], $p['email'], ...$cols, $p['checked_in_at'] ? 'yes' : 'no', $p['done_at'] ? 'yes' : 'no']);
                fputcsv($f, $row, ',', '"', '');
            }
            exit;
        }

        default:
            fail('Unknown action.', 404);
    }
} catch (PDOException $ex) {
    error_log('founders-walk: ' . $ex->getMessage());
    fail('Something went wrong saving that. Try again in a moment.', 500);
}
