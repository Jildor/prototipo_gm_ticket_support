# GM Ticket Support: guía para el equipo web

Cómo atender los tickets de GM desde la web. Lo que fija el emulador es la tabla, las acciones y los códigos de
resultado; cómo se implementa en la web lo decide el equipo web. El prototipo (`src/GmTicketSupport.php`) es una
forma de hacerlo que sirve de referencia.

## 1. Por qué una cola

Los tickets viven en la memoria del worldserver. `gm_ticket` es solo una copia que el servidor reescribe entera
cada vez que guarda un ticket (`REPLACE`). Si la web cambiara `gm_ticket`, el servidor no se enteraría y lo
pisaría en el siguiente guardado.

Por eso la web **lee** `gm_ticket` y, para **actuar**, deja una petición en `gm_ticket_support`. El worldserver
la recoge cada 5 segundos, comprueba todo, la ejecuta como si el GM hubiera escrito el comando en el juego y
cierra la fila con el resultado.

## 2. Reglas

- **`gm_ticket`: solo `SELECT`.** Nunca `INSERT`, `UPDATE` ni `DELETE`.
- **`gm_ticket_support`: solo `SELECT` e `INSERT`.** `status`, `result_code` y `processed_at` los escribe el
  worldserver.
- **`gm_guid` tiene que ser un personaje de la cuenta del GM que ha iniciado sesión.** La web debe comprobarlo
  siempre. El servidor comprueba los permisos de esa cuenta, pero no puede saber quién está delante del panel.
- **Evitar el doble envío**: desactivar los botones hasta tener el resultado.
- Permisos del usuario de BD de la web: `SELECT` en `gm_ticket` y `characters`; `SELECT, INSERT` en
  `gm_ticket_support`.

## 3. Tabla `gm_ticket_support`

| Columna | Quién | Qué es |
|---|---|---|
| `id` | BD | Autoincremental. Es el id que aparece en el log de GM como `[web:<id>]` |
| `ticket_id` | Web | `gm_ticket.id` |
| `player_guid` | Web | `gm_ticket.playerGuid` que la web tenía en pantalla. Los números de ticket se reutilizan tras `.ticket reset`: si no coincide, el servidor rechaza la petición (código 4) |
| `action` | Web | Ver §4 |
| `gm_guid` | Web | Personaje del GM con el que actúa (de la cuenta de la sesión) |
| `text` | Web | Texto de la respuesta. `NULL` en *Ver* |
| `requested_by` | Web | Libre, para la web (p. ej. usuario del panel). El servidor no lo usa |
| `status` | Servidor | 0 pendiente, 1 hecho, 2 error |
| `result_code` | Servidor | Ver §6 |
| `created_at`, `processed_at` | BD / servidor | Creación y cierre |

## 4. Acciones

| `action` | Qué hace | Comando equivalente | `text` |
|---|---|---|---|
| 1 | **Ver**: marca el ticket como visto (el jugador ve que un GM lo ha abierto) y lo registra | `.ticket viewid` | `NULL` |
| 5 | **Responder**: añade el texto a la respuesta. El servidor la envía sola en su siguiente pasada (hasta 5 min) y entonces el ticket queda completado | `.ticket response append` | Obligatorio |
| 6 | **Completar**: añade el texto (si lo hay), envía la respuesta al momento y saca el ticket de la lista | `.ticket response append` + `.ticket complete` | Opcional si el ticket ya tiene respuesta |

Cualquier otro número lo rechaza el servidor con el código 2. Asignar, comentar, cerrar, borrar o escalar un
ticket se sigue haciendo desde el juego.

## 5. Flujo recomendado

### Acceso y personaje del GM

- El panel solo debe verlo una cuenta con rango de GM en ese reino (en la BD de cuentas, `account_access` con el
  `RealmID` del reino o `-1`). Es para no enseñarlo a quien no toca; el permiso de cada acción lo vuelve a
  comprobar el servidor y, si falta, responde con el código 6.
- El GM elige con cuál de sus personajes actúa. Ese personaje es el que queda en el registro de GM y en el ticket
  ("respondido por"):

```sql
SELECT guid, name, level, online FROM characters WHERE account = ? AND deleteDate IS NULL ORDER BY name;
```

- Antes de cada `INSERT`, comprobar en el servidor web que `gm_guid` sigue siendo de la cuenta de la sesión:

```sql
SELECT 1 FROM characters WHERE guid = ? AND account = ? AND deleteDate IS NULL;
```

### Listar tickets

```sql
-- Abiertos (lo que ve el GM con .ticket list), estén o no conectados los jugadores
SELECT t.id, t.playerGuid, t.name, t.description, FROM_UNIXTIME(t.createTime) AS created,
       t.assignedTo, a.name AS assigned_name, t.viewed, LENGTH(t.response) AS response_bytes,
       COALESCE(p.online, 0) AS online
FROM gm_ticket t
LEFT JOIN characters a ON a.guid = t.assignedTo
LEFT JOIN characters p ON p.guid = t.playerGuid
WHERE t.type = 0 AND t.completed = 0
ORDER BY t.id;
```

