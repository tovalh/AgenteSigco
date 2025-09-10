<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Client-ID, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// --- CONFIGURACIÓN DE LA CONEXIÓN A LA BASE DE DATOS (MYSQL) ---
$host = $_ENV['MYSQLHOST'] ?? 'localhost';
$dbname = $_ENV['MYSQLDATABASE'] ?? 'railway';
$username = $_ENV['MYSQLUSER'] ?? 'root';
$password = $_ENV['MYSQLPASSWORD'] ?? '';
$port = $_ENV['MYSQLPORT'] ?? 3306;

$dsn = "mysql:host=$host;port=$port;dbname=$dbname";

// --- PUNTO DE DEBUG (Útil para comprobar las variables) ---
if (($_GET['action'] ?? '') === 'debug') {
    echo json_encode([
        'env_vars' => [
            'MYSQLHOST' => $_ENV['MYSQLHOST'] ?? 'NOT_SET',
            'MYSQLDATABASE' => $_ENV['MYSQLDATABASE'] ?? 'NOT_SET',
            'MYSQLUSER' => $_ENV['MYSQLUSER'] ?? 'NOT_SET',
            'MYSQLPASSWORD' => isset($_ENV['MYSQLPASSWORD']) ? 'SET (' . strlen($_ENV['MYSQLPASSWORD']) . ' chars)' : 'NOT_SET',
            'MYSQLPORT' => $_ENV['MYSQLPORT'] ?? 'NOT_SET'
        ],
        'resolved_vars' => [
            'dsn' => $dsn,
            'username' => $username,
            'password' => isset($password) && $password ? 'SET (' . strlen($password) . ' chars)' : 'NOT_SET'
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
          CREATE TABLE IF NOT EXISTS agente_clients (
              id INT AUTO_INCREMENT PRIMARY KEY,
              client_id VARCHAR(64) UNIQUE NOT NULL,
              license_key VARCHAR(32) NOT NULL,
              hostname VARCHAR(255),
              platform VARCHAR(50),
              version VARCHAR(20),
              system_info JSON,
              stats JSON,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              last_seen TIMESTAMP,
              active BOOLEAN DEFAULT 1,
              status VARCHAR(20) DEFAULT 'active',
              heartbeat_count INT DEFAULT 0
          )
      ");

    $pdo->exec("
          CREATE TABLE IF NOT EXISTS agente_commands (
              id INT AUTO_INCREMENT PRIMARY KEY,
              client_id VARCHAR(64) NOT NULL,
              command_type VARCHAR(50) NOT NULL,
              command_data JSON,
              status VARCHAR(20) DEFAULT 'pending',
              result JSON,
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              executed_at TIMESTAMP NULL
          )
      ");

} catch (PDOException $e) {
    http_response_code(500);
    // Este mensaje de error será mucho más detallado
    echo json_encode(['error' => 'Database connection failed.', 'details' => $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? '';

switch($path) {
    case 'register':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data['clientId']) {
            http_response_code(400);
            echo json_encode(['error' => 'Client ID required']);
            break;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM agente_clients WHERE client_id = ?");
            $stmt->execute([$data['clientId']]);
            $existing = $stmt->fetch();

            if ($existing) {
                $stmt = $pdo->prepare("
                      UPDATE agente_clients
                      SET last_seen = NOW(), version = ?, hostname = ?, system_info = ?, status = 'active'
                      WHERE client_id = ?
                  ");
                $stmt->execute([
                    $data['version'],
                    $data['hostname'],
                    json_encode($data['systemInfo'] ?? []),
                    $data['clientId']
                ]);

                echo json_encode([
                    'success' => true,
                    'licenseKey' => $existing['license_key'],
                    'active' => $existing['active'] == 1,
                    'message' => 'Client updated'
                ]);
            } else {
                $licenseKey = strtoupper(substr(md5(uniqid() . microtime()), 0, 32));

                $stmt = $pdo->prepare("
                      INSERT INTO agente_clients
                      (client_id, license_key, hostname, platform, version, system_info, created_at, last_seen, active, status)
                      VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), 1, 'active')
                  ");
                $stmt->execute([
                    $data['clientId'],
                    $licenseKey,
                    $data['hostname'],
                    $data['platform'],
                    $data['version'],
                    json_encode($data['systemInfo'] ?? [])
                ]);

                echo json_encode([
                    'success' => true,
                    'licenseKey' => $licenseKey,
                    'active' => true,
                    'message' => 'Client registered successfully'
                ]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Registration failed: ' . $e->getMessage()]);
        }
        break;

    case 'heartbeat':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data['clientId'] || !$data['licenseKey']) {
            http_response_code(400);
            echo json_encode(['error' => 'Client ID and License Key required']);
            break;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM agente_clients WHERE client_id = ? AND license_key = ?");
            $stmt->execute([$data['clientId'], $data['licenseKey']]);
            $client = $stmt->fetch();

            if (!$client) {
                echo json_encode([
                    'success' => false,
                    'error' => 'LICENSE_REVOKED',
                    'message' => 'Invalid license'
                ]);
                break;
            }

            if ($client['active'] != 1) {
                echo json_encode([
                    'success' => false,
                    'error' => 'CLIENT_BLOCKED',
                    'message' => 'Client blocked by administrator'
                ]);
                break;
            }

            $stmt = $pdo->prepare("
                  UPDATE agente_clients
                  SET last_seen = NOW(),
                      system_info = ?,
                      stats = ?,
                      status = 'active',
                      heartbeat_count = heartbeat_count + 1
                  WHERE client_id = ?
              ");
            $stmt->execute([
                json_encode($data['systemInfo'] ?? []),
                json_encode($data['stats'] ?? []),
                $data['clientId']
            ]);

            $stmt = $pdo->prepare("
                  SELECT * FROM agente_commands
                  WHERE client_id = ? AND status = 'pending'
                  ORDER BY created_at ASC
              ");
            $stmt->execute([$data['clientId']]);
            $commands = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($commands) {
                $commandIds = array_column($commands, 'id');
                $placeholders = str_repeat('?,', count($commandIds) - 1) . '?';
                $stmt = $pdo->prepare("UPDATE agente_commands SET status = 'sent' WHERE id IN ($placeholders)");
                $stmt->execute($commandIds);
            }

            echo json_encode([
                'success' => true,
                'active' => true,
                'timestamp' => date('c'),
                'commands' => $commands,
                'updateAvailable' => false,
                'updateInfo' => null
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Heartbeat failed: ' . $e->getMessage()]);
        }
        break;

    case 'dashboard':
        try {
            $stmt = $pdo->query("
                  SELECT
                      client_id,
                      hostname,
                      platform,
                      version,
                      created_at,
                      last_seen,
                      active,
                      status,
                      heartbeat_count,
                      system_info,
                      stats
                  FROM agente_clients
                  ORDER BY last_seen DESC
              ");
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $now = time();
            $activeCount = 0;
            $offlineCount = 0;

            foreach ($clients as &$client) {
                $lastSeen = strtotime($client['last_seen']);
                $minutesAgo = ($now - $lastSeen) / 60;

                if ($minutesAgo <= 10 && $client['active']) {
                    $activeCount++;
                } else {
                    $offlineCount++;
                }

                $client['system_info'] = json_decode($client['system_info'] ?? '{}', true);
                $client['stats'] = json_decode($client['stats'] ?? '{}', true);
            }

            $stats = [
                'total_clients' => count($clients),
                'active_clients' => $activeCount,
                'offline_clients' => $offlineCount,
                'blocked_clients' => count(array_filter($clients, fn($c) => $c['active'] == 0))
            ];

            echo json_encode([
                'success' => true,
                'clients' => $clients,
                'stats' => $stats,
                'timestamp' => date('c')
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Dashboard failed: ' . $e->getMessage()]);
        }
        break;

    case 'send-command':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        try {
            $stmt = $pdo->prepare("
                  INSERT INTO agente_commands (client_id, command_type, command_data, status)
                  VALUES (?, ?, ?, 'pending')
              ");
            $stmt->execute([
                $data['clientId'],
                $data['commandType'],
                json_encode($data['commandData'] ?? [])
            ]);

            echo json_encode(['success' => true, 'message' => 'Command sent']);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Command failed: ' . $e->getMessage()]);
        }
        break;

    case 'block-client':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? 'block';
        $active = $action === 'block' ? 0 : 1;

        try {
            $stmt = $pdo->prepare("UPDATE agente_clients SET active = ? WHERE client_id = ?");
            $stmt->execute([$active, $data['clientId']]);

            echo json_encode(['success' => true, 'message' => 'Client ' . $action . 'ed']);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Block action failed: ' . $e->getMessage()]);
        }
        break;

    default:
        if (empty($path)) {
            readfile('dashboard.html');
            exit;
        }

        http_response_code(404);
        echo json_encode([
            'error' => 'Endpoint not found',
            'available' => [
                '?action=register',
                '?action=heartbeat',
                '?action=dashboard',
                '?action=send-command',
                '?action=block-client'
            ]
        ]);
}
