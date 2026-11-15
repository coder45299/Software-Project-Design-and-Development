<?php
declare(strict_types=1);
/**
 * Pet Biometrics - REST API (PHP 8+, PDO/MySQL)
 * Single entry point. All routes are relative to /api
 */
// ======================= SETTINGS (edit these) =======================
$cfg = [
    // XAMPP defaults. On a real server set the PETBIO_DB_* environment variables instead of editing this file.
    'db' => ['host' => getenv('PETBIO_DB_HOST') ?: '127.0.0.1', 'name' => getenv('PETBIO_DB_NAME') ?: 'pet_biometrics',
             'user' => getenv('PETBIO_DB_USER') ?: 'root', 'pass' => getenv('PETBIO_DB_PASS') ?: '', 'charset' => 'utf8mb4'],
    'allowed_origin' => '',                    // only if frontend is on another origin, e.g. 'http://localhost:5500'
    'upload_dir' => __DIR__ . '/../uploads',   // created automatically
    'upload_url' => 'uploads',                 // path saved in DB
    'max_upload_mb' => 5,
    'ml_url' => getenv('PETBIO_ML_URL') ?: 'http://127.0.0.1:5000',   // Python ML service
    'site_root' => __DIR__ . '/..',            // project folder (seed photos live in photos/)
    'ml_floor' => 0.72,                        // must equal PETBIO_FLOOR of ml/service.py (cosine of two unrelated photos)
    'match_threshold' => 20,                   // min similarity (0-100) to list a match in Find My Pet
    'suggest_threshold' => 55,                 // closest photo is shown to the admin as a suggestion at/above this
    'notify_threshold' => 75,                  // notify the owner of a registered LOST pet at/above this
];
// =====================================================================


session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header_remove('X-Powered-By');

