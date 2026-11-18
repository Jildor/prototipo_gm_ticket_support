# prototipo_gm_ticket_support

Prototipo de ejemplo del sistema **GM Ticket Support** de NaerEmu: un GM atiende los tickets del juego desde la
web. La web lee los tickets de `characters.gm_ticket` y, para actuar, inserta una petición en
`characters.gm_ticket_support`; el worldserver la ejecuta como si el GM hubiera usado `.ticket` en el juego.

El objetivo es que el equipo web vea el funcionamiento completo y sepa qué tiene que implementar. **No es código
para producción**: no tiene inicio de sesión (la cuenta del GM se escribe a mano) y deja saltarse las
comprobaciones para hacer pruebas.

**Equipo web:** empezad por la [guía de integración](docs/INTEGRACION.md).

## Cómo funciona

```
 Web                                   BD characters                       worldserver
  │ 1. lista y lee tickets (SELECT)          │ gm_ticket (copia de la memoria)   │
  │ 2. INSERT gm_ticket_support ───────────► │ status = 0 (pendiente)            │
  │                                          │ ◄──────── 3. cada 5 s lee las     │
  │                                          │           pendientes, las valida  │
  │                                          │           y las ejecuta           │
  │                                          │ status = 1/2, result_code ◄────── │ 4. guarda el ticket y
  │ 5. consulta el resultado (SELECT) ◄───── │ gm_ticket actualizado             │    cierra la fila
```

- La web **nunca escribe en `gm_ticket`**: el worldserver tiene los tickets en memoria y pisaría el cambio.
- En `gm_ticket_support` la web solo inserta y consulta. El estado lo escribe siempre el worldserver.
- Lo que hace el GM desde la web queda en su log de GM (`gm_<cuenta>.log` y `gm_command_log`) igual que un
  comando del juego, con la marca `[web:<id de petición>]`.

## Ejecutarlo en local (Debian en WSL2 + Docker)

Igual que `prototipo_character_support`, con el mismo túnel. Todo desde la consola de **Debian**:

1. Configuración (si no existe):
   ```bash
   cd /mnt/d/git/ComunidadRean/prototipo_gm_ticket_support
   cp config.example.php config.php
   nano config.php        # usuario y contraseña de MariaDB del reino de desarrollo
   ```
2. Túnel SSH a la máquina de desarrollo, en **otra consola de Debian** (déjala abierta). Si ya lo tienes abierto
   para el otro prototipo, sirve el mismo:
   ```bash
   ssh -N -L 127.0.0.1:13306:127.0.0.1:3306 usuario_ssh@IP_MAQUINA_DESARROLLO
   ```
3. Arrancar:
   ```bash
   docker compose up --build
   ```
4. Abrir en el navegador de Windows: **http://localhost:8091**

Para pararlo, `Ctrl+C` (o `docker compose down`). Para usar otro puerto: `PORT=8092 docker compose up`. El
contenedor no se reinicia solo: si WSL se apaga, vuelve a abrir el túnel y arráncalo con `docker compose up -d`.

Requisitos del reino: el SQL `2026_11_16_100_characters_gm_ticket_support.sql` aplicado y el worldserver con el
script `gm_ticket_support` (sección `GM TICKET SUPPORT` del `worldserver.conf`).

## Dejar que lo pruebe otra persona (modo staff)

En `config.php`:

1. `'mode' => 'staff'`: quita la inserción manual, "saltar comprobaciones" y el SQL de cada acción (en la página y
   en la API), y no muestra los errores de BD con detalle.
2. Un usuario por persona en `'users'`, con el hash de su contraseña y el id de su cuenta GM del reino de
   desarrollo. Solo podrá actuar con los personajes de esa cuenta. Para sacar el hash (escribe la contraseña y
   pulsa Intro):
   ```bash
   docker compose run --rm web php -r 'echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), "\n";'
   ```
3. Un usuario de BD propio con permisos mínimos, en lugar del tuyo. Así, aunque alguien entrara, solo podría
   leer tickets y personajes e insertar peticiones:
   ```sql
   CREATE USER 'gmts_prototipo'@'127.0.0.1' IDENTIFIED BY 'una-contraseña-larga';
   GRANT SELECT ON characters.gm_ticket TO 'gmts_prototipo'@'127.0.0.1';
   GRANT SELECT ON characters.characters TO 'gmts_prototipo'@'127.0.0.1';
   GRANT SELECT, INSERT ON characters.gm_ticket_support TO 'gmts_prototipo'@'127.0.0.1';
   ```
   Cambia `characters` por el nombre real de la BD y el host por el que vea MariaDB al llegar por el túnel.

