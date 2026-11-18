<?php
declare(strict_types=1);

/**
 * API JSON del prototipo.
 *
 * Dos modos (config.php, 'mode'):
 *  - dev: todas las herramientas de pruebas (inserción manual, saltar comprobaciones, SQL de cada acción).
 *  - staff: solo lo que vería un GM en la web real. Las herramientas se bloquean aquí, no solo en la página.
 *
 * Con usuarios en config.php ('users') hace falta iniciar sesión, y la cuenta del GM es la del usuario: no se
 * puede elegir otra. En modo staff los usuarios son obligatorios.
 */

require_once __DIR__ . '/../src/GmTicketSupport.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// GMTS_CONFIG permite levantar una segunda copia con otra configuración (p. ej. la de modo staff)
$configFile = getenv('GMTS_CONFIG') ?: __DIR__ . '/../config.php';
if (!is_file($configFile)) {
    respond(false, null, ['Falta config.php: copia config.example.php a config.php y rellénalo.']);
}
$config = require $configFile;

$staff = ($config['mode'] ?? 'dev') === 'staff';
$users = $config['users'] ?? [];
$authRequired = $staff || $users;
if ($staff && !$users) {
    respond(false, null, ['El modo staff necesita al menos un usuario en config.php.']);
}

// Sin usuarios (modo dev) no hay sesión: así no se toca la cookie de otra copia abierta en el mismo navegador
if ($authRequired) {
    startSession();
}
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Las acciones que cambian algo solo por POST y con la cabecera propia: un formulario o enlace de otra web no puede
// ponerla, así que no puede actuar con la sesión del usuario (CSRF). La cookie además es SameSite=Strict.
$postActions = ['login', 'logout', 'view', 'respond', 'complete', 'raw'];
if (in_array($action, $postActions, true)
    && ($method !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'gmts')) {
    http_response_code(400);
    respond(false, null, ['Petición no válida.']);
}
$input = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];

// ---------------------------------------------------------------- Sesión (sin BD)

if ($action === 'session') {
    respond(true, sessionInfo($staff, $authRequired));
}

if ($action === 'login') {
    $user = (string) ($input['user'] ?? '');
    $password = (string) ($input['password'] ?? '');
    $throttle = new LoginThrottle(__DIR__ . '/../data/login_attempts.json');

    if ($wait = $throttle->blockedFor($user)) {
        respond(false, null, [sprintf('Demasiados intentos fallidos. Espera %d min.', (int) ceil($wait / 60))]);
    }
    $entry = is_array($users[$user] ?? null) ? $users[$user] : null;
    // Con un usuario inexistente también se calcula un hash, para no delatar por el tiempo qué usuarios existen
    $hash = $entry['password_hash'] ?? password_hash('-', PASSWORD_DEFAULT);
    if (!$entry || !password_verify($password, $hash) || (int) ($entry['account'] ?? 0) <= 0) {
        $throttle->fail($user);
        sleep(1);
        respond(false, null, ['Usuario o contraseña incorrectos.']);
    }
    $throttle->success($user);
    session_regenerate_id(true);
    $_SESSION = ['user' => $user, 'account' => (int) $entry['account'], 'last' => time()];
    respond(true, sessionInfo($staff, $authRequired));
}

if ($action === 'logout') {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    respond(true, sessionInfo($staff, $authRequired));
}

if ($authRequired && empty($_SESSION['user'])) {
    http_response_code(401);
    respond(false, null, ['Inicia sesión.']);
}

// Herramientas de pruebas: en modo staff no existen
if ($staff && $action === 'raw') {
    http_response_code(403);
    respond(false, null, ['No disponible en modo staff.']);
}

// Cuenta del GM: la de la sesión si la hay; si no (modo dev sin usuarios), la que escribe en la página
$sessionAccount = (int) ($_SESSION['account'] ?? 0);
$accountOf = fn (array $data, string $key) => $sessionAccount ?: (int) ($data[$key] ?? 0);

// ---------------------------------------------------------------- BD