- Estado para mostrar:

  | Estado | Condición | Se puede responder |
  |---|---|---|
  | Abierto | `type = 0` y `completed = 0` | Sí |
  | Completado (respuesta entregada) | `completed = 1` | No: corregir por correo |
  | Cerrado (por un GM, por el jugador o por borrar el personaje) | `type <> 0` | No |

- `viewed = 1`: algún GM lo ha abierto. `assignedTo`: guid del GM asignado (0 = nadie); si es otro, solo ese GM
  puede responder.
- `gm_ticket` va al día con la memoria del servidor con unos milisegundos de retraso: sirve para mostrar.
- `resolvedBy` no es fiable (el servidor no lo carga al arrancar). Para saber quién respondió o completó un
  ticket, usar `gm_ticket_support` o `gm_command_log`.

### Abrir un ticket

1. Leer el ticket con un `SELECT` (el contenido se muestra al momento).
2. Insertar una petición *Ver*. No hace falta esperar su resultado para mostrar el ticket.

```sql
INSERT INTO gm_ticket_support (ticket_id, player_guid, action, gm_guid, text, requested_by)
VALUES (?, ?, 1, ?, NULL, ?);
```

*Ver* es prescindible: si no se envía, responder funciona igual. Solo se pierde el aviso de "visto" al jugador y
la línea `ticket viewid` en el registro del GM.

### Responder

Dos botones, como hacen hoy los GM en el juego:

- **Responder** (`action = 5`): el jugador la recibe en la siguiente pasada del servidor.
- **Responder y completar** (`action = 6` con el texto): la recibe al momento y el ticket sale de la lista.

```sql
INSERT INTO gm_ticket_support (ticket_id, player_guid, action, gm_guid, text, requested_by)
VALUES (?, ?, 6, ?, ?, ?);
```

Una respuesta sin completar **no es un borrador**: se envía sola en unos minutos. Para corregirla, otra respuesta
(se añade detrás); si el ticket ya está completado, un correo al jugador.

### Esperar el resultado

```sql
SELECT status, result_code, processed_at FROM gm_ticket_support WHERE id = ?;
```

Tarda como mucho unos segundos (`GmTicketSupport.Interval`, 5 s). Con `status = 1`, `gm_ticket` ya tiene el
cambio: se guardan en la misma transacción. Varias peticiones del mismo ticket se ejecutan en orden, una por
ciclo. Una petición sin procesar en 60 minutos se cierra como caducada (código 3), por ejemplo si el worldserver
estaba parado.

### Buscar tickets ya atendidos

El staff lo pide para seguir un caso (un jugador que pone varios tickets) y para revisar las respuestas de un GM.
Va aparte de la cola, porque los tickets atendidos se acumulan:

```sql
-- Por jugador (nombre del personaje que creó el ticket) o por GM (último que respondió)
SELECT id, playerGuid, name, description, FROM_UNIXTIME(createTime) AS created, responsedByName, response,
       type, completed
FROM gm_ticket
WHERE name LIKE CONCAT(?, '%')            -- o: WHERE responsedByName LIKE CONCAT(?, '%')
ORDER BY id DESC LIMIT 50;

-- Otros tickets del mismo personaje, al abrir uno
SELECT id, description, FROM_UNIXTIME(createTime) AS created, responsedByName, response, type, completed
FROM gm_ticket WHERE playerGuid = ? AND id <> ? ORDER BY id DESC LIMIT 20;
```

Límites de lo que guarda `gm_ticket`:

- **Solo el último GM que respondió** (`responsedByName`). Si respondieron varios, los anteriores no constan ahí.
  El detalle completo está en `gm_command_log` (en el juego y desde la web) y en `gm_ticket_support` (solo web).
- **`.ticket reset` borra los tickets cerrados** y reinicia la numeración. Si el historial tiene que durar, la
  web debería guardar su propia copia de los tickets atendidos.
- `name` y `responsedByName` no tienen índice. Con pocos miles de filas da igual; si la tabla crece, añadirlo.
- Escapar `%` y `_` en lo que escribe el usuario antes del `LIKE`.

### Historial de un ticket

```sql
SELECT s.id, s.action, s.gm_guid, g.name AS gm_name, s.status, s.result_code, s.created_at, s.processed_at
FROM gm_ticket_support s LEFT JOIN characters g ON g.guid = s.gm_guid
WHERE s.ticket_id = ? ORDER BY s.id DESC;
```

Las peticiones cerradas se borran a los 90 días (`GmTicketSupport.HistoryDays`).

