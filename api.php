<?php
// =====================================================
// API Classify — penghubung antara classify.html dan MySQL
// Semua query memakai prepared statement (aman dari SQL injection).
// =====================================================
declare(strict_types=1);
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

const SESI = ['08:00 - 10:30 WIB', '10:30 - 13:00 WIB', '13:00 - 15:30 WIB', '15:30 - 18:00 WIB'];
const GEDUNG = ['FT', 'FIK', 'FEBI', 'FISIP', 'FK'];

function out(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }
function norm(string $s): string { return preg_replace('/[^a-z0-9]/', '', mb_strtolower($s)) ?? ''; }
function clip(mixed $v, int $max): string { return mb_substr(trim((string)($v ?? '')), 0, $max); }

try {
    $pdo = new PDO(DB_DSN, defined('DB_USER') ? DB_USER : null, defined('DB_PASS') ? DB_PASS : null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    fail('Tidak dapat terhubung ke database. Periksa config.php dan pastikan MySQL menyala.', 500);
}
$isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$lock = $isMysql ? ' FOR UPDATE' : '';

function q(string $sql, array $p = []): PDOStatement {
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st;
}

$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($in)) $in = [];
$user = $_SESSION['user'] ?? null;

function requireLogin(): array {
    global $user;
    if (!$user) fail('Sesi berakhir, silakan login kembali.', 401);
    return $user;
}
function requireRole(string ...$roles): array {
    $u = requireLogin();
    if (!in_array($u['role'], $roles, true)) fail('Akses ditolak untuk peran Anda.', 403);
    return $u;
}

function slotTaken(string $ruang, string $tanggal, string $sesi, array $statuses, string $excludeId = ''): bool {
    global $lock;
    $ph = implode(',', array_fill(0, count($statuses), '?'));
    $st = q("SELECT COUNT(*) FROM peminjaman WHERE ruang=? AND tanggal=? AND sesi=? AND id<>? AND status IN ($ph)" . $lock,
        array_merge([$ruang, $tanggal, $sesi, $excludeId], $statuses));
    return (int)$st->fetchColumn() > 0;
}

