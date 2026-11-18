<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * Lógica de la web para gm_ticket_support (atender tickets de GM desde la web). Sirve de referencia para la web
 * real, que puede implementarla a su manera.
 *
 * Reglas del contrato:
 *  - gm_ticket solo se LEE. Nunca INSERT, UPDATE ni DELETE: el worldserver tiene los tickets en memoria y pisaría
 *    cualquier cambio de la web en su siguiente guardado.
 *  - En gm_ticket_support la web solo hace SELECT e INSERT. status, result_code y processed_at los escribe el
 *    worldserver.
 *  - gm_guid tiene que ser un personaje de la cuenta del GM que ha iniciado sesión en la web. El worldserver
 *    comprueba los permisos de esa cuenta, pero no sabe quién está delante del panel: eso lo garantiza la web.
 *  - Las comprobaciones previas (check*) sirven para avisar al momento. El worldserver las repite siempre y su
 *    result_code es el que manda.
 */
final class GmTicketSupport
{
    public const ACTION_VIEW            = 1;
    public const ACTION_RESPONSE_APPEND = 5;
    public const ACTION_COMPLETE        = 6;

    public const STATUS_PENDING = 0;
    public const STATUS_DONE    = 1;
    public const STATUS_ERROR   = 2;

    /** Máximo de la respuesta entera (la que ya tiene el ticket más el texto nuevo), en bytes UTF-8. */
    public const MAX_RESPONSE_BYTES = 15996;
    /** A partir de aquí el servidor envía la respuesta en varios trozos y una tilde puede verse mal en el corte. */
    public const WARN_RESPONSE_BYTES = 3999;

    /** Acciones. Cualquier otro número lo rechaza el servidor (código 2). */
    public const ACTION_NAMES = [
        1 => 'Ver',
        5 => 'Responder',
        6 => 'Completar',
    ];

    public const STATUS_NAMES = [
        self::STATUS_PENDING => 'Pendiente',
        self::STATUS_DONE    => 'Hecho',
        self::STATUS_ERROR   => 'Error',
    ];

    /** result_code → mensaje para el GM. Mismos valores que el enum GmTicketSupportResult del worldserver. */
    public const RESULT_MESSAGES = [
        0  => 'Hecho.',
        1  => 'El ticket ya no existe.',
        2  => 'Los datos de la petición no son válidos.',
        3  => 'La petición ha caducado sin procesarse (¿está parado el worldserver?). No se ha hecho nada.',
        4  => 'Ese número de ticket ahora es de otro jugador (los números se reutilizan). Recarga la lista.',
        5  => 'El personaje GM no existe.',
        6  => 'Tu cuenta no tiene permiso para esta acción en este reino.',
        7  => 'Se ha hecho en el servidor, pero no se pudo guardar en la BD. NO lo repitas (se duplicaría) y avisa a un administrador.',
        20 => 'El ticket está cerrado.',
        21 => 'El ticket ya está completado: la respuesta ya se entregó al jugador. Si mandabas un texto nuevo, no se ha enviado; escríbele por correo.',
        22 => 'El ticket está asignado a otro GM.',
        40 => 'Falta el texto de la respuesta.',
        41 => 'La respuesta es demasiado larga (máximo 15.996 bytes contando lo que ya tiene el ticket).',
        42 => 'El texto tiene caracteres no permitidos (emojis, "|" o caracteres de control).',
    ];

    public function __construct(private Db $db)
    {
    }

    // ------------------------------------------------------------------ GM

    /**
     * Personajes de una cuenta, para elegir con cuál actúa el GM. En la web real la cuenta es la de la sesión
     * iniciada; aquí se escribe a mano.
     */
    public function accountCharacters(int $accountId): array
    {
        return array_map(fn (array $r) => ['guid' => (int) $r['guid'], 'name' => $r['name'], 'level' => (int) $r['level'], 'online' => (bool) $r['online']],
            $this->db->query(
                'SELECT guid, name, level, online FROM characters WHERE account = ? AND deleteDate IS NULL ORDER BY name',
                [$accountId]
            )->fetchAll());
    }

    /** Comprueba que el personaje GM es de la cuenta de la sesión (la web real debe hacerlo siempre). */
    public function isCharacterOfAccount(int $guid, int $accountId): bool
    {
        return (bool) $this->db->query(
            'SELECT 1 FROM characters WHERE guid = ? AND account = ? AND deleteDate IS NULL',
            [$guid, $accountId]
        )->fetchColumn();
    }

    // ------------------------------------------------------------------ Tickets (solo lectura)