## 6. Códigos de resultado

| Código | Significado | Qué mostrar al GM |
|---|---|---|
| 0 | Hecho | — |
| 1 | El ticket no existe | Ya no existe; recargar la lista |
| 2 | Parámetros no válidos | Acción desconocida o *Ver* con texto. Es un fallo de la web |
| 3 | Caducada | No se procesó a tiempo (¿worldserver parado?). No se ha hecho nada |
| 4 | El ticket es de otro jugador | El número se ha reutilizado. Recargar la lista |
| 5 | El personaje GM no existe | — |
| 6 | Sin permiso | La cuenta del GM no tiene el permiso del comando en este reino |
| 7 | Error de BD | **Se ha hecho**, pero no se pudo guardar. **No repetir** (se duplicaría la respuesta); avisar a un administrador |
| 20 | Ticket cerrado | — |
| 21 | Ticket completado | La respuesta ya se entregó. Si la petición traía texto, **ese texto no se ha enviado**: usar el correo. Es normal tras *Responder*, si el servidor ya la entregó |
| 22 | Asignado a otro GM | Solo lo puede responder el GM asignado (como en el juego) |
| 40 | Falta el texto | *Responder* sin texto, o *Completar* sin texto en un ticket sin respuesta |
| 41 | Texto demasiado largo | La respuesta entera (la actual más la nueva) pasa de 15.996 bytes |
| 42 | Caracteres no válidos | Emojis, `\|` o caracteres de control |

## 7. Texto de la respuesta

El servidor lo valida y la web puede validarlo antes para avisar al momento (`checkText` en el prototipo):

- **UTF-8 de 1 a 3 bytes.** Sin emojis ni otros caracteres de 4 bytes: `gm_ticket` es `utf8` de 3 bytes y no los
  puede guardar.
- **Sin `|`**: el cliente del juego lo interpreta como código de color o enlace.
- **Sin caracteres de control** salvo el salto de línea. Los `\r\n` del navegador se convierten en `\n`.
- **Máximo 15.996 bytes** contando la respuesta que ya tiene el ticket (`LENGTH(response)`). Son bytes, no
  caracteres: una tilde o una eñe cuentan 2.
- **Aviso a partir de 3.999 bytes** en total: el juego envía la respuesta en trozos de ese tamaño y una tilde
  justo en el corte puede verse mal.

La conexión de la web a la BD debe ser `utf8mb4` para que las tildes se guarden bien.

## 8. Registro de GM

Cada petición **hecha** queda en `gm/gm_<cuenta>.log` y en `gm_command_log` con el mismo formato que el comando
del juego, terminada en ` [web:<id>]`. Las respuestas largas se registran troceadas en varias líneas. Las
rechazadas no se registran ahí; quedan en el log técnico del worldserver (`gmticketsupport`).

## 9. Qué es solo del prototipo

No hay que copiarlo en la web real:

- **Inicio de sesión y modo staff** (`config.php`, `public/api.php`): existen solo para dejar probar el prototipo
  al staff. La web real usa su propio inicio de sesión.
- **Escribir el id de la cuenta a mano**: en la web real la cuenta sale de la sesión.
- **Inserción manual, "saltar las comprobaciones" y el panel de SQL**: herramientas de pruebas.
- **Consultar el resultado sondeando cada 3 s toda la lista**: vale para el prototipo. En la web real basta con
  preguntar por las peticiones propias pendientes mientras el GM tiene el ticket abierto.

Lo que sí sirve de referencia:

| Qué | Dónde |
|---|---|
| Consultas de lectura, comprobaciones previas e `INSERT` | `src/GmTicketSupport.php` |
| Validación del texto en el navegador (bytes, emojis, `\|`, caracteres de control) | `checkText` en `public/app.js` |
| Mensaje para el GM de cada `result_code` | `GmTicketSupport::RESULT_MESSAGES` |

## 10. Lista de comprobación

- [ ] El panel solo lo ven cuentas GM, y `gm_guid` siempre es un personaje de la cuenta de la sesión.
- [ ] La web no escribe nunca en `gm_ticket`.
- [ ] Al abrir un ticket se inserta *Ver* (opcional, pero lo usa el registro de GM).
- [ ] Dos botones: *Responder* (`action = 5`) y *Responder y completar* (`action = 6`).
- [ ] Los botones se desactivan hasta tener el resultado.
- [ ] El texto se valida antes de enviar (§7) y se avisa a partir de 3.999 bytes.
- [ ] Se muestra el mensaje de cada `result_code` (§6), con especial cuidado en el 7 (no repetir) y el 21 (el
      texto nuevo no se envió).
- [ ] Buscador aparte por jugador y por GM, y los otros tickets del jugador al abrir uno.
- [ ] Conexión a la BD en `utf8mb4` y usuario de BD con los permisos mínimos (§2).
