# Guía de integración para la web

Esta guía explica cómo funciona el sistema **GM Ticket Support** de NaerEmu y qué hace falta para que los GM
atiendan los tickets del juego desde la web. **Es orientativa** en lo que toca a la web: describe el
funcionamiento y propone pantallas y pasos, pero cómo se implemente (estructura del código, diseño, framework…)
lo decide el equipo web.

El prototipo de este repositorio es un ejemplo que funciona contra el reino de desarrollo. Su lógica está en
[`src/GmTicketSupport.php`](../src/GmTicketSupport.php) (consultas, comprobaciones e inserción) y la validación del
texto en el navegador en `checkText` de [`public/app.js`](../public/app.js), por si sirven de referencia.

**Lo único obligatorio** es lo que marca el emulador: la tabla, las acciones, los códigos de resultado, las reglas
del texto y las reglas de §1.2.

## 1. Por qué existe `gm_ticket_support`

### 1.1 La web no debe modificar `gm_ticket`

El servidor del juego (worldserver) carga todos los tickets en memoria al arrancar y trabaja siempre con esa
copia. La tabla `characters.gm_ticket` es solo donde los guarda: cada vez que algo cambia en un ticket, el
servidor reescribe la fila **entera** desde la memoria (`REPLACE`).

Por eso, si la web cambiara `gm_ticket` directamente (escribir una respuesta, marcarlo como completado…):

- **El servidor no se entera.** El jugador no recibe la respuesta, y los GM que estén en el juego siguen viendo el
  ticket como estaba.
- **El servidor pisa el cambio** en el siguiente guardado de ese ticket (por ejemplo, cuando el jugador lo edita o
  cuando otro GM lo mira), y lo que escribió la web desaparece.
- **Faltan las comprobaciones y el registro** que hace el juego: permisos del GM, ticket asignado a otro GM,
  caracteres que rompen el cliente, y la línea en el registro de GM que usa el staff para revisar el trabajo.

### 1.2 Cómo se hace entonces

La web **lee** `gm_ticket` para mostrar los tickets y, para **actuar**, deja una **petición** en la tabla
`gm_ticket_support`. El worldserver la recoge en su siguiente ciclo (cada 5 segundos), comprueba que se puede
hacer y la ejecuta como si el GM hubiera escrito el comando `.ticket` en el juego: actualiza la memoria, guarda el
ticket, envía la respuesta al jugador, avisa a los GM conectados y lo apunta en el registro de GM. Después cierra
la petición con su resultado.

```
 Web                                   BD characters                       worldserver
  │ 1. lista y lee tickets (SELECT)          │ gm_ticket                         │
  │ 2. INSERT gm_ticket_support ───────────► │ status = 0 (pendiente)            │
  │                                          │ ◄──────── 3. cada 5 s lee las     │
  │                                          │           pendientes, las valida  │
  │                                          │           y las ejecuta           │
  │                                          │ status = 1/2, result_code ◄────── │ 4. guarda el ticket y
  │ 5. consulta el resultado (SELECT) ◄───── │ gm_ticket actualizado             │    cierra la fila
```

Reglas:

- **En `gm_ticket` la web solo hace `SELECT`.** Nunca `INSERT`, `UPDATE` ni `DELETE`, ni siquiera como paso
  previo o posterior a una petición.
- **En `gm_ticket_support` la web solo hace `SELECT` e `INSERT`.** `status`, `result_code` y `processed_at` los
  escribe siempre el worldserver. Si la web los cambia o borra filas pendientes, el servidor puede ejecutar algo que
  la web da por cancelado, o al revés.
- **`gm_guid` tiene que ser un personaje de la cuenta del GM que ha iniciado sesión en la web.** La web lo
  comprueba **siempre**, en el servidor web, antes de cada `INSERT`. El worldserver comprueba los permisos de la
  cuenta de ese personaje, pero no puede saber quién está delante del panel: sin esta comprobación, un GM podría
  actuar con el nombre de otro.
- Las demás comprobaciones de la web sirven para avisar al GM al momento. El worldserver las **repite todas** y
  su resultado (`result_code`) es el que vale.
- El resultado no es instantáneo: tarda **unos segundos** (§8).

Permisos del usuario de BD de la web en la BD `characters`: `SELECT` en `gm_ticket` y en `characters` (personajes
del GM y nombres), y `SELECT, INSERT` en `gm_ticket_support`. Nada más.