    /**
     * Tickets abiertos (sin completar y sin cerrar), estén o no conectados los jugadores: lo que ve el GM con
     * .ticket list. Con $all = true, también los completados y cerrados (los más recientes primero).
     */
    public function listTickets(bool $all, int $limit = 100): array
    {
        $where = $all ? '' : 'WHERE t.type = 0 AND t.completed = 0';
        $order = $all ? 't.id DESC' : 't.id';
        $rows = $this->db->query(
            "SELECT t.id, t.type, t.playerGuid, t.name, LEFT(t.description, 100) AS description,
                    FROM_UNIXTIME(t.createTime) AS created, FROM_UNIXTIME(t.lastModifiedTime) AS modified,
                    t.assignedTo, a.name AS assigned_name, t.completed, t.viewed, t.escalated,
                    LENGTH(t.response) AS response_bytes, COALESCE(p.online, 0) AS online
             FROM gm_ticket t
             LEFT JOIN characters a ON a.guid = t.assignedTo
             LEFT JOIN characters p ON p.guid = t.playerGuid
             $where
             ORDER BY $order
             LIMIT ?",
            [$limit]
        )->fetchAll();

        return array_map(fn (array $r) => self::ticketRow($r), $rows);
    }

    /**
     * Busca tickets de cualquier estado por el nombre del jugador que lo creó o del último GM que respondió
     * (gm_ticket.responsedByName). Coincidencia por el principio del nombre, los más recientes primero.
     * Ojo: si responden varios GM, gm_ticket solo guarda el último; y .ticket reset borra los tickets cerrados.
     */
    public function searchTickets(string $by, string $name, int $limit = 50): array
    {
        $column = $by === 'gm' ? 't.responsedByName' : 't.name';
        $like = addcslashes(trim($name), '\%_') . '%';
        $rows = $this->db->query(
            "SELECT t.id, t.type, t.playerGuid, t.name, LEFT(t.description, 100) AS description,
                    FROM_UNIXTIME(t.createTime) AS created, FROM_UNIXTIME(t.lastModifiedTime) AS modified,
                    t.assignedTo, a.name AS assigned_name, t.completed, t.viewed, t.escalated, t.responsedByName,
                    LEFT(t.response, 100) AS response_preview, LENGTH(t.response) AS response_bytes,
                    COALESCE(p.online, 0) AS online
             FROM gm_ticket t
             LEFT JOIN characters a ON a.guid = t.assignedTo
             LEFT JOIN characters p ON p.guid = t.playerGuid
             WHERE $column LIKE ?
             ORDER BY t.id DESC
             LIMIT ?",
            [$like, $limit]
        )->fetchAll();

        return array_map(fn (array $r) => self::ticketRow($r), $rows);
    }

    /** Otros tickets del mismo personaje, para seguir el caso cuando pone varios. */
    public function playerTickets(int $playerGuid, int $excludeId, int $limit = 20): array
    {
        $rows = $this->db->query(
            'SELECT t.id, t.type, t.playerGuid, t.name, LEFT(t.description, 100) AS description,
                    FROM_UNIXTIME(t.createTime) AS created, t.assignedTo, t.completed, t.viewed, t.escalated,
                    t.responsedByName, LEFT(t.response, 100) AS response_preview, LENGTH(t.response) AS response_bytes,
                    0 AS online
             FROM gm_ticket t
             WHERE t.playerGuid = ? AND t.id <> ?
             ORDER BY t.id DESC
             LIMIT ?',
            [$playerGuid, $excludeId, $limit]
        )->fetchAll();

        return array_map(fn (array $r) => self::ticketRow($r), $rows);
    }

    public function getTicket(int $id): ?array
    {
        $row = $this->db->query(
            'SELECT t.id, t.type, t.playerGuid, t.name, t.description, t.comment, t.response, t.responsedByName,
                    FROM_UNIXTIME(t.createTime) AS created, FROM_UNIXTIME(t.lastModifiedTime) AS modified,
                    t.mapId, t.assignedTo, a.name AS assigned_name, t.closedBy, t.completed, t.viewed, t.escalated,
                    t.needMoreHelp, LENGTH(t.response) AS response_bytes, COALESCE(p.online, 0) AS online
             FROM gm_ticket t
             LEFT JOIN characters a ON a.guid = t.assignedTo
             LEFT JOIN characters p ON p.guid = t.playerGuid
             WHERE t.id = ?',
            [$id]
        )->fetch();

        return $row ? self::ticketRow($row) : null;
    }