try {
    $db = Db::mysql($config['db']);
} catch (PDOException $e) {
    error_log('gmts: ' . $e->getMessage());
    respond(false, null, ['No se puede conectar a la BD' . ($staff ? '.' : ' (¿está abierto el túnel SSH?): ' . $e->getMessage())]);
}

$ts = new GmTicketSupport($db);
$requestedBy = (string) ($config['requested_by'] ?? 'prototipo') . (isset($_SESSION['user']) ? ':' . $_SESSION['user'] : '');

try {
    switch ($action) {
        case 'status':
            $version = $db->query('SELECT VERSION()')->fetchColumn();
            $hasTable = (bool) $db->query("SHOW TABLES LIKE 'gm_ticket_support'")->fetch();
            respond(true, ['version' => $staff ? null : $version, 'table' => $hasTable,
                           'max_bytes' => GmTicketSupport::MAX_RESPONSE_BYTES, 'warn_bytes' => GmTicketSupport::WARN_RESPONSE_BYTES],
                $hasTable ? [] : ['Falta la tabla gm_ticket_support: aplica el SQL 2026_11_16_100_characters_gm_ticket_support.sql.']);

        case 'gm_characters':
            respond(true, $ts->accountCharacters($accountOf($_GET, 'account')));

        case 'tickets':
            respond(true, $ts->listTickets(!empty($_GET['all'])));

        case 'search':
            $q = trim((string) ($_GET['q'] ?? ''));
            if (mb_strlen($q) < 2) {
                respond(false, null, ['Escribe al menos 2 letras del nombre.']);
            }
            respond(true, $ts->searchTickets(($_GET['by'] ?? '') === 'gm' ? 'gm' : 'player', $q));

        case 'player_tickets':
            respond(true, $ts->playerTickets((int) ($_GET['guid'] ?? 0), (int) ($_GET['exclude'] ?? 0)));

        case 'ticket':
            $ticket = $ts->getTicket((int) ($_GET['id'] ?? 0));
            $ticket ? respond(true, $ticket) : respond(false, null, ['El ticket no existe.']);

        case 'view':
        case 'respond':
        case 'complete':
            $gmGuid = (int) ($input['gm_guid'] ?? 0);
            $ticketId = (int) ($input['ticket_id'] ?? 0);
            $playerGuid = (int) ($input['player_guid'] ?? 0);
            $text = GmTicketSupport::normalizeText((string) ($input['text'] ?? ''));
            $skipChecks = !$staff && !empty($input['skip_checks']);
            $type = ['view' => GmTicketSupport::ACTION_VIEW, 'respond' => GmTicketSupport::ACTION_RESPONSE_APPEND,
                     'complete' => GmTicketSupport::ACTION_COMPLETE][$action];

            // Esta comprobación no se salta nunca: es la que impide actuar con el personaje de otro
            if (!$ts->isCharacterOfAccount($gmGuid, $accountOf($input, 'account'))) {
                respond(false, null, ['El personaje GM no es de tu cuenta.']);
            }

            $warnings = [];
            if (!$skipChecks) {
                [$errors, $warnings] = $ts->checkAction($ts->getTicket($ticketId), $playerGuid, $type, $gmGuid, $text);
                if ($errors) {
                    respond(false, null, $errors);
                }
            }

            $id = $ts->insertRequest($ticketId, $playerGuid, $type, $gmGuid, $type === GmTicketSupport::ACTION_VIEW ? null : $text, $requestedBy);
            respond(true, ['request' => $ts->getRequest($id), 'warnings' => $warnings]);

        case 'raw':
            // Inserción manual sin comprobar nada (acciones de la v2, GM de otra cuenta, textos inválidos...)
            $text = (string) ($input['text'] ?? '');
            $id = $ts->insertRequest((int) ($input['ticket_id'] ?? 0), (int) ($input['player_guid'] ?? 0), (int) ($input['action'] ?? 0),
                (int) ($input['gm_guid'] ?? 0), $text === '' ? null : $text, $requestedBy);
            respond(true, ['request' => $ts->getRequest($id), 'warnings' => []]);

        case 'requests':
            $ticketId = (int) ($_GET['ticket'] ?? 0);
            respond(true, $ts->listRequests($ticketId ?: null, 50));

        default:
            respond(false, null, ['Acción desconocida.']);
    }
} catch (PDOException $e) {
    error_log('gmts: ' . $e->getMessage());
    respond(false, null, ['Error de BD' . ($staff ? '.' : ': ' . $e->getMessage())]);
}