## 2. La tabla `characters.gm_ticket_support`

| Columna | La escribe | Significado |
|---|---|---|
| `id` | BD | Id de la petición. Se obtiene con `lastInsertId()` tras insertar. Es el número que aparece en el registro de GM como `[web:<id>]` |
| `ticket_id` | Web | `gm_ticket.id` del ticket |
| `player_guid` | Web | `gm_ticket.playerGuid` del ticket que la web tenía en pantalla. Los números de ticket se reutilizan tras un `.ticket reset`; si no coincide, el servidor rechaza la petición (código 4) en lugar de actuar sobre el ticket de otro jugador |
| `action` | Web | Acción (§3) |
| `gm_guid` | Web | `characters.guid` del personaje GM con el que actúa (de la cuenta de la sesión) |
| `text` | Web | Texto de la respuesta, o `NULL`. Una cadena vacía cuenta como `NULL` |
| `requested_by` | Web | Texto libre para la web (máx. 64 caracteres), por ejemplo `web:<usuario>`. El servidor no lo usa |
| `status` | worldserver | 0 pendiente, 1 hecho, 2 error |
| `result_code` | worldserver | Motivo del resultado (§4) |
| `created_at` | BD | Fecha de creación |
| `processed_at` | worldserver | Fecha de cierre (`NULL` mientras está pendiente) |

**No hay índice único**: dos `INSERT` iguales son dos peticiones, y el servidor ejecuta las dos. Con *Responder*
eso duplica la respuesta en el ticket. Evitar el doble envío es cosa de la web (§10).

## 3. Acciones

| `action` | Botón | Qué hace | Comando del juego | `text` |
|---|---|---|---|---|
| 1 | — (al abrir el ticket) | **Ver**: marca el ticket como visto por un GM y lo apunta en el registro | `.ticket viewid` | `NULL` |
| 5 | **Responder** | Añade el texto a la respuesta del ticket. No la envía en el momento: el servidor la entrega sola en su siguiente pasada automática (hasta 5 min) y entonces el ticket queda completado | `.ticket response append` | Obligatorio |
| 6 | **Responder y completar** | Añade el texto (si lo hay), envía la respuesta al jugador en el momento y marca el ticket como completado (sale de la lista) | `.ticket response append` + `.ticket complete` | Opcional si el ticket ya tiene respuesta |

Detalles:

- Son las dos formas en que responden hoy los GM en el juego. La mayoría usa *Responder* y deja que el servidor la
  envíe. *Responder y completar* es para cuando se quiere que llegue ya.
- **Una respuesta sin completar no es un borrador**: se envía sola en unos minutos. Si la web quiere borradores,
  los guarda ella, en su propia BD.
- Para **corregir o ampliar** una respuesta aún no entregada, se manda otra *Responder*: el texto nuevo se añade
  detrás del anterior. No se puede sustituir ni borrar. Si el ticket ya está completado, la respuesta ya llegó al
  jugador: se le escribe un correo en el juego.
- Si el jugador no está conectado, recibe la respuesta al entrar al juego.
- Cualquier otro número de `action` lo rechaza el servidor con el código 2. Asignar, comentar, cerrar, escalar o
  borrar tickets se sigue haciendo desde el juego.

Qué ven los demás:

| | Ver | Responder | Responder y completar |
|---|---|---|---|
| Jugador | El ticket pasa a "visto por un GM" | La respuesta, cuando el servidor la entrega | La respuesta, al momento |
| GM conectados en el juego | Nada | Aviso de "respondido por <GM>" | Avisos de "respondido" y "completado" |
| Registro de GM | `ticket viewid <id>` | `ticket response append <id> <texto>` | `ticket response append …` + `ticket complete <id>` |

## 4. Códigos de resultado

El prototipo tiene estos mensajes en `GmTicketSupport::RESULT_MESSAGES`.