    private static function ticketRow(array $r): array
    {
        foreach (['id', 'type', 'playerGuid', 'assignedTo', 'completed', 'viewed', 'escalated', 'response_bytes', 'online'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['closed'] = $r['type'] !== 0;
        $r['state'] = $r['closed'] ? 'Cerrado' : ($r['completed'] ? 'Completado' : 'Abierto');
        return $r;
    }

    // ------------------------------------------------------------------ Comprobaciones previas

    /** Lo mismo que hace el servidor con el texto: \r\n y \r pasan a \n. */
    public static function normalizeText(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * Comprobaciones del texto, en el mismo orden que el servidor. Devuelve [errores, avisos].
     * Los límites son en bytes UTF-8 (strlen), no en caracteres: una tilde cuenta 2.
     */
    public static function checkText(string $text, int $currentResponseBytes): array
    {
        $errors = [];
        $warnings = [];
        $bytes = strlen($text);

        if (!mb_check_encoding($text, 'UTF-8')) {
            $errors[] = 'El texto no es UTF-8 válido.';
        } else {
            if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $text)) {
                $errors[] = 'El texto tiene emojis u otros caracteres de 4 bytes, que el ticket no puede guardar.';
            }
            if (str_contains($text, '|')) {
                $errors[] = 'El texto tiene el carácter "|", que el cliente del juego interpreta como código de color o enlace.';
            }
            if (preg_match('/[\x00-\x09\x0B-\x1F\x7F\x{80}-\x{9F}]/u', $text)) {
                $errors[] = 'El texto tiene caracteres de control (tabuladores u otros invisibles). Solo se admiten saltos de línea.';
            }
        }

        $total = $currentResponseBytes + $bytes;
        if ($total > self::MAX_RESPONSE_BYTES) {
            $errors[] = sprintf('La respuesta quedaría en %s bytes y el máximo es %s.', number_format($total, 0, ',', '.'),
                number_format(self::MAX_RESPONSE_BYTES, 0, ',', '.'));
        } elseif ($total > self::WARN_RESPONSE_BYTES) {
            $warnings[] = sprintf('La respuesta quedaría en %s bytes: el juego la enviará en varios trozos y una tilde o eñe '
                . 'puede verse mal justo en el corte.', number_format($total, 0, ',', '.'));
        }

        return [$errors, $warnings];
    }

    /**
     * Comprobaciones previas de una acción sobre un ticket, con los datos que ve la web. Devuelve [errores, avisos].
     * El servidor las repite con lo que tiene en memoria, que es lo que vale.
     */
    public function checkAction(?array $ticket, int $playerGuid, int $action, int $gmGuid, string $text): array
    {
        if (!$ticket) {
            return [['El ticket ya no existe.'], []];
        }
        if ($ticket['playerGuid'] !== $playerGuid) {
            return [['Ese número de ticket ahora es de otro jugador. Recarga la lista.'], []];
        }
        if ($ticket['closed']) {
            return [['El ticket está cerrado.'], []];
        }
        if ($ticket['completed']) {
            return [['El ticket ya está completado: la respuesta ya se entregó al jugador. Si hay que corregir algo, escríbele por correo.'], []];
        }
        if ($action === self::ACTION_VIEW) {
            return [[], []];
        }

        if ($text === '') {
            if ($action === self::ACTION_RESPONSE_APPEND || $ticket['response_bytes'] === 0) {
                return [['Escribe la respuesta.'], []];
            }
            return [[], []]; // completar con la respuesta que ya tiene
        }

        [$errors, $warnings] = self::checkText($text, $ticket['response_bytes']);
        if ($errors) {
            return [$errors, $warnings];
        }

        if ($ticket['assignedTo'] && $ticket['assignedTo'] !== $gmGuid) {
            return [['El ticket está asignado a ' . ($ticket['assigned_name'] ?? 'otro GM') . '.'], $warnings];
        }
        return [[], $warnings];
    }

    // ------------------------------------------------------------------ Peticiones

    public function insertRequest(int $ticketId, int $playerGuid, int $action, int $gmGuid, ?string $text, string $requestedBy): int
    {
        $this->db->query(
            'INSERT INTO gm_ticket_support (ticket_id, player_guid, action, gm_guid, text, requested_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$ticketId, $playerGuid, $action, $gmGuid, $text === '' ? null : $text, $requestedBy]
        );
        return $this->db->lastInsertId();
    }

    public function getRequest(int $id): ?array
    {
        $rows = $this->requests('WHERE s.id = ?', [$id], 1);
        return $rows[0] ?? null;
    }

    /** Últimas peticiones, de un ticket o de todos. Sirve también de historial del ticket. */
    public function listRequests(?int $ticketId, int $limit = 50): array
    {
        return $ticketId ? $this->requests('WHERE s.ticket_id = ?', [$ticketId], $limit) : $this->requests('', [], $limit);
    }

    private function requests(string $where, array $params, int $limit): array
    {
        $rows = $this->db->query(
            "SELECT s.id, s.ticket_id, s.player_guid, s.action, s.gm_guid, g.name AS gm_name,
                    LEFT(s.text, 120) AS text, LENGTH(s.text) AS text_bytes, s.status, s.result_code,
                    s.created_at, s.processed_at,
                    TIMESTAMPDIFF(SECOND, s.created_at, COALESCE(s.processed_at, NOW())) AS wait_seconds
             FROM gm_ticket_support s
             LEFT JOIN characters g ON g.guid = s.gm_guid
             $where
             ORDER BY s.id DESC
             LIMIT ?",
            [...$params, $limit]
        )->fetchAll();

        return array_map(function (array $r) {
            foreach (['id', 'ticket_id', 'player_guid', 'action', 'gm_guid', 'status', 'result_code', 'wait_seconds'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['text_bytes'] = $r['text_bytes'] === null ? 0 : (int) $r['text_bytes'];
            $r['action_name'] = self::ACTION_NAMES[$r['action']] ?? 'Acción ' . $r['action'];
            $r['status_name'] = self::STATUS_NAMES[$r['status']] ?? (string) $r['status'];
            $r['message'] = $r['status'] === self::STATUS_PENDING
                ? 'Esperando al worldserver.'
                : (self::RESULT_MESSAGES[$r['result_code']] ?? 'Código desconocido.');
            return $r;
        }, $rows);
    }
}