// ---------------------------------------------------------------- Funciones

function startSession(): void
{
    // Detrás de un túnel HTTPS (Cloudflare, Tailscale) la conexión con el navegador es HTTPS aunque llegue por HTTP
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    // Las cookies no distinguen puertos: con dos copias en el mismo equipo (8091 y 8092) cada una necesita su
    // propio nombre, o una pisa la sesión de la otra
    session_name('gmts' . (int) ($_SERVER['SERVER_PORT'] ?? 0));
    session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => $https]);
    ini_set('session.use_strict_mode', '1');
    session_start();

    // La sesión caduca tras 2 horas sin uso
    if (isset($_SESSION['last']) && time() - $_SESSION['last'] > 7200) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    if (isset($_SESSION['user'])) {
        $_SESSION['last'] = time();
    }
}

function sessionInfo(bool $staff, bool $authRequired): array
{
    return [
        'mode'          => $staff ? 'staff' : 'dev',
        'auth_required' => $authRequired,
        'user'          => $_SESSION['user'] ?? null,
        'account'       => isset($_SESSION['account']) ? (int) $_SESSION['account'] : null,
    ];
}

function respond(bool $ok, mixed $data, array $errors = []): never
{
    global $staff;
    echo json_encode([
        'ok'     => $ok,
        'data'   => $data,
        'errors' => $errors,
        'sql'    => empty($staff) && class_exists('Db', false) ? Db::log() : [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Límite de intentos de inicio de sesión, guardado en un fichero. Por usuario (5 fallos en 15 min lo bloquean
 * 15 min) y en total (20 fallos en 15 min bloquean a todos), porque detrás de un túnel todas las conexiones
 * llegan desde la misma IP.
 */
final class LoginThrottle
{
    private const WINDOW = 900;
    private const PER_USER = 5;
    private const GLOBAL = 20;

    public function __construct(private string $file)
    {
    }

    public function blockedFor(string $user): int
    {
        return $this->update(function (array &$d) use ($user) {
            $wait = 0;
            foreach ([$this->key($user) => self::PER_USER, '*' => self::GLOBAL] as $k => $max) {
                $fails = $d[$k] ?? [];
                if (count($fails) >= $max) {
                    $wait = max($wait, self::WINDOW - (time() - min($fails)));
                }
            }
            return $wait;
        });
    }

    public function fail(string $user): void
    {
        $this->update(function (array &$d) use ($user) {
            $d[$this->key($user)][] = time();
            $d['*'][] = time();
            return 0;
        });
    }

    public function success(string $user): void
    {
        $this->update(function (array &$d) use ($user) {
            unset($d[$this->key($user)]);
            return 0;
        });
    }

    private function key(string $user): string
    {
        return 'u:' . hash('sha256', $user);
    }

    private function update(callable $fn): int
    {
        if (!is_dir(dirname($this->file))) {
            mkdir(dirname($this->file), 0700, true);
        }
        $h = fopen($this->file, 'c+');
        flock($h, LOCK_EX);
        $data = json_decode(stream_get_contents($h) ?: '{}', true) ?: [];
        foreach ($data as $k => $times) {
            $data[$k] = array_values(array_filter($times, fn ($t) => time() - $t < self::WINDOW));
            if (!$data[$k]) {
                unset($data[$k]);
            }
        }
        $result = $fn($data);
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode($data));
        flock($h, LOCK_UN);
        fclose($h);
        return $result;
    }
}