| Código | Acciones | Significado | Mensaje de ejemplo para el GM |
|---|---|---|---|
| 0 | todas | Hecho | Hecho. |
| 1 | todas | El ticket no existe en el servidor | El ticket ya no existe. |
| 2 | todas | Acción desconocida, o *Ver* con texto. Es un fallo de la web | Los datos de la petición no son válidos. |
| 3 | todas | Caducada: ha estado pendiente más de 60 min (worldserver parado). No se ha hecho nada | La petición ha caducado sin procesarse. No se ha hecho nada. |
| 4 | todas | El número de ticket es ahora de otro jugador (reutilizado tras `.ticket reset`) | Ese número de ticket ahora es de otro jugador. Recarga la lista. |
| 5 | todas | El personaje GM no existe | El personaje GM no existe. |
| 6 | todas | La cuenta del GM no tiene en este reino el permiso del comando equivalente | Tu cuenta no tiene permiso para esta acción en este reino. |
| 7 | todas | **La acción se ha hecho** (el jugador puede haberla recibido), pero el servidor no pudo guardarla en la BD | Se ha hecho, pero no se pudo guardar. **No lo repitas** y avisa a un administrador. |
| 20 | todas | El ticket está cerrado (por un GM, por el jugador o por borrar el personaje) | El ticket está cerrado. |
| 21 | todas | El ticket ya está completado: la respuesta ya se entregó | El ticket ya está completado. Si mandabas un texto nuevo, no se ha enviado; escríbele por correo. |
| 22 | 5, 6 con texto | Asignado a otro GM. Solo puede responder el asignado, como en el juego | El ticket está asignado a otro GM. |
| 40 | 5, 6 | Falta el texto (*Responder* sin texto, o *Completar* sin texto en un ticket sin respuesta) | Falta el texto de la respuesta. |
| 41 | 5, 6 | La respuesta entera (la que ya tenía más la nueva) pasaría de 15.996 bytes | La respuesta es demasiado larga. |
| 42 | 5, 6 | Emojis, `\|` o caracteres de control (§9) | El texto tiene caracteres no permitidos. |

Cuidado especial con dos:

- **7**: no ofrecer "reintentar". Repetir la petición añadiría la respuesta otra vez.
- **21**: si la petición llevaba texto, **ese texto no se ha añadido ni enviado**. Pasa, por ejemplo, si el
  servidor entregó la respuesta anterior justo antes, o si otro GM completó el ticket en el juego. Hay que decírselo
  así al GM, no darlo por entregado.

Una petición con error **no se reintenta**: queda cerrada y el GM puede volver a enviarla cuando quiera.

## 5. Lo que hay en `gm_ticket`

Columnas que necesita la web, y cómo interpretarlas:

| Columna | Significado |
|---|---|
| `id` | Número del ticket (el que usan los GM: "GM-123") |
| `type` | 0 abierto; cualquier otro valor, cerrado |
| `playerGuid`, `name` | Personaje del jugador que lo creó y su nombre |
| `description` | Mensaje del jugador |
| `createTime`, `lastModifiedTime` | **Fecha Unix** (segundos), no `TIMESTAMP`: mostrar con `FROM_UNIXTIME()` |
| `mapId` | Mapa donde estaba el jugador al crearlo |
| `assignedTo` | Guid del GM asignado en el juego (0 = nadie). Si es otro GM, solo ese puede responder |
| `comment` | Nota interna de los GM (`.ticket comment`). El jugador no la ve |
| `response` | Respuesta acumulada hasta ahora. Su tamaño en bytes (`LENGTH(response)`) cuenta para el límite (§9) |
| `responsedByName` | Nombre del **último** GM que respondió |
| `completed` | 1 = la respuesta ya se entregó al jugador |
| `viewed` | 1 = algún GM lo ha abierto |
| `escalated` | Escalado a un nivel superior de staff |
| `needMoreHelp` | El jugador pidió más ayuda tras la respuesta |
| `resolvedBy` | **No fiable**: el servidor no lo carga al arrancar y lo pone a 0 en el siguiente guardado. Para saber quién atendió un ticket, usar `gm_ticket_support` o `gm_command_log` |

Estado para mostrar:

| Estado | Condición | ¿Se puede responder? |
|---|---|---|
| Abierto | `type = 0` y `completed = 0` | Sí |
| Completado | `type = 0` y `completed = 1` | No: la respuesta ya llegó. Corregir por correo |
| Cerrado | `type <> 0` | No |

`gm_ticket` va al día con la memoria del servidor con unos milisegundos de retraso, así que sirve para mostrar.
Pero lo que vale para actuar es la memoria: si algo cambia entre que la web lee y el servidor ejecuta, el
servidor responde con el código que toque (20, 21, 22…).

## 6. Pantallas y flujo propuesto

