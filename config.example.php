<?php
// Copia este fichero a config.php y rellénalo. config.php NO se sube a git.
return [
    'db' => [
        // Extremo local del túnel SSH (ver README). Usar 127.0.0.1, no "localhost".
        'host'          => '127.0.0.1',
        'port'          => 13306,
        // Para dejar entrar a otras personas, un usuario de BD propio con permisos mínimos (ver README)
        'user'          => 'usuario',
        'password'      => 'contraseña',
        'characters_db' => 'characters',
    ],

    // 'dev':   todas las herramientas de pruebas (inserción manual, saltar comprobaciones, SQL de cada acción)
    // 'staff': solo lo que vería un GM en la web real. Obliga a iniciar sesión.
    'mode' => 'dev',

    // Usuarios del prototipo. Si hay alguno, hace falta iniciar sesión, y cada usuario actúa solo con los
    // personajes de su cuenta GM. Hash de la contraseña (ver README):
    //   docker compose run --rm web php -r 'echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), "\n";'
    'users' => [
        // 'nombre' => ['password_hash' => '$2y$10$...', 'account' => 12345],
    ],

    // Se guarda en gm_ticket_support.requested_by (con ":usuario" detrás si hay sesión)
    'requested_by' => 'prototipo',
];
