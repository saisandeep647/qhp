<?php
// Simple REST API for business verifier.
// Endpoints:
// GET  /api.php/entries[?status=pending|accepted|rejected]   -> list
// GET  /api.php/entries/{id}                                -> get single
// POST /api.php/entries                                     -> create (JSON body: category,businessName,location,businessPhone)
// PUT  /api.php/entries/{id}                                -> update (JSON body partial)
// POST /api.php/entries/{id}/verify                         -> verify (ownerName,personalPhone) -> sets verified=1
// POST /api.php/entries/{id}/accept                         -> set status=accepted
// POST /api.php/entries/{id}/reject                         -> set status=rejected + reason
// DELETE /api.php/entries/{id}                              -> delete

header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/config.php';

function pdo_connect($config) {
    if ($config->DB_TYPE === 'sqlite') {
        $pdo = new PDO('sqlite:' . $config->SQLITE_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON;');
    } else {
        $pdo = new PDO($config->MYSQL_DSN, $config->MYSQL_USER, $config->MYSQL_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
    return $pdo;
}

$method = $_SERVER['REQUEST_METHOD'];
// get path after api.php, using PATH_INFO if available, else parse REQUEST_URI
$path = '';
if (isset($_SERVER['PATH_INFO'])) {
    $path = $_SERVER['PATH_INFO'];
} else {
    // fallback: SCRIPT_NAME + path in REQUEST_URI
    $script = $_SERVER['SCRIPT_NAME'];
    $uri = $_SERVER['REQUEST_URI'];
    $path = substr($uri, strlen($script));
    if ($path === false) $path = '/';
}
$path = trim($path, '/');
$parts = $path === '' ? [] : explode('/', $path);

// helper to read JSON body
function jsonBody() {
    $text = file_get_contents('php://input');
    if (!$text) return null;
    $data = json_decode($text, true);
    return $data === null ? null : $data;
}

// basic id generator
function uid() {
    return bin2hex(random_bytes(8));
}

// send response
function send($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = pdo_connect($config);

    // Routing
    // root: /entries or /entries/{id} or /entries/{id}/action
    if (count($parts) === 0) {
        send(['ok' => true, 'msg' => 'API running']);
    }

    if ($parts[0] !== 'entries') {
        send(['error' => 'Unknown resource'], 404);
    }

    // /entries
    if (count($parts) === 1) {
        if ($method === 'GET') {
            // list
            $status = isset($_GET['status']) ? $_GET['status'] : null;
            if ($status) {
                $stmt = $pdo->prepare('SELECT * FROM entries WHERE status = :status ORDER BY createdAt DESC');
                $stmt->execute([':status' => $status]);
            } else {
                $stmt = $pdo->query('SELECT * FROM entries ORDER BY createdAt DESC');
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            send($rows);
        } elseif ($method === 'POST') {
            $body = jsonBody();
            if (!is_array($body)) send(['error'=>'Invalid JSON body'], 400);
            if (empty($body['category']) || empty($body['businessName'])) {
                send(['error'=>'category and businessName are required'], 400);
            }
            $id = uid();
            $now = gmdate('c');
            $stmt = $pdo->prepare('INSERT INTO entries
                (id, category, businessName, ownerName, location, personalPhone, businessPhone, verified, status, rejectReason, createdAt, updatedAt)
                VALUES (:id,:category,:businessName,:ownerName,:location,:personalPhone,:businessPhone,:verified,:status,:rejectReason,:createdAt,:updatedAt)
            ');
            $stmt->execute([
                ':id'=>$id,
                ':category'=>$body['category'],
                ':businessName'=>$body['businessName'],
                ':ownerName'=>isset($body['ownerName']) ? $body['ownerName'] : null,
                ':location'=>isset($body['location']) ? $body['location'] : null,
                ':personalPhone'=>isset($body['personalPhone']) ? $body['personalPhone'] : null,
                ':businessPhone'=>isset($body['businessPhone']) ? $body['businessPhone'] : null,
                ':verified'=>!empty($body['verified']) ? 1 : 0,
                ':status'=>isset($body['status']) ? $body['status'] : 'pending',
                ':rejectReason'=>isset($body['rejectReason']) ? $body['rejectReason'] : null,
                ':createdAt'=>$now,
                ':updatedAt'=>$now
            ]);
            send(['id'=>$id], 201);
        } else {
            send(['error'=>'Method not allowed'], 405);
        }
    }

    // /entries/{id} or /entries/{id}/action
    $id = $parts[1] ?? null;
    if (!$id) send(['error'=>'Missing id'], 400);

    if (count($parts) === 2) {
        if ($method === 'GET') {
            $stmt = $pdo->prepare('SELECT * FROM entries WHERE id = :id');
            $stmt->execute([':id'=>$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) send(['error'=>'Not found'], 404);
            send($row);
        } elseif ($method === 'PUT' || $method === 'POST') {
            // allow both PUT and POST for update
            $body = jsonBody();
            if (!is_array($body)) send(['error'=>'Invalid JSON'], 400);
            $stmt = $pdo->prepare('SELECT * FROM entries WHERE id = :id');
            $stmt->execute([':id'=>$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) send(['error'=>'Not found'], 404);

            $now = gmdate('c');
            $upd = $pdo->prepare('UPDATE entries SET
                category=:category, businessName=:businessName, ownerName=:ownerName,
                location=:location, personalPhone=:personalPhone, businessPhone=:businessPhone,
                verified=:verified, status=:status, rejectReason=:rejectReason, updatedAt=:updatedAt
                WHERE id = :id
            ');
            $upd->execute([
                ':category'=> $body['category'] ?? $existing['category'],
                ':businessName'=> $body['businessName'] ?? $existing['businessName'],
                ':ownerName'=> array_key_exists('ownerName', $body) ? $body['ownerName'] : $existing['ownerName'],
                ':location'=> array_key_exists('location', $body) ? $body['location'] : $existing['location'],
                ':personalPhone'=> array_key_exists('personalPhone', $body) ? $body['personalPhone'] : $existing['personalPhone'],
                ':businessPhone'=> array_key_exists('businessPhone', $body) ? $body['businessPhone'] : $existing['businessPhone'],
                ':verified'=> array_key_exists('verified', $body) ? ($body['verified'] ? 1 : 0) : $existing['verified'],
                ':status'=> $body['status'] ?? $existing['status'],
                ':rejectReason'=> array_key_exists('rejectReason', $body) ? $body['rejectReason'] : $existing['rejectReason'],
                ':updatedAt'=>$now,
                ':id'=>$id
            ]);
            send(['ok'=>true]);
        } elseif ($method === 'DELETE') {
            $del = $pdo->prepare('DELETE FROM entries WHERE id = :id');
            $del->execute([':id'=>$id]);
            send(['ok'=>true]);
        } else {
            send(['error'=>'Method not allowed'], 405);
        }
    }

    // actions: /entries/{id}/verify , /accept , /reject
    $action = $parts[2] ?? null;
    if (!$action) send(['error'=>'Missing action'], 400);

    if ($action === 'verify' && $method === 'POST') {
        $body = jsonBody();
        if (!is_array($body)) send(['error'=>'Invalid JSON'], 400);
        if (empty($body['ownerName']) || empty($body['personalPhone'])) {
            send(['error'=>'ownerName and personalPhone required'], 400);
        }
        $now = gmdate('c');
        $stmt = $pdo->prepare('UPDATE entries SET ownerName = :ownerName, personalPhone = :personalPhone, verified = 1, updatedAt = :updatedAt WHERE id = :id');
        $stmt->execute([
            ':ownerName'=>$body['ownerName'],
            ':personalPhone'=>$body['personalPhone'],
            ':updatedAt'=>$now,
            ':id'=>$id
        ]);
        send(['ok'=>true]);
    }

    if ($action === 'accept' && $method === 'POST') {
        $now = gmdate('c');
        $stmt = $pdo->prepare('UPDATE entries SET status = "accepted", rejectReason = NULL, updatedAt = :updatedAt WHERE id = :id');
        $stmt->execute([':updatedAt'=>$now, ':id'=>$id]);
        send(['ok'=>true]);
    }

    if ($action === 'reject' && $method === 'POST') {
        $body = jsonBody();
        if (!is_array($body) || empty($body['reason'])) send(['error'=>'reason required'], 400);
        $now = gmdate('c');
        $stmt = $pdo->prepare('UPDATE entries SET status = "rejected", rejectReason = :reason, updatedAt = :updatedAt WHERE id = :id');
        $stmt->execute([':reason'=>$body['reason'], ':updatedAt'=>$now, ':id'=>$id]);
        send(['ok'=>true]);
    }

    send(['error'=>'Unknown action'], 404);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'detail' => $e->getMessage()]);
}