### 6.1 Acceso y personaje del GM

1. El panel solo lo ve una cuenta con rango de GM en ese reino: en la BD de cuentas, `account_access` con
   `gmlevel` de GM y `RealmID` igual al del reino o `-1`. Esto es para no enseñarlo a quien no toca; el permiso de
   cada acción lo vuelve a comprobar el servidor (código 6).
2. El GM elige con cuál de los personajes de su cuenta actúa (consulta en §7). Ese personaje es el que queda en el
   registro de GM, en el aviso a los GM conectados y como "respondido por" en el ticket. Conviene recordarlo entre
   visitas.
3. **Antes de cada `INSERT`**, en el servidor web (no en el navegador), comprobar que ese `gm_guid` sigue siendo un
   personaje no borrado de la cuenta de la sesión. En el prototipo: `GmTicketSupport::isCharacterOfAccount`.

### 6.2 Lista de tickets (la cola)

1. Mostrar los tickets abiertos, como `.ticket list` en el juego (consulta en §7), estén o no conectados los
   jugadores, los más antiguos primero.
2. Por cada ticket, conviene enseñar: número, jugador (y si está conectado), fecha, inicio del mensaje, si está
   visto, si está asignado y a quién, y si ya tiene respuesta pendiente de entregar (`response_bytes > 0`).
3. Recargar la lista cada cierto tiempo (por ejemplo, cada 30 s) o con un botón: los jugadores crean tickets y
   otros GM los atienden desde el juego.

### 6.3 Abrir un ticket

1. Leer el ticket entero (consulta en §7) y mostrarlo: mensaje del jugador, respuesta actual si la tiene, nota
   interna, asignado, estado.
2. Si está abierto, insertar una petición **Ver** (`action = 1`, `text = NULL`) con el personaje del GM. No hace
   falta esperar su resultado para mostrar el ticket ni para responder.
   *Ver* es prescindible: sin ella, responder funciona igual; solo se pierde el "visto" para el jugador y la línea
   `ticket viewid` del registro de GM, que el staff usa para revisar el trabajo. Se puede enviar cada vez que un GM
   abre el ticket, como en el juego.
3. Mostrar también, para seguir el caso:
   - los **otros tickets del mismo jugador** (consulta en §7);
   - las **peticiones web de este ticket** (historial, consulta en §7).

### 6.4 Responder

1. El GM escribe en un cuadro de texto. Mientras escribe, validar el texto (§9) y mostrar los bytes que ocuparía la
   respuesta entera y su límite.
2. Dos botones:
   - **Responder** → `action = 5` con el texto.
   - **Responder y completar** → `action = 6` con el texto. Si el ticket ya tiene respuesta, se puede pulsar sin
     texto: solo completa y la envía.
3. Comprobaciones previas en el servidor web, con el ticket recién leído (en el prototipo,
   `GmTicketSupport::checkAction`), en este orden, que es el del worldserver:
   1. el personaje GM es de la cuenta de la sesión (§6.1; esta no es opcional);
   2. el ticket existe y su `playerGuid` es el que tenía en pantalla;
   3. no está cerrado ni completado;
   4. hay texto, o es *Completar* y el ticket ya tiene respuesta;
   5. el texto cumple §9;
   6. no está asignado a otro GM (`assignedTo` es 0 o el propio `gm_guid`).
4. Normalizar los saltos de línea (`\r\n` y `\r` → `\n`) e insertar la petición. Desactivar los dos botones.
5. Esperar el resultado (§8) y mostrar el mensaje del código. Si es 0, vaciar el cuadro de texto y recargar el
   ticket. Volver a activar los botones.

### 6.5 Buscar tickets ya atendidos

Para seguir un caso (un jugador que pone varios tickets) y para revisar las respuestas de un GM. Va en una pantalla
aparte de la cola, porque los tickets atendidos se acumulan.

1. Un campo de texto y un selector: **por jugador** (nombre del personaje que creó el ticket) o **por GM** (nombre
   del último GM que respondió).
2. Pedir al menos 2 letras y buscar por el principio del nombre, en tickets de cualquier estado, los más recientes
   primero, con un límite (50 en el prototipo). Consulta en §7.
3. Al pulsar un resultado, abrirlo como en §6.3. Si está completado o cerrado, sin cuadro de respuesta y sin
   enviar *Ver*.