La sesión dura 2 horas sin uso. Tras 5 contraseñas fallidas seguidas un usuario queda bloqueado 15 minutos (20 en
total bloquean a todos). Los intentos se guardan en `data/` (no se sube a git).

### Pasarle una URL (Cloudflare Tunnel, sin que la otra persona instale nada)

Se levanta una **segunda copia** en modo staff (puerto 8092, con `config.staff.php`) y solo esa se expone. La tuya
en modo dev (8091) sigue igual y no sale de tu PC. Todo desde Debian, en la carpeta del prototipo:

1. Configuración de la copia staff (pasos 1 a 3 de arriba en este fichero):
   ```bash
   cp config.php config.staff.php
   nano config.staff.php
   ```
2. Arrancarla:
   ```bash
   GMTS_CONFIG=/app/config.staff.php PORT=8092 docker compose -p gmts-staff up -d
   ```
   Comprueba en `http://localhost:8092` que pide usuario y contraseña.
3. Instalar `cloudflared` (solo la primera vez):
   ```bash
   curl -L -o /tmp/cloudflared.deb https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64.deb
   sudo dpkg -i /tmp/cloudflared.deb
   ```
4. Abrir el túnel, en otra consola que dejas abierta:
   ```bash
   cloudflared tunnel --url http://localhost:8092
   ```
   Muestra una dirección `https://<palabras>.trycloudflare.com`. Esa es la URL para la otra persona. Pásale el
   usuario y la contraseña por un canal distinto al de la URL.
5. Al terminar: `Ctrl+C` en la consola de `cloudflared` (la URL deja de funcionar) y
   `docker compose -p gmts-staff down`.

Mientras dure la prueba tu PC tiene que estar encendido, con el túnel SSH a la BD, Docker y `cloudflared`
abiertos. La URL cambia cada vez que se abre el túnel. Es pública: cualquiera que la tenga llega a la pantalla de
inicio de sesión, así que no la publiques en canales abiertos.

## Uso

1. **GM**: escribe el id de la cuenta del GM y elige con qué personaje actúa. Se recuerda entre recargas.
2. **Tickets**: los abiertos, como `.ticket list`. Pulsa uno para abrirlo. Al abrirlo se envía una petición
   *Ver* (`.ticket viewid`): marca el ticket como visto para el jugador y lo deja en el registro del GM.
3. **Ticket**: mensaje del jugador, respuesta actual y el cuadro de respuesta, que cuenta los bytes y avisa al
   momento de emojis, `|`, caracteres de control y respuestas demasiado largas.
   - **Responder** (`.ticket response append`): el jugador la recibe en la siguiente pasada del servidor, hasta
     5 minutos después. Es lo que hacen los GM en la mayoría de respuestas.
   - **Responder y completar** (`response append` + `.ticket complete`): la recibe al momento y el ticket sale
     de la lista. Sin texto, si el ticket ya tiene respuesta, solo lo completa.
   - *Saltar las comprobaciones de la web* inserta igualmente, para ver cómo las rechaza el servidor.
4. **Buscar tickets**: en todos los tickets, también los atendidos, por el nombre del jugador o del GM que
   respondió. Al abrir un ticket se ven además los otros tickets del mismo personaje.
5. **Peticiones**: lista que se actualiza cada 3 s, del ticket abierto o de todos. Cada fila pasa de *Pendiente* a
   *Hecho* o *Error* con el motivo.
6. **SQL de la última acción**: las consultas exactas que ha lanzado la web, con sus parámetros.
7. **Inserción manual**: inserta sin comprobar nada (acciones de la segunda versión, GM de otra cuenta, emojis,
   ticket de otro jugador).

## Ficheros

| Fichero | Qué es |
|---|---|
| `docs/INTEGRACION.md` | **Guía para el equipo web** |
| `src/GmTicketSupport.php` | **Lógica de la web**: lecturas, comprobaciones, inserción y consulta. Sirve de referencia |
| `src/Db.php` | Conexión PDO. Registra el SQL ejecutado solo para mostrarlo en el prototipo |
| `public/api.php` | API JSON que usa la página |
| `public/index.html`, `app.js`, `style.css` | Interfaz |
| `config.example.php` | Plantilla de configuración (`config.php` no se sube a git) |