switch ($action) {
    // ---------------------------------------------------- LOGIN
    case 'login': {
        $role = (string)($in['role'] ?? '');
        $name = clip($in['name'] ?? '', 100);
        $cred = clip($in['cred'] ?? '', 100);
        $found = null;
        if ($role === 'mahasiswa') {
            $r = q('SELECT nim, nama, fakultas, prodi, kelas FROM mahasiswa WHERE nim=?', [$cred])->fetch();
            if ($r && norm($r['nama']) === norm($name)) $found = ['role' => 'mahasiswa', 'row' => $r, 'id' => $r['nim']];
        } elseif ($role === 'dosen') {
            $r = q('SELECT nisn, nama, fakultas FROM dosen WHERE nisn=?', [$cred])->fetch();
            if ($r && norm($r['nama']) === norm($name)) $found = ['role' => 'dosen', 'row' => $r, 'id' => $r['nisn']];
        } elseif ($role === 'ob' || $role === 'admin') {
            $table = $role === 'ob' ? 'petugas_ob' : 'admin';
            $cols = $role === 'ob' ? 'id, nama, password, area_tugas' : 'id, nama, password, jabatan';
            foreach (q("SELECT $cols FROM $table")->fetchAll() as $r) {
                if (norm($r['nama']) === norm($name) && $cred === $r['password']) {                    unset($r['password']);
                    $found = ['role' => $role, 'row' => $r, 'id' => $r['id']];
                    break;
                }
            }
        }
        if (!$found) { usleep(400000); fail('Login gagal: data tidak cocok.', 401); }
        session_regenerate_id(true);
        $_SESSION['user'] = ['role' => $found['role'], 'id' => $found['id'], 'row' => $found['row']];
        out(['role' => $found['role'], 'row' => $found['row']]);
    }

    case 'logout':
        $_SESSION = [];
        session_destroy();
        out(['ok' => true]);

    case 'me':
        out($user ? ['role' => $user['role'], 'row' => $user['row']] : ['role' => null]);

    // ---------------------------------------------------- SNAPSHOT (data untuk ditampilkan)
    case 'snapshot': {
        requireLogin();
        $counts = [];
        foreach (['mahasiswa', 'dosen', 'petugas_ob', 'admin', 'ruang_kelas', 'peminjaman', 'laporan_ob', 'chat'] as $t) {
            $counts[$t] = (int)q("SELECT COUNT(*) FROM $t")->fetchColumn();
        }
        out([
            'ruang_kelas' => q('SELECT kode, gedung, lantai, nomor, kapasitas FROM ruang_kelas ORDER BY gedung, lantai, nomor')->fetchAll(),
            'peminjaman' => q('SELECT id, pemohon_role, pemohon_id, pemohon_nama, fakultas, prodi, kelas, dosen, mata_kuliah, ruang, tanggal, sesi, keperluan, status FROM peminjaman ORDER BY tanggal, sesi, ruang')->fetchAll(),
            'laporan_ob' => q('SELECT id, pelapor, lokasi, masalah, status FROM laporan_ob ORDER BY id DESC')->fetchAll(),
            'chat' => q('SELECT id, tab, pengirim_id, pengirim_nama, pesan, waktu FROM chat ORDER BY id')->fetchAll(),
            'dosen' => q('SELECT nisn, nama, fakultas FROM dosen ORDER BY nama')->fetchAll(),
            'counts' => $counts,
        ]);
    }

    // ---------------------------------------------------- AJUKAN PEMINJAMAN
    case 'booking_create': {
        $u = requireRole('mahasiswa', 'dosen');
        $b = [
            'fakultas' => clip($in['fakultas'] ?? '', 10),
            'prodi' => clip($in['prodi'] ?? '', 80),
            'kelas' => clip($in['kelas'] ?? '', 30),
            'dosen' => clip($in['dosen'] ?? '', 100),
            'mata_kuliah' => clip($in['mata_kuliah'] ?? '', 120),
            'ruang' => clip($in['ruang'] ?? '', 15),
            'tanggal' => clip($in['tanggal'] ?? '', 10),
            'sesi' => clip($in['sesi'] ?? '', 25),
            'keperluan' => clip($in['keperluan'] ?? '', 255),
        ];
        foreach (['prodi', 'kelas', 'dosen', 'mata_kuliah', 'keperluan'] as $f) if ($b[$f] === '') fail("Kolom $f wajib diisi.");
        if (!in_array($b['fakultas'], GEDUNG, true)) fail('Fakultas tidak valid.');
        if (!in_array($b['sesi'], SESI, true)) fail('Sesi tidak valid.');
        $d = DateTime::createFromFormat('Y-m-d', $b['tanggal']);
        if (!$d || $d->format('Y-m-d') !== $b['tanggal']) fail('Tanggal tidak valid.');
        if (!q('SELECT 1 FROM ruang_kelas WHERE kode=?', [$b['ruang']])->fetch()) fail('Ruang tidak ditemukan.');
        if (!q('SELECT 1 FROM dosen WHERE nama=?', [$b['dosen']])->fetch()) fail('Dosen tidak terdaftar.');

        $id = 'BK-' . strtoupper(base_convert((string)(int)(microtime(true) * 1000), 10, 36)) . random_int(10, 99);
        $pdo->beginTransaction();
        try {
            if (slotTaken($b['ruang'], $b['tanggal'], $b['sesi'], ['Approved', 'Pending'])) {
                $pdo->rollBack();
                fail("Ruang {$b['ruang']} sudah diajukan/terpakai pada tanggal & sesi tersebut.", 409);
            }
            q('INSERT INTO peminjaman (id, pemohon_role, pemohon_id, pemohon_nama, fakultas, prodi, kelas, dosen, mata_kuliah, ruang, tanggal, sesi, keperluan, status)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $id, $u['role'], $u['id'], $u['row']['nama'], $b['fakultas'], $b['prodi'], $b['kelas'], $b['dosen'],
                $b['mata_kuliah'], $b['ruang'], $b['tanggal'], $b['sesi'], $b['keperluan'], 'Pending',
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        out(['ok' => true, 'id' => $id]);
    }

    // ---------------------------------------------------- SETUJUI / TOLAK (admin)
    case 'booking_status': {
        requireRole('admin');
        $id = clip($in['id'] ?? '', 30);
        $approve = (bool)($in['approve'] ?? false);
        $pdo->beginTransaction();
        try {
            $row = q('SELECT * FROM peminjaman WHERE id=?' . $lock, [$id])->fetch();
            if (!$row) { $pdo->rollBack(); fail('Data peminjaman tidak ditemukan.', 404); }
            if ($approve && slotTaken($row['ruang'], $row['tanggal'], $row['sesi'], ['Approved'], $id)) {
                $pdo->rollBack();
                fail("Bentrok: {$row['ruang']} sudah disetujui untuk pemohon lain pada sesi itu.", 409);
            }
            q('UPDATE peminjaman SET status=? WHERE id=?', [$approve ? 'Approved' : 'Rejected', $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        out(['ok' => true, 'ruang' => $row['ruang']]);
    }

    // ---------------------------------------------------- STATUS LAPORAN OB
    case 'ob_status': {
        requireRole('ob', 'admin');
        $id = clip($in['id'] ?? '', 30);
        $rep = q('SELECT status FROM laporan_ob WHERE id=?', [$id])->fetch();
        if (!$rep) fail('Laporan tidak ditemukan.', 404);
        $order = ['Baru', 'Dalam Proses', 'Selesai'];
        $idx = array_search($rep['status'], $order, true);
        $next = $order[(($idx === false ? -1 : $idx) + 1) % count($order)];
        q('UPDATE laporan_ob SET status=? WHERE id=?', [$next, $id]);
        out(['ok' => true, 'status' => $next]);
    }

    // ---------------------------------------------------- CHAT
    case 'chat_send': {
        $u = requireLogin();
        $tab = ($in['tab'] ?? '') === 'ob' ? 'ob' : 'nego';
        $text = clip($in['text'] ?? '', 500);
        if ($text === '') fail('Pesan kosong.');
        $nama = $u['row']['nama'];
        $label = match ($u['role']) {
            'dosen' => "$nama (Dosen)",
            'ob' => "$nama (Petugas OB)",
            'admin' => "$nama (Admin)",
            default => $nama,
        };
        $waktu = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('H:i') . ' WIB';
        q('INSERT INTO chat (tab, pengirim_id, pengirim_nama, pesan, waktu) VALUES (?,?,?,?,?)', [$tab, $u['id'], $label, $text, $waktu]);
        if ($tab === 'ob' && in_array($u['role'], ['mahasiswa', 'dosen'], true)) {
            $pelapor = !empty($u['row']['kelas']) ? "$nama ({$u['row']['kelas']})" : $nama;
            q('INSERT INTO laporan_ob (id, pelapor, lokasi, masalah, status) VALUES (?,?,?,?,?)',
                ['REP-' . strtoupper(base_convert((string)(int)(microtime(true) * 1000), 10, 36)) . random_int(10, 99), $pelapor, 'Laporan via forum', $text, 'Baru']);
        }
        out(['ok' => true]);
    }

    default:
        fail('Aksi tidak dikenal.', 404);
}