Límites de lo que guarda `gm_ticket`:

- **Solo el último GM que respondió** (`responsedByName`). Si respondieron varios, los anteriores no constan ahí.
  El detalle completo está en `gm_command_log` (lo hecho en el juego y desde la web) y en `gm_ticket_support` (solo
  lo hecho desde la web, durante 90 días).
- **`.ticket reset` borra los tickets cerrados** y vuelve a numerar desde 1. Si el historial tiene que durar, la
  web tendría que guardar su propia copia de los tickets atendidos.
- `name` y `responsedByName` no tienen índice. Con unos miles de filas da igual; si la tabla crece mucho, se puede
  pedir un índice al equipo del emulador.

## 7. Consultas

```sql
-- Personajes de la cuenta del GM (para elegir con cuál actúa)
SELECT guid, name, level, online FROM characters WHERE account = ? AND deleteDate IS NULL ORDER BY name;

-- Comprobar, antes de cada INSERT, que el personaje GM es de la cuenta de la sesión
SELECT 1 FROM characters WHERE guid = ? AND account = ? AND deleteDate IS NULL;

-- Cola: tickets abiertos
SELECT t.id, t.playerGuid, t.name, LEFT(t.description, 100) AS description,
       FROM_UNIXTIME(t.createTime) AS created, t.assignedTo, a.name AS assigned_name, t.viewed, t.escalated,
       LENGTH(t.response) AS response_bytes, COALESCE(p.online, 0) AS online
FROM gm_ticket t
LEFT JOIN characters a ON a.guid = t.assignedTo
LEFT JOIN characters p ON p.guid = t.playerGuid
WHERE t.type = 0 AND t.completed = 0
ORDER BY t.id;

-- Un ticket entero
SELECT t.id, t.type, t.playerGuid, t.name, t.description, t.comment, t.response, t.responsedByName,
       FROM_UNIXTIME(t.createTime) AS created, FROM_UNIXTIME(t.lastModifiedTime) AS modified, t.mapId,
       t.assignedTo, a.name AS assigned_name, t.completed, t.viewed, t.escalated, t.needMoreHelp,
       LENGTH(t.response) AS response_bytes, COALESCE(p.online, 0) AS online
FROM gm_ticket t
LEFT JOIN characters a ON a.guid = t.assignedTo
LEFT JOIN characters p ON p.guid = t.playerGuid
WHERE t.id = ?;

-- Crear petición (action 1 con text NULL; 5 y 6 con el texto)
INSERT INTO gm_ticket_support (ticket_id, player_guid, action, gm_guid, text, requested_by)
VALUES (?, ?, ?, ?, ?, ?);

-- Resultado de una petición
SELECT status, result_code, processed_at FROM gm_ticket_support WHERE id = ?;

-- Historial web de un ticket
SELECT s.id, s.action, s.gm_guid, g.name AS gm_name, LEFT(s.text, 120) AS text, s.status, s.result_code,
       s.created_at, s.processed_at
FROM gm_ticket_support s LEFT JOIN characters g ON g.guid = s.gm_guid
WHERE s.ticket_id = ? ORDER BY s.id DESC;

-- Buscar por jugador (o por GM: cambiar t.name por t.responsedByName)
SELECT t.id, t.type, t.playerGuid, t.name, LEFT(t.description, 100) AS description,
       FROM_UNIXTIME(t.createTime) AS created, t.completed, t.responsedByName,
       LEFT(t.response, 100) AS response_preview
FROM gm_ticket t
WHERE t.name LIKE ?             -- ? = lo escrito, con % y _ escapados, más '%' al final
ORDER BY t.id DESC LIMIT 50;

-- Otros tickets del mismo jugador
SELECT id, type, description, FROM_UNIXTIME(createTime) AS created, completed, responsedByName,
       LEFT(response, 100) AS response_preview
FROM gm_ticket WHERE playerGuid = ? AND id <> ? ORDER BY id DESC LIMIT 20;
```

En PHP, el `LIKE` se puede preparar así:

```php
$like = addcslashes(trim($nombre), '\\%_') . '%';
```

La conexión de la web a la BD debe ser **`utf8mb4`** (`charset=utf8mb4` en el DSN de PDO) para que las tildes y
eñes se guarden y se lean bien.

## 8. Mostrar el resultado

El servidor procesa la cola cada 5 s. Tiempos normales:

- Una petición sola: entre 0 y 5 s.
- Varias del mismo ticket (por ejemplo, *Ver* y justo después *Responder*): se ejecutan en orden, **una por ciclo**,
  así que la segunda tarda un ciclo más (hasta 10 s).

Propuesta:

1. Tras el `INSERT`, consultar esa petición por su `id` cada 2–3 s hasta que `status` deje de ser 0.
2. Con `status = 1` (hecho), `gm_ticket` **ya tiene el cambio**: el servidor guarda el ticket y cierra la petición
   en la misma transacción. Se puede recargar el ticket y la lista.
3. Con `status = 2`, mostrar el mensaje de `result_code` (§4).
4. Si pasa más de un minuto sin respuesta, dejar de consultar y mostrar algo como "El servidor del juego no
   responde; la petición se procesará cuando vuelva". Si el worldserver no la procesa en 60 min, se cierra sola con
   el código 3 sin hacer nada.

Para *Ver* no hace falta esperar ni bloquear nada: basta con verla en el historial del ticket.

## 9. Texto de la respuesta

El servidor rechaza con el código 42 o 41 lo que no cumpla estas reglas. La web debería validarlo antes, mientras
el GM escribe, para avisar al momento:

- **UTF-8 de 1 a 3 bytes.** Sin emojis ni otros caracteres de 4 bytes (fuera del plano básico Unicode): `gm_ticket`
  es `utf8` de 3 bytes y no los puede guardar.
- **Sin `|`**: el cliente del juego lo interpreta como código de color o de enlace y puede romper la ventana del
  ticket.
- **Sin caracteres de control**, salvo el salto de línea (`\n`). Esto incluye el tabulador.
- **Saltos de línea**: un `<textarea>` los envía como `\r\n`. El servidor los convierte en `\n`, y la web debería
  hacer lo mismo antes de validar y contar.
- **Máximo 15.996 bytes contando la respuesta que ya tiene el ticket** (`LENGTH(response)` + bytes del texto
  nuevo). Son **bytes**, no caracteres: una tilde o una eñe cuentan 2. En PHP, `strlen`, no `mb_strlen`; en
  JavaScript, `new TextEncoder().encode(texto).length`.
- **Aviso, no error, a partir de 3.999 bytes** en total: el juego envía la respuesta en trozos de ese tamaño y una
  tilde justo en el corte puede verse mal.

En PHP (igual que `GmTicketSupport::checkText`):

```php
$texto = str_replace(["\r\n", "\r"], "\n", $texto);
$errores = [];
if (!mb_check_encoding($texto, 'UTF-8'))                       $errores[] = 'No es UTF-8 válido.';
elseif (preg_match('/[\x{10000}-\x{10FFFF}]/u', $texto))       $errores[] = 'Tiene emojis.';
if (str_contains($texto, '|'))                                 $errores[] = 'Tiene el carácter "|".';
if (preg_match('/[\x00-\x09\x0B-\x1F\x7F\x{80}-\x{9F}]/u', $texto)) $errores[] = 'Tiene caracteres de control.';
$total = $bytesRespuestaActual + strlen($texto);
if ($total > 15996)      $errores[] = 'Demasiado larga.';
elseif ($total > 3999)   $aviso = 'Se enviará en varios trozos.';
```

En JavaScript, para el aviso mientras se escribe (igual que `checkText` en `public/app.js`):

```js
const texto = textarea.value.replace(/\r\n?/g, '\n');
const emojis  = /[\u{10000}-\u{10FFFF}]/u.test(texto);
const barra   = texto.includes('|');
const control = /[\x00-\x09\x0B-\x1F\x7F-\x9F]/.test(texto);
const total   = bytesRespuestaActual + new TextEncoder().encode(texto).length;
```

## 10. Casos que hay que tener en cuenta

- **Doble clic o varias pestañas**: no hay índice único (§2), así que dos `INSERT` de *Responder* añaden la
  respuesta dos veces. Desactivar los botones hasta tener el resultado. Si hace falta más seguridad, la web puede
  comprobar antes de insertar que el mismo GM no tiene ya una petición pendiente de ese ticket:
  `SELECT 1 FROM gm_ticket_support WHERE ticket_id = ? AND gm_guid = ? AND status = 0 AND action IN (5, 6)`.