// ---------- CORS (only when frontend is on another origin) ----------
if ($cfg['allowed_origin'] !== '') {
    header('Access-Control-Allow-Origin: ' . $cfg['allowed_origin']);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET,POST,DELETE,OPTIONS');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

set_exception_handler(function (Throwable $e) {
    error_log('[PetBio] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['message' => 'Server error. Please try again.'], 500);
});

// =====================================================================
//  Helpers
// =====================================================================
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    global $cfg;
    $d = $cfg['db'];
    $pdo = new PDO("mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}", $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
function out($data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400): never { out(['message' => $msg], $code); }

function body(): array {
    static $b = null;
    if ($b !== null) return $b;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $j = json_decode(file_get_contents('php://input') ?: '', true);
        return $b = is_array($j) ? $j : [];
    }
    return $b = $_POST;
}
function str(string $key, int $max = 255, bool $required = false): string {
    $v = trim((string)(body()[$key] ?? ''));
    if ($required && $v === '') fail(ucfirst(str_replace('_', ' ', $key)) . ' is required.');
    return mb_substr($v, 0, $max);
}
/** Phone numbers are validated at their real length (str() would silently cut a 21-digit number to 20 and accept it). */
function phoneField(bool $required = false): string {
    $v = trim((string)(body()['phone'] ?? ''));
    if ($v === '') { if ($required) fail('Phone is required.'); return ''; }
    if (!preg_match('/^[0-9+\-\s]{6,20}$/', $v)) fail('Enter a valid phone number.');
    return $v;
}
function pick(string $key, array $allowed, string $default): string {
    $v = (string)(body()[$key] ?? $default);
    return in_array($v, $allowed, true) ? $v : $default;
}
function validDate(?string $d): bool {
    if (!$d) return false;
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

const SPECIES = ['Dog', 'Cat', 'Bird', 'Rabbit', 'Other'];
const CARE_TYPES = ['Vaccination', 'Deworming', 'Vet Checkup', 'Grooming', 'Medication', 'Other'];

function me(): ?array {
    if (empty($_SESSION['uid'])) return null;
    static $u = false;
    if ($u === false) {
        $st = db()->prepare('SELECT user_id,name,email,phone,role,address,created_at,is_active FROM users WHERE user_id=?');
        $st->execute([$_SESSION['uid']]);
        $u = $st->fetch() ?: null;
        if ($u && !(int)$u['is_active']) $u = null;          // suspended by an admin
        if (!$u) unset($_SESSION['uid']);
    }
    return $u;
}
function needLogin(): array { return me() ?? fail('Please login first.', 401); }
function needAdmin(): array { $u = needLogin(); if ($u['role'] !== 'admin') fail('Admin access only.', 403); return $u; }

function notify(int $uid, string $type, string $msg): void {
    db()->prepare('INSERT INTO notifications (user_id,type,message) VALUES (?,?,?)')->execute([$uid, $type, mb_substr($msg, 0, 500)]);
}

// ---------- uploads ----------
function filesList(string $key): array {
    if (empty($_FILES[$key])) return [];
    $f = $_FILES[$key];
    if (!is_array($f['name'])) return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
    $o = [];
    foreach ($f['name'] as $i => $n) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $o[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $o;
}
function saveImage(array $f, string $sub): string {
    global $cfg;
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) fail('Image is too large (max ' . $cfg['max_upload_mb'] . 'MB).', 413);
    if ($f['error'] !== UPLOAD_ERR_OK) fail('Image upload failed.');
    if ($f['size'] > $cfg['max_upload_mb'] * 1048576) fail('Image is too large (max ' . $cfg['max_upload_mb'] . 'MB).', 413);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) fail('Only JPG, PNG or WEBP images are allowed.', 415);
    $dir = $cfg['upload_dir'] . '/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) fail('Could not save image.', 500);
    return $cfg['upload_url'] . "/$sub/$name";
}
function absPath(string $rel): string {
    global $cfg;
    return $cfg['upload_dir'] . '/' . substr($rel, strlen($cfg['upload_url']) + 1);
}
function deleteImageFile(string $rel): void { global $cfg; if (str_starts_with($rel, $cfg['upload_url'] . '/')) { $p = absPath($rel); if (is_file($p)) @unlink($p); } }
/** Real file behind a stored image path: uploads/... or a seed photo such as photos/pets/01-bruno.jpg. null if missing/unsafe. */
function fileFor(string $rel): ?string {
    global $cfg;
    if ($rel === '' || str_contains($rel, '..')) return null;
    $root = realpath($cfg['site_root']);
    if (str_starts_with($rel, $cfg['upload_url'] . '/')) $p = absPath($rel);
    elseif (str_starts_with($rel, 'photos/')) $p = $root . '/' . $rel;
    else return null;
    $rp = realpath($p);
    return ($rp && is_file($rp) && str_starts_with($rp, $root)) ? $rp : null;
}

// ---------- ML service ----------
function embed(string $file): ?array {
    global $cfg;
    if (!function_exists('curl_init')) return null;
    $ch = curl_init(rtrim($cfg['ml_url'], '/') . '/embed');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['image' => new CURLFile($file)],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 30,
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($r === false || $code !== 200) return null;
    $j = json_decode($r, true);
    return (isset($j['embedding']) && is_array($j['embedding'])) ? array_map('floatval', $j['embedding']) : null;
}
function cosine(array $a, array $b): float {
    $dot = $na = $nb = 0.0;
    foreach ($a as $i => $x) { $y = $b[$i]; $dot += $x * $y; $na += $x * $x; $nb += $y * $y; }
    return ($na > 0 && $nb > 0) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
}
/** cosine (about 0.72 for unrelated photos, 1.0 for the same photo) -> 0-100, same scale as the ML service and the browser */
function toScore(float $cos): float {
    global $cfg; $f = (float)$cfg['ml_floor'];
    return round(max(0.0, min(1.0, ($cos - $f) / (1 - $f))) * 100, 1);
}
function vecOf(?string $json): ?array { $v = $json ? json_decode($json, true) : null; return is_array($v) ? $v : null; }

/** Closest registered pets for a vector. Returns [['pet_id','score','image'], ...] best first. */
function findMatches(array $vec, ?string $species, int $limit = 5, int $min = -1): array {
    global $cfg;
    $min = $min < 0 ? (int)$cfg['match_threshold'] : $min;
    $sql = 'SELECT i.pet_id, i.image_path, i.embedding_vector FROM pet_images i JOIN pets p ON p.pet_id=i.pet_id WHERE i.embedding_vector IS NOT NULL';
    $args = [];
    if ($species && in_array($species, SPECIES, true)) { $sql .= ' AND p.species=?'; $args[] = $species; }
    $st = db()->prepare($sql);
    $st->execute($args);
    $best = [];
    foreach ($st as $row) {
        $v = vecOf($row['embedding_vector']);
        if (!$v || count($v) !== count($vec)) continue;
        $sc = toScore(cosine($vec, $v));
        $pid = (int)$row['pet_id'];
        if (!isset($best[$pid]) || $sc > $best[$pid]['score']) $best[$pid] = ['pet_id' => $pid, 'score' => $sc, 'image' => $row['image_path']];
    }
    usort($best, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_values(array_filter(array_slice($best, 0, $limit), fn($m) => $m['score'] >= $min));
}

/** Fingerprint of a stored report photo. Computed on demand if the ML service was offline when the report arrived. */
function reportVec(array $r): ?array {
    if ($v = vecOf($r['embedding_vector'] ?? null)) return $v;
    $f = fileFor($r['image_path']);
    $v = $f ? embed($f) : null;
    if ($v) db()->prepare('UPDATE lost_reports SET embedding_vector=? WHERE report_id=?')->execute([json_encode($v), $r['report_id']]);
    return $v;
}
const TYPE_LABEL = ['lost' => 'Lost', 'found' => 'Found'];
/** What the admin sees for a report: registered pets + reports of the opposite type that look alike. */
function candidatesFor(array $r, int $limit = 6): array {
    $vec = reportVec($r);
    if (!$vec) return [];
    $out = [];
    foreach (findMatches($vec, $r['species'], 60, 0) as $m) {
        $p = petRow($m['pet_id']); if (!$p) continue;
        $out[] = ['kind' => 'pet', 'id' => $p['pet_id'], 'label' => $p['name'],
                  'sub' => 'Registered ' . strtolower($p['species']) . ' · ' . ($p['breed'] ?: $p['species']) . ' · ' . ($p['location'] ?: 'no location'),
                  'image' => $m['image'], 'score' => $m['score'], 'pet_status' => $p['status']];
    }
    $opp = $r['report_type'] === 'lost' ? 'found' : 'lost';
    $st = db()->prepare("SELECT * FROM lost_reports WHERE report_id<>? AND report_type=? AND species=? AND status<>'resolved' ORDER BY report_id DESC LIMIT 200");
    $st->execute([$r['report_id'], $opp, $r['species']]);
    foreach ($st->fetchAll() as $o) {
        $v = reportVec($o);
        if (!$v || count($v) !== count($vec)) continue;
        $out[] = ['kind' => 'report', 'id' => (int)$o['report_id'], 'label' => TYPE_LABEL[$o['report_type']] . ' report #' . $o['report_id'],
                  'sub' => ($o['pet_name'] ? $o['pet_name'] . ' · ' : '') . $o['location'], 'image' => $o['image_path'], 'score' => toScore(cosine($vec, $v))];
    }
    $out = array_values(array_filter($out, fn($c) => $c['score'] >= 25));
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($out, 0, $limit);
}

// ---------- formatting ----------
function fmtPet(array $p): array {
    $p['pet_id'] = (int)$p['pet_id'];
    $p['owner_id'] = (int)$p['owner_id'];
    $p['age'] = $p['age'] !== null ? (float)$p['age'] : null;
    $p['ar'] = !empty($p['photo_ratio']) ? (float)$p['photo_ratio'] : null;      // gallery layout
    unset($p['photo_ratio']);
    return $p;
}
const COVER_SQL = '(SELECT image_path FROM pet_images i WHERE i.pet_id=p.pet_id ORDER BY i.is_primary DESC, i.image_id ASC LIMIT 1) AS cover_image';

function petRow(int $id): ?array {
    $st = db()->prepare('SELECT p.*, u.name AS owner_name, ' . COVER_SQL . ' FROM pets p JOIN users u ON u.user_id=p.owner_id WHERE p.pet_id=?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? fmtPet($r) : null;
}
const REPORT_SQL = 'SELECT r.*, u.name AS reporter, mp.name AS matched_pet_name, mr.report_type AS matched_report_type
    FROM lost_reports r JOIN users u ON u.user_id=r.reporter_id
    LEFT JOIN pets mp ON mp.pet_id=r.matched_pet_id LEFT JOIN lost_reports mr ON mr.report_id=r.matched_report_id';
function reportRaw(int $id): array {
    $st = db()->prepare(REPORT_SQL . ' WHERE r.report_id=?'); $st->execute([$id]);
    return $st->fetch() ?: fail('Report not found.', 404);
}
/** JSON shape the pages use. Phone, notes and match details only go to admins and to the person who sent the report. */
function fmtReport(array $r): array {
    $u = me(); $admin = $u && $u['role'] === 'admin'; $mine = $u && (int)$u['user_id'] === (int)$r['reporter_id']; $priv = $admin || $mine;
    $matched = null;
    if ($r['matched_pet_id']) $matched = ['kind' => 'pet', 'id' => (int)$r['matched_pet_id'], 'label' => $r['matched_pet_name'], 'score' => (float)$r['match_score']];
    elseif ($r['matched_report_id']) $matched = ['kind' => 'report', 'id' => (int)$r['matched_report_id'], 'label' => TYPE_LABEL[$r['matched_report_type']] . ' report #' . $r['matched_report_id'], 'score' => (float)$r['match_score']];
    return [
        'report_id' => (int)$r['report_id'], 'report_type' => $r['report_type'], 'pet_name' => $r['pet_name'] ?? '',
        'pet_id' => $r['pet_id'] !== null ? (int)$r['pet_id'] : null, 'species' => $r['species'], 'image_path' => $r['image_path'],
        'description' => $r['description'] ?? '', 'location' => $r['location'], 'found_date' => $r['found_date'], 'reporter' => $r['reporter'],
        'status' => $r['status'], 'created_at' => $r['created_at'], 'mine' => (bool)$mine,
        'phone' => $priv ? ($r['phone'] ?? '') : '',
        'admin_note' => $priv ? ($r['admin_note'] ?? '') : '',
        'matched' => $priv ? $matched : null,
        'suggestion' => $admin ? vecJson($r['suggestion_json'] ?? null) : null,
        'reporter_id' => $priv ? (int)$r['reporter_id'] : null,
    ];
}
function vecJson(?string $j): ?array { $v = $j ? json_decode($j, true) : null; return is_array($v) ? $v : null; }
function reportRow(int $id): array { return fmtReport(reportRaw($id)); }

// =====================================================================
//  Router
// =====================================================================
$path = trim((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$base = trim(dirname($_SERVER['SCRIPT_NAME']), '/\\.');
if ($base !== '' && str_starts_with($path, $base)) $path = trim(substr($path, strlen($base)), '/');
if (str_starts_with($path, 'index.php')) $path = trim(substr($path, 9), '/');
// Fallback that needs no URL rewriting at all:  api/index.php?route=/pets/5
if ($path === '' && isset($_GET['route'])) $path = trim((string)$_GET['route'], '/');
$seg = $path === '' ? [] : explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'];

switch ($seg[0] ?? '') {
    case '':              out(['name' => 'Pet Biometrics API', 'status' => 'ok']);
    case 'auth':          routeAuth($seg, $method); break;
    case 'pets':          routePets($seg, $method); break;
    case 'adoption':      routeAdoption($seg, $method); break;
    case 'care':          routeCare($seg, $method); break;
    case 'find-pet':      routeFindPet($method); break;
    case 'lost-reports':  routeLost($seg, $method); break;
    case 'notifications': routeNotifs($seg, $method); break;
    case 'admin':         routeAdmin($seg, $method); break;
    default:              fail('Not found.', 404);
}
fail('Method not allowed.', 405);

// =====================================================================
//  AUTH
// =====================================================================
function routeAuth(array $seg, string $m): void {
    $a = $seg[1] ?? '';
    if ($a === 'register' && $m === 'POST') {
        $name = str('name', 100, true); $email = strtolower(str('email', 150, true));
        $pass = (string)(body()['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address.');
        if (strlen($pass) < 8) fail('Password must be at least 8 characters.');
        $phone = phoneField();
        $st = db()->prepare('SELECT 1 FROM users WHERE email=?'); $st->execute([$email]);
        if ($st->fetch()) fail('This email is already registered.', 409);
        db()->prepare('INSERT INTO users (name,email,password_hash,phone,address) VALUES (?,?,?,?,?)')
            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $phone ?: null, str('address') ?: null]);
        $id = (int)db()->lastInsertId();
        notify($id, 'system', 'Welcome to Pet Biometrics! Add your first pet to get started.');
        session_regenerate_id(true);
        $_SESSION['uid'] = $id;
        out(['user' => me()], 201);
    }
    if ($a === 'login' && $m === 'POST') {
        if (($_SESSION['tries'] ?? 0) >= 5 && time() - ($_SESSION['try_time'] ?? 0) < 900) fail('Too many attempts. Try again in 15 minutes.', 429);
        $email = strtolower(str('email', 150, true)); $pass = (string)(body()['password'] ?? '');
        $st = db()->prepare('SELECT * FROM users WHERE email=?'); $st->execute([$email]);
        $u = $st->fetch();
        if (!$u || !password_verify($pass, $u['password_hash'])) {
            $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1; $_SESSION['try_time'] = time();
            fail('Incorrect email or password.', 401);
        }
        if (!(int)$u['is_active']) fail('This account is suspended. Please contact the admin.', 403);
        session_regenerate_id(true);
        $_SESSION = ['uid' => (int)$u['user_id']];
        unset($u['password_hash'], $u['is_active']);
        out(['user' => $u]);
    }
    if ($a === 'me' && $m === 'GET') out(['user' => me()]);
    if ($a === 'logout' && $m === 'POST') { $_SESSION = []; session_destroy(); out(['ok' => true]); }
    fail('Not found.', 404);
}

// =====================================================================
//  PETS
// =====================================================================
function routePets(array $seg, string $m): void {
    $id = $seg[1] ?? '';

    if ($id === '' && $m === 'GET') {              // list (public)
        $sql = 'SELECT p.*, ' . COVER_SQL . ' FROM pets p WHERE 1=1'; $args = [];
        if (!empty($_GET['status']))  { $sql .= ' AND p.status=?';  $args[] = $_GET['status']; }
        if (!empty($_GET['species'])) { $sql .= ' AND p.species=?'; $args[] = $_GET['species']; }
        if (!empty($_GET['q']))       { $sql .= ' AND (p.name LIKE ? OR p.breed LIKE ?)'; $args[] = '%' . $_GET['q'] . '%'; $args[] = '%' . $_GET['q'] . '%'; }
        $sql .= ' ORDER BY p.created_at DESC LIMIT ' . max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $st = db()->prepare($sql); $st->execute($args);
        out(['data' => array_map('fmtPet', $st->fetchAll())]);
    }
    if ($id === 'mine' && $m === 'GET') {          // my pets
        $u = needLogin();
        $st = db()->prepare('SELECT p.*, ' . COVER_SQL . ' FROM pets p WHERE p.owner_id=? ORDER BY p.created_at DESC');
        $st->execute([$u['user_id']]);
        out(['data' => array_map('fmtPet', $st->fetchAll())]);
    }
    if ($id === '' && $m === 'POST') { savePet(null); }

    if (ctype_digit($id)) {
        $pid = (int)$id;
        if ($m === 'GET') {
            $p = petRow($pid) ?? fail('Pet not found.', 404);
            $st = db()->prepare('SELECT image_id,image_path,is_primary FROM pet_images WHERE pet_id=? ORDER BY is_primary DESC,image_id');
            $st->execute([$pid]);
            $p['images'] = $st->fetchAll();
            out(['data' => $p]);
        }
        if ($m === 'POST') savePet($pid);
        if ($m === 'DELETE') {
            $u = needLogin(); $p = petRow($pid) ?? fail('Pet not found.', 404);
            if ($p['owner_id'] !== (int)$u['user_id'] && $u['role'] !== 'admin') fail('Not allowed.', 403);
            $st = db()->prepare('SELECT image_path FROM pet_images WHERE pet_id=?'); $st->execute([$pid]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $img) deleteImageFile($img);
            db()->prepare('DELETE FROM pets WHERE pet_id=?')->execute([$pid]);
            out(['ok' => true]);
        }
    }
    fail('Not found.', 404);
}

function savePet(?int $pid): never {
    $u = needLogin();
    $name = str('name', 100, true);
    $species = pick('species', SPECIES, 'Dog');
    $gender = pick('gender', ['Male', 'Female'], 'Male');
    $status = pick('status', ['owned', 'adoption', 'lost'], 'owned');
    $age = body()['age'] ?? null; $age = ($age === '' || $age === null) ? null : max(0, min(40, (float)$age));
    $vals = [$name, $species, str('breed', 100) ?: null, $age, $gender, str('color', 60) ?: null, str('description', 5000) ?: null, $status, str('location', 120) ?: null];

    if ($pid === null) {
        db()->prepare('INSERT INTO pets (owner_id,name,species,breed,age,gender,color,description,status,location) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$u['user_id'], ...$vals]);
        $pid = (int)db()->lastInsertId();
    } else {
        $p = petRow($pid) ?? fail('Pet not found.', 404);
        if ($p['owner_id'] !== (int)$u['user_id'] && $u['role'] !== 'admin') fail('Not allowed.', 403);
        db()->prepare('UPDATE pets SET name=?,species=?,breed=?,age=?,gender=?,color=?,description=?,status=?,location=? WHERE pet_id=?')
            ->execute([...$vals, $pid]);
    }

    // new photos (first one becomes primary if the pet has none)
    $files = filesList('images');
    if (count($files) > 6) fail('Maximum 6 photos allowed.');
    $st = db()->prepare('SELECT COUNT(*) FROM pet_images WHERE pet_id=?'); $st->execute([$pid]);
    $has = (int)$st->fetchColumn();
    foreach ($files as $i => $f) {
        $path = saveImage($f, 'pets');
        $vec = embed(absPath($path));            // null if ML service is offline (can be back-filled later)
        db()->prepare('INSERT INTO pet_images (pet_id,image_path,is_primary,embedding_vector) VALUES (?,?,?,?)')
            ->execute([$pid, $path, ($has === 0 && $i === 0) ? 1 : 0, $vec ? json_encode($vec) : null]);
        if ($has === 0 && $i === 0 && ($sz = @getimagesize(absPath($path))) && $sz[1] > 0)
            db()->prepare('UPDATE pets SET photo_ratio=? WHERE pet_id=?')->execute([round($sz[0] / $sz[1], 3), $pid]);
    }
    out(['data' => petRow($pid)], 201);
}

// =====================================================================
//  ADOPTION
// =====================================================================
function routeAdoption(array $seg, string $m): void {
    $a = $seg[1] ?? '';

    if ($a === '' && $m === 'GET') {               // public listing
        $sql = "SELECT p.*, " . COVER_SQL . " FROM pets p WHERE p.status='adoption'"; $args = [];
        foreach (['species', 'gender', 'location'] as $f) if (!empty($_GET[$f])) { $sql .= " AND p.$f=?"; $args[] = $_GET[$f]; }
        if (!empty($_GET['q'])) { $sql .= ' AND (p.name LIKE ? OR p.breed LIKE ?)'; $args[] = '%' . $_GET['q'] . '%'; $args[] = '%' . $_GET['q'] . '%'; }
        $st = db()->prepare($sql . ' ORDER BY p.created_at DESC'); $st->execute($args);
        out(['data' => array_map('fmtPet', $st->fetchAll())]);
    }

    if ($a === '' && $m === 'POST') {              // post my pet for adoption
        $u = needLogin();
        $p = petRow((int)(body()['pet_id'] ?? 0)) ?? fail('Pet not found.', 404);
        if ($p['owner_id'] !== (int)$u['user_id']) fail('You can only post your own pets.', 403);
        $loc = str('location', 120, true);
        db()->prepare("INSERT INTO adoption_posts (pet_id,posted_by,location,note,status) VALUES (?,?,?,?, 'active')")
            ->execute([$p['pet_id'], $u['user_id'], $loc, str('note', 2000) ?: null]);
        db()->prepare("UPDATE pets SET status='adoption', location=? WHERE pet_id=?")->execute([$loc, $p['pet_id']]);
        out(['ok' => true], 201);
    }

    if ($a === 'request' && $m === 'POST') {       // send adoption request
        $u = needLogin();
        $pid = (int)(body()['pet_id'] ?? 0);
        $st = db()->prepare("SELECT ap.post_id, ap.posted_by, p.name FROM adoption_posts ap JOIN pets p ON p.pet_id=ap.pet_id WHERE ap.pet_id=? AND ap.status='active' ORDER BY ap.post_id DESC LIMIT 1");
        $st->execute([$pid]);
        $post = $st->fetch() ?: fail('This pet is not available for adoption.', 404);
        if ((int)$post['posted_by'] === (int)$u['user_id']) fail('You cannot adopt your own pet.');
        $st = db()->prepare('SELECT 1 FROM adoption_requests WHERE post_id=? AND requester_id=?'); $st->execute([$post['post_id'], $u['user_id']]);
        if ($st->fetch()) fail('You already sent a request for this pet.', 409);
        db()->prepare('INSERT INTO adoption_requests (post_id,requester_id,phone,message) VALUES (?,?,?,?)')
            ->execute([$post['post_id'], $u['user_id'], phoneField() ?: null, str('message', 2000, true)]);
        $rid = (int)db()->lastInsertId();
        notify((int)$post['posted_by'], 'adoption', $u['name'] . ' sent an adoption request for ' . $post['name'] . '.');
        notifyAdmins("New adoption request #$rid: {$u['name']} wants to adopt {$post['name']}.", $rid, 'adoption');
        notify((int)$u['user_id'], 'adoption', 'Your adoption request for ' . $post['name'] . ' was sent. The admin will review it.');
        out(['ok' => true], 201);
    }

    if ($a === 'requests' && ($seg[2] ?? '') === '' && $m === 'GET') {   // requests on MY pets
        $u = needLogin();
        $st = db()->prepare('SELECT r.request_id, p.name AS pet, ru.name AS requester, r.phone, r.message, r.status, r.date
            FROM adoption_requests r JOIN adoption_posts ap ON ap.post_id=r.post_id JOIN pets p ON p.pet_id=ap.pet_id
            JOIN users ru ON ru.user_id=r.requester_id WHERE ap.posted_by=? ORDER BY r.date DESC');
        $st->execute([$u['user_id']]);
        out(['data' => $st->fetchAll()]);
    }

    if ($a === 'requests' && ctype_digit($seg[2] ?? '') && ($seg[3] ?? '') === 'decide' && $m === 'POST') {
        $u = needLogin(); $rid = (int)$seg[2];
        $status = pick('status', ['approved', 'rejected'], '');
        if ($status === '') fail('Status must be approved or rejected.');
        $st = db()->prepare('SELECT r.request_id, r.requester_id, r.status, ap.post_id, ap.pet_id, ap.posted_by, p.name FROM adoption_requests r
            JOIN adoption_posts ap ON ap.post_id=r.post_id JOIN pets p ON p.pet_id=ap.pet_id WHERE r.request_id=?');
        $st->execute([$rid]);
        $r = $st->fetch() ?: fail('Request not found.', 404);
        if ((int)$r['posted_by'] !== (int)$u['user_id'] && $u['role'] !== 'admin') fail('Not allowed.', 403);
        if ($r['status'] !== 'pending') fail('This request was already decided.');
        $pdo = db(); $pdo->beginTransaction();
        $pdo->prepare('UPDATE adoption_requests SET status=? WHERE request_id=?')->execute([$status, $rid]);
        if ($status === 'approved') {
            $pdo->prepare("UPDATE adoption_posts SET status='adopted' WHERE post_id=?")->execute([$r['post_id']]);
            $pdo->prepare("UPDATE pets SET status='adopted' WHERE pet_id=?")->execute([$r['pet_id']]);
            $pdo->prepare("UPDATE adoption_requests SET status='rejected' WHERE post_id=? AND request_id<>? AND status='pending'")->execute([$r['post_id'], $rid]);
        }
        $pdo->commit();
        notify((int)$r['requester_id'], 'adoption', 'Your adoption request for ' . $r['name'] . ' was ' . $status . '.');
        out(['ok' => true]);
    }
    fail('Not found.', 404);
}

// =====================================================================
//  CARE TRACKER
// =====================================================================
function ownPetOrFail(int $pid, array $u): array {
    $p = petRow($pid) ?? fail('Pet not found.', 404);
    if ($p['owner_id'] !== (int)$u['user_id'] && $u['role'] !== 'admin') fail('Not allowed.', 403);
    return $p;
}
function routeCare(array $seg, string $m): void {
    $u = needLogin(); $a = $seg[1] ?? '';

    if ($a === '' && $m === 'GET') {
        $pid = (int)($_GET['pet_id'] ?? 0); ownPetOrFail($pid, $u);
        $st = db()->prepare('SELECT * FROM care_records WHERE pet_id=? ORDER BY date DESC'); $st->execute([$pid]);
        out(['data' => $st->fetchAll()]);
    }
    if ($a === 'upcoming' && $m === 'GET') {       // for dashboard reminders
        $st = db()->prepare('SELECT c.*, p.name AS pet_name, DATEDIFF(c.next_due_date, CURDATE()) AS days_left
            FROM care_records c JOIN pets p ON p.pet_id=c.pet_id WHERE p.owner_id=? AND c.next_due_date IS NOT NULL
            ORDER BY c.next_due_date LIMIT 10');
        $st->execute([$u['user_id']]);
        out(['data' => $st->fetchAll()]);
    }
    if ($a === '' && $m === 'POST') {
        $pid = (int)(body()['pet_id'] ?? 0); ownPetOrFail($pid, $u);
        $date = str('date', 10, true); $next = str('next_due_date', 10);
        if (!validDate($date)) fail('Enter a valid date.');
        if ($next !== '' && !validDate($next)) fail('Enter a valid next due date.');
        db()->prepare('INSERT INTO care_records (pet_id,type,date,notes,next_due_date) VALUES (?,?,?,?,?)')
            ->execute([$pid, pick('type', CARE_TYPES, 'Other'), $date, str('notes', 2000) ?: null, $next ?: null]);
        out(['data' => ['record_id' => (int)db()->lastInsertId()]], 201);
    }
    if (ctype_digit($a) && $m === 'DELETE') {
        $st = db()->prepare('SELECT pet_id FROM care_records WHERE record_id=?'); $st->execute([$a]);
        $r = $st->fetch() ?: fail('Record not found.', 404);
        ownPetOrFail((int)$r['pet_id'], $u);
        db()->prepare('DELETE FROM care_records WHERE record_id=?')->execute([$a]);
        out(['ok' => true]);
    }
    fail('Not found.', 404);
}

// =====================================================================
//  FIND MY PET  (biometric matching)
// =====================================================================
function routeFindPet(string $m): void {
    if ($m !== 'POST') fail('Method not allowed.', 405);
    $files = filesList('image');
    if (!$files) fail('Please upload a photo.');
    $path = saveImage($files[0], 'tmp');
    try {
        $vec = embed(absPath($path));
        if (!$vec) fail('The matching service is unavailable right now. Please try again later.', 503);
        $matches = findMatches($vec, $_POST['species'] ?? null, 5);
        foreach ($matches as &$mt) { $mt['pet'] = petRow($mt['pet_id']); unset($mt['image']); }
        unset($mt);
        out(['matches' => $matches]);
    } finally {
        deleteImageFile($path);                 // search photos are not kept
    }
}

// =====================================================================
//  LOST / FOUND REPORTS
// =====================================================================
function notifyAdmins(string $msg, int $refId, string $type = 'report'): void {
    $st = db()->query("SELECT user_id FROM users WHERE role='admin' AND is_active=1");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $aid)
        db()->prepare('INSERT INTO notifications (user_id,type,message,ref_id) VALUES (?,?,?,?)')->execute([$aid, $type, mb_substr($msg, 0, 500), $refId]);
}

function routeLost(array $seg, string $m): void {
    global $cfg;
    if (($seg[1] ?? '') === '' && $m === 'GET') {
        $rows = db()->query(REPORT_SQL . ' ORDER BY r.created_at DESC, r.report_id DESC LIMIT 100')->fetchAll();
        out(['data' => array_map('fmtReport', $rows)]);
    }
    if (($seg[1] ?? '') === '' && $m === 'POST') {
        $u = needLogin();
        $files = filesList('image');
        if (!$files) fail('Please upload a photo of the pet.');
        $type = pick('report_type', ['lost', 'found'], 'found');
        $loc = str('location', 255, true); $date = str('found_date', 10, true);
        if (!validDate($date)) fail('Enter a valid date.');
        if ($date > date('Y-m-d')) fail('The date cannot be in the future.');
        $phone = phoneField(true);
        $species = pick('species', SPECIES, 'Dog');
        $petName = $type === 'lost' ? str('pet_name', 100) : '';
        $petId = null;                                              // only your own registered pet can be linked
        if ($type === 'lost' && ctype_digit((string)(body()['pet_id'] ?? ''))) {
            $p = petRow((int)body()['pet_id']);
            if ($p && $p['owner_id'] === (int)$u['user_id']) { $petId = $p['pet_id']; $petName = $petName ?: $p['name']; }
        }
        $path = saveImage($files[0], 'lost');
        $vec = embed(absPath($path));                               // null if the ML service is offline; filled in later

        db()->prepare('INSERT INTO lost_reports (reporter_id,report_type,pet_name,pet_id,image_path,species,description,phone,location,found_date,embedding_vector)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$u['user_id'], $type, $petName ?: null, $petId, $path, $species, str('description', 2000) ?: null, $phone, $loc, $date, $vec ? json_encode($vec) : null]);
        $rid = (int)db()->lastInsertId();
        if ($petId && $type === 'lost') db()->prepare("UPDATE pets SET status='lost' WHERE pet_id=?")->execute([$petId]);

        $best = candidatesFor(reportRaw($rid), 1)[0] ?? null;      // closest look-alike, shown to the admin as a hint
        if ($best && $best['score'] >= $cfg['suggest_threshold']) {
            db()->prepare('UPDATE lost_reports SET suggestion_json=? WHERE report_id=?')
                ->execute([json_encode(['kind' => $best['kind'], 'id' => $best['id'], 'label' => $best['label'], 'score' => $best['score']]), $rid]);
        }
        $what = $petName ? "$petName ($species)" : $species;
        notifyAdmins('New ' . strtoupper($type) . " report #$rid: $what near $loc." . ($best && $best['score'] >= $cfg['suggest_threshold'] ? ' Closest photo: ' . $best['label'] . ' (' . round($best['score']) . '%).' : ''), $rid);
        notify((int)$u['user_id'], 'system', "We received your $type report #$rid. Our team will compare the photo and contact you.");

        if ($type === 'found' && $best && $best['kind'] === 'pet' && $best['score'] >= $cfg['notify_threshold']) {   // strong hit on a pet its owner reported lost
            $p = petRow($best['id']);
            if ($p && $p['status'] === 'lost' && $p['owner_id'] !== (int)$u['user_id'])
                notify($p['owner_id'], 'match', 'A pet reported near ' . $loc . ' looks like your lost pet ' . $p['name'] . '. Our team is checking the photo.');
        }
        out(['data' => reportRow($rid)], 201);
    }
    if (ctype_digit($seg[1] ?? '') && $m === 'DELETE') {
        $u = needLogin();
        $st = db()->prepare('SELECT reporter_id, image_path FROM lost_reports WHERE report_id=?'); $st->execute([$seg[1]]);
        $r = $st->fetch() ?: fail('Report not found.', 404);
        if ((int)$r['reporter_id'] !== (int)$u['user_id'] && $u['role'] !== 'admin') fail('Not allowed.', 403);
        deleteImageFile($r['image_path']);
        db()->prepare('DELETE FROM lost_reports WHERE report_id=?')->execute([$seg[1]]);
        out(['ok' => true]);
    }
    fail('Not found.', 404);
}

// =====================================================================
//  NOTIFICATIONS
// =====================================================================
function routeNotifs(array $seg, string $m): void {
    $u = needLogin(); $a = $seg[1] ?? '';
    if ($a === '' && $m === 'GET') {
        $st = db()->prepare("SELECT n.*, r.image_path, r.report_type, r.status AS report_status FROM notifications n
            LEFT JOIN lost_reports r ON n.type='report' AND r.report_id=n.ref_id WHERE n.user_id=? ORDER BY n.created_at DESC, n.notif_id DESC LIMIT 100"); $st->execute([$u['user_id']]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) { $r['notif_id'] = (int)$r['notif_id']; $r['is_read'] = (int)$r['is_read']; }
        out(['data' => $rows, 'unread' => count(array_filter($rows, fn($r) => !$r['is_read']))]);
    }
    if ($a === 'read' && $m === 'POST') {          // body: {id} for one, nothing for all
        $id = (int)(body()['id'] ?? 0);
        if ($id) db()->prepare('UPDATE notifications SET is_read=1 WHERE notif_id=? AND user_id=?')->execute([$id, $u['user_id']]);
        else     db()->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$u['user_id']]);
        out(['ok' => true]);
    }
    if (ctype_digit($a) && $m === 'DELETE') {
        db()->prepare('DELETE FROM notifications WHERE notif_id=? AND user_id=?')->execute([$a, $u['user_id']]);
        out(['ok' => true]);
    }
    fail('Not found.', 404);
}

// =====================================================================
//  ADMIN
// =====================================================================
function routeAdmin(array $seg, string $m): void {
    $me = needAdmin(); $a = $seg[1] ?? '';

    if ($a === 'stats' && $m === 'GET') {
        $c = fn(string $sql) => (int)db()->query($sql)->fetchColumn();
        out(['data' => [
            'users' => $c('SELECT COUNT(*) FROM users'), 'pets' => $c('SELECT COUNT(*) FROM pets'),
            'pending_requests' => $c("SELECT COUNT(*) FROM adoption_requests WHERE status='pending'"),
            'reports' => $c('SELECT COUNT(*) FROM lost_reports'),
            'by_species' => db()->query('SELECT species, COUNT(*) AS total FROM pets GROUP BY species')->fetchAll(),
        ]]);
    }
    if ($a === 'users' && ($seg[2] ?? '') === '' && $m === 'GET') {
        out(['data' => array_map(function ($r) { $r['user_id'] = (int)$r['user_id']; $r['is_active'] = (int)$r['is_active']; $r['pets'] = (int)$r['pets']; return $r; },
            db()->query('SELECT u.user_id,u.name,u.email,u.phone,u.role,u.address,u.created_at,u.is_active,(SELECT COUNT(*) FROM pets p WHERE p.owner_id=u.user_id) AS pets FROM users u ORDER BY u.user_id')->fetchAll())]);
    }
    if ($a === 'users' && ctype_digit($seg[2] ?? '')) {
        $uid = (int)$seg[2];
        if ($uid === (int)$me['user_id']) fail('You cannot change your own account here.');
        if (($seg[3] ?? '') === 'role' && $m === 'POST') {
            db()->prepare('UPDATE users SET role=? WHERE user_id=?')->execute([pick('role', ['user', 'admin'], 'user'), $uid]);
            out(['ok' => true]);
        }
        if (($seg[3] ?? '') === 'active' && $m === 'POST') {          // suspend / re-activate
            $on = !empty(body()['active']) ? 1 : 0;
            db()->prepare('UPDATE users SET is_active=? WHERE user_id=?')->execute([$on, $uid]);
            if (!$on) notify($uid, 'system', 'Your account was suspended by an admin.');
            out(['ok' => true]);
        }
        if ($m === 'DELETE') { db()->prepare('DELETE FROM users WHERE user_id=?')->execute([$uid]); out(['ok' => true]); }
    }
    if ($a === 'pets' && ($seg[2] ?? '') === '' && $m === 'GET') {
        $st = db()->query('SELECT p.*, u.name AS owner_name, ' . COVER_SQL . ' FROM pets p JOIN users u ON u.user_id=p.owner_id ORDER BY p.pet_id');
        out(['data' => array_map('fmtPet', $st->fetchAll())]);
    }
    if ($a === 'pets' && ctype_digit($seg[2] ?? '') && ($seg[3] ?? '') === 'status' && $m === 'POST') {
        $status = pick('status', ['owned', 'adoption', 'lost', 'adopted'], '');
        if ($status === '') fail('Invalid status.');
        $p = petRow((int)$seg[2]) ?? fail('Pet not found.', 404);
        db()->prepare('UPDATE pets SET status=? WHERE pet_id=?')->execute([$status, $p['pet_id']]);
        if ($status === 'adoption') {                                // adopters need an active post to send requests to
            $st = db()->prepare("SELECT 1 FROM adoption_posts WHERE pet_id=? AND status='active'"); $st->execute([$p['pet_id']]);
            if (!$st->fetch()) db()->prepare("INSERT INTO adoption_posts (pet_id,posted_by,location,note,status) VALUES (?,?,?,?, 'active')")->execute([$p['pet_id'], $p['owner_id'], $p['location'] ?: 'Not given', 'Listed by an admin.']);
        }
        if ($status !== $p['status']) notify($p['owner_id'], 'system', $p['name'] . ' was marked "' . $status . '" by an admin.');
        out(['ok' => true]);
    }
    if ($a === 'broadcast' && $m === 'POST') {                      // announcement to everyone, or to one user
        $msg = str('message', 500, true); $uid = (int)(body()['user_id'] ?? 0);
        $st = $uid ? db()->prepare("SELECT user_id FROM users WHERE user_id=? AND is_active=1") : db()->prepare("SELECT user_id FROM users WHERE role='user' AND is_active=1");
        $st->execute($uid ? [$uid] : []);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) notify((int)$id, 'system', $msg);
        out(['ok' => true, 'sent' => count($ids)]);
    }
    if ($a === 'reports') { adminReports($seg, $m); }
    if ($a === 'reindex' && $m === 'POST') { adminReindex(); }
    if ($a === 'requests' && $m === 'GET') {
        out(['data' => db()->query('SELECT r.request_id, p.name AS pet, u.name AS requester, r.message, r.status, r.date
            FROM adoption_requests r JOIN adoption_posts ap ON ap.post_id=r.post_id JOIN pets p ON p.pet_id=ap.pet_id
            JOIN users u ON u.user_id=r.requester_id ORDER BY r.date DESC')->fetchAll()]);
    }
    fail('Not found.', 404);
}

// =====================================================================
//  ADMIN: lost / found reports  (list, review, photo download, compare, decide)
// =====================================================================
function adminReports(array $seg, string $m): void {
    $rid = $seg[2] ?? '';
    if ($rid === '' && $m === 'GET') {                                  // every report, newest first
        out(['data' => array_map('fmtReport', db()->query(REPORT_SQL . ' ORDER BY r.created_at DESC, r.report_id DESC LIMIT 300')->fetchAll())]);
    }
    if (!ctype_digit($rid)) fail('Not found.', 404);
    $id = (int)$rid; $sub = $seg[3] ?? '';
    $r = reportRaw($id);

    if ($sub === '' && $m === 'GET') {                                  // the match console: report + look-alikes
        out(['data' => fmtReport($r), 'candidates' => candidatesFor($r)]);
    }
    if ($sub === 'photo' && $m === 'GET') {                             // "Download photo" button
        $f = fileFor($r['image_path']) ?? fail('Photo file not found.', 404);
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($f));
        header('Content-Disposition: attachment; filename="petbio-report-' . $id . '-' . $r['report_type'] . '-' . strtolower($r['species']) . '.' . ($ext ?: 'jpg') . '"');
        header('Cache-Control: private, no-store');
        readfile($f);
        exit;
    }
    if ($sub === 'compare' && $m === 'POST') {                          // report photo vs any photo the admin uploads
        $files = filesList('image') ?: fail('Choose a photo to compare.');
        $path = saveImage($files[0], 'tmp');
        try {
            $a = reportVec($r); $b = embed(absPath($path));
            if (!$a || !$b) fail('The matching service is unavailable right now.', 503);
            $c = cosine($a, $b);
            out(['score' => toScore($c), 'cosine' => round($c, 4)]);
        } finally { deleteImageFile($path); }
    }
    if ($sub === 'decide' && $m === 'POST') { adminDecide($r); }
    fail('Not found.', 404);
}

function adminDecide(array $r): never {
    $action = pick('action', ['confirm', 'reunite', 'reject'], '');
    if ($action === '') fail('Action must be confirm, reunite or reject.');
    $note = str('note', 500) ?: null;
    $id = (int)$r['report_id'];
    $kind = (string)(body()['candidate_kind'] ?? ''); $cid = (int)(body()['candidate_id'] ?? 0);
    if ($action === 'reunite' && !$cid) {                               // reuse the match that was already confirmed
        if ($r['matched_pet_id']) { $kind = 'pet'; $cid = (int)$r['matched_pet_id']; }
        elseif ($r['matched_report_id']) { $kind = 'report'; $cid = (int)$r['matched_report_id']; }
    }
    $pdo = db();

    if ($action === 'reject') {
        $pdo->beginTransaction();
        if ($r['matched_report_id'])                                    // un-link the other report too
            $pdo->prepare("UPDATE lost_reports SET status='open', matched_report_id=NULL, match_score=NULL WHERE report_id=? AND matched_report_id=? AND status='matched'")->execute([$r['matched_report_id'], $id]);
        $pdo->prepare("UPDATE lost_reports SET status='open', matched_pet_id=NULL, matched_report_id=NULL, match_score=NULL, admin_note=? WHERE report_id=?")->execute([$note, $id]);
        $pdo->commit();
        out(['ok' => true]);
    }

    if ($action === 'confirm' && !$cid) fail('Choose the photo that matches first.');
    $status = $action === 'reunite' ? 'resolved' : 'matched';
    $vec = reportVec($r);
    $pet = null; $other = null; $score = null; $label = null;
    if ($cid && $kind === 'pet') {
        $pet = petRow($cid) ?? fail('That pet no longer exists.', 404); $label = $pet['name'];
        $best = $vec ? (findMatches($vec, null, 500, 0)) : [];
        foreach ($best as $b) if ($b['pet_id'] === $cid) { $score = $b['score']; break; }
    } elseif ($cid && $kind === 'report') {
        if ($cid === $id) fail('A report cannot be matched with itself.');
        $other = reportRaw($cid); $label = TYPE_LABEL[$other['report_type']] . ' report #' . $cid;
        $ov = reportVec($other);
        if ($vec && $ov && count($vec) === count($ov)) $score = toScore(cosine($vec, $ov));
    } elseif ($cid) fail('Unknown candidate.');

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE lost_reports SET status=?, matched_pet_id=?, matched_report_id=?, match_score=?, admin_note=? WHERE report_id=?')
        ->execute([$status, $pet ? $pet['pet_id'] : null, $other ? (int)$other['report_id'] : null, $score, $note, $id]);
    if ($other)
        $pdo->prepare('UPDATE lost_reports SET status=?, matched_pet_id=NULL, matched_report_id=?, match_score=? WHERE report_id=?')->execute([$status, $id, $score, $other['report_id']]);
    if ($action === 'reunite') {                                        // pet is home again: clear 'lost' on the matched pet and on the pets named in either report
        $ids = array_filter([$pet ? (int)$pet['pet_id'] : 0, (int)($r['pet_id'] ?? 0), $other ? (int)($other['pet_id'] ?? 0) : 0]);
        foreach (array_unique($ids) as $pid)
            $pdo->prepare("UPDATE pets SET status='owned' WHERE pet_id=? AND status='lost'")->execute([$pid]);
    }
    $pdo->commit();

    $reporter = (int)$r['reporter_id']; $type = $r['report_type'];
    if ($action === 'confirm') {
        notify($reporter, 'match', "Our team matched your $type report #$id" . ($label ? " with $label" : '') . '. We will share contact details shortly.');
        if ($pet && $pet['owner_id'] !== $reporter)
            notify($pet['owner_id'], 'match', 'Good news: a photo reported near ' . $r['location'] . ' matches your pet ' . $pet['name'] . '. Reporter phone: ' . ($r['phone'] ?: 'not given') . '.');
        if ($other && (int)$other['reporter_id'] !== $reporter)
            notify((int)$other['reporter_id'], 'match', 'Good news: your ' . $other['report_type'] . " report #{$other['report_id']} was matched with a " . $type . " report from " . $r['location'] . '. Phone: ' . ($r['phone'] ?: 'not given') . '.');
    } else {
        notify($reporter, 'match', "Report #$id is marked reunited. Thank you for helping!");
        if ($other && (int)$other['reporter_id'] !== $reporter) notify((int)$other['reporter_id'], 'match', "Report #{$other['report_id']} is marked reunited. Thank you for helping!");
    }
    out(['ok' => true]);
}

/** Recompute every stored fingerprint (after changing PETBIO_ENGINE or adding photos by hand). */
function adminReindex(): never {
    @set_time_limit(600);
    $done = ['pet_images' => 0, 'reports' => 0, 'missing_files' => 0];
    foreach (db()->query('SELECT image_id, image_path FROM pet_images')->fetchAll() as $row) {
        $f = fileFor($row['image_path']);
        if (!$f) { $done['missing_files']++; continue; }
        $v = embed($f) ?? fail('The matching service is unavailable right now.', 503);
        db()->prepare('UPDATE pet_images SET embedding_vector=? WHERE image_id=?')->execute([json_encode($v), $row['image_id']]);
        $done['pet_images']++;
    }
    foreach (db()->query('SELECT report_id, image_path FROM lost_reports')->fetchAll() as $row) {
        $f = fileFor($row['image_path']);
        if (!$f) { $done['missing_files']++; continue; }
        $v = embed($f) ?? fail('The matching service is unavailable right now.', 503);
        db()->prepare('UPDATE lost_reports SET embedding_vector=? WHERE report_id=?')->execute([json_encode($v), $row['report_id']]);
        $done['reports']++;
    }
    out(['ok' => true, 'indexed' => $done]);
}