- **Dos GM a la vez**, uno en la web y otro en el juego o los dos en la web: no se pisan. Las respuestas se añaden
  una detrás de otra en el orden en que llegan. Si uno de ellos completa el ticket antes, el otro recibe el
  código 21.
- **Respuesta entregada automáticamente**: tras *Responder*, el servidor completa el ticket por su cuenta en unos
  minutos. Un *Responder y completar* posterior da 21. Es normal; ver §4.
- **El jugador cierra el ticket o borra el personaje** mientras el GM escribe: el servidor responde con 20 (o 1).
- **`.ticket reset`** en el juego reutiliza los números: una petición sobre un número antiguo da 4 o 1. Recargar la
  lista.
- **Servidor del juego parado o reiniciando**: las peticiones se quedan pendientes y se procesan al arrancar, salvo
  las que llevan más de 60 min, que se cierran con el código 3 sin ejecutarse.
- **Fechas**: en `gm_ticket` son fecha Unix (`createTime`, `lastModifiedTime`); en `gm_ticket_support` son
  `TIMESTAMP` (`created_at`, `processed_at`). Para tiempos relativos ("hace 3 min") es más seguro calcularlos en
  SQL con `NOW()` y `TIMESTAMPDIFF` que con la hora de PHP.
- **Historial**: el servidor borra las peticiones cerradas a los 90 días. Si la web las quiere más tiempo, que las
  copie a una tabla suya.

## 11. Qué es solo del prototipo

No hay que copiarlo en la web real:

- **Inicio de sesión, usuarios en `config.php` y modo staff**: existen solo para dejar probar el prototipo. La web
  real usa su propio inicio de sesión y saca de él la cuenta del GM.
- **Escribir el id de la cuenta a mano** (modo dev): en la web real la cuenta sale de la sesión.
- **Inserción manual, "saltar las comprobaciones" y el panel de SQL**: herramientas de pruebas.
- **Consultar toda la lista de peticiones cada 3 s**: en la web real basta con consultar las peticiones pendientes
  del propio GM (§8).

Lo que sí sirve de referencia:

| Qué | Dónde |
|---|---|
| Consultas de lectura | `GmTicketSupport::listTickets`, `getTicket`, `searchTickets`, `playerTickets`, `listRequests` |
| Comprobación del personaje GM | `GmTicketSupport::isCharacterOfAccount` |
| Comprobaciones previas, en el orden del servidor | `GmTicketSupport::checkAction` |
| Validación del texto | `GmTicketSupport::checkText` (PHP) y `checkText` en `public/app.js` (navegador) |
| `INSERT` de la petición | `GmTicketSupport::insertRequest` |
| Mensaje de cada `result_code` | `GmTicketSupport::RESULT_MESSAGES` |
| Cómo se encadena todo en una petición HTTP | `case 'view'/'respond'/'complete'` en `public/api.php` |

## 12. Preguntas frecuentes

**¿Por qué no es instantáneo?**
El servidor del juego revisa la tabla cada 5 s. Así la web no necesita conectarse al servidor del juego ni tocar
los tickets mientras el juego los usa.

**¿Por qué no se hace directamente con un `UPDATE` en `gm_ticket`?**
Porque el servidor del juego no se entera (el jugador no recibe nada) y lo pisa en el siguiente guardado (§1.1).

**¿Por qué comprobar cosas en la web si el servidor ya las comprueba?**
Para avisar al momento, mientras el GM escribe, en lugar de hacerle esperar unos segundos para enterarse de que
el texto tiene un emoji. La única comprobación que no es opcional es la del personaje GM (§6.1).

**¿Para qué sirve *Ver* si el ticket ya se puede leer con un `SELECT`?**
Leer no lo necesita. *Ver* hace lo mismo que `.ticket viewid` en el juego: el jugador ve que un GM ha abierto su
ticket y queda la línea en el registro de GM.

**¿Puede la web cancelar o modificar una petición pendiente?**
No: el servidor podría estar procesándola en ese momento. Como se procesa en unos segundos, no hace falta.

**¿Se puede corregir una respuesta ya enviada?**
Mientras el ticket no esté completado, se añade otra respuesta detrás. Una vez completado, el jugador ya la tiene:
se le escribe un correo en el juego.

**¿Qué pasa si el GM no tiene permiso para algún comando de tickets?**
El servidor rechaza la petición con el código 6, igual que el juego rechazaría el comando.

