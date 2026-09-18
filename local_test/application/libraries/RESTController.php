<?php
// Reemplazo LOCAL, minimo, de chriskacerguis/codeigniter-restserver (la
// libreria real que usa el servidor de produccion). Solo imita lo que
// los controllers de captura_v1 realmente usan: $this->response(...) y
// $this->get(...). No es una copia de la libreria real - es un parche
// para poder probar la LOGICA de nuestro modulo sin instalar todo
// CodeIgniter. Nunca se sube al servidor.
namespace chriskacerguis\RestServer;

// Los controllers reales hacen "require" (no "require_once") de este
// archivo en su propio constructor - si el mismo script de prueba
// instancia mas de un controller, este archivo se "require"-ea mas de
// una vez. Esta guarda evita el error de "clase ya declarada".
if (class_exists(__NAMESPACE__ . '\\RestController')) {
    return;
}

class RestController {
    public $load;
    public $config;
    public $db;
    public $json;

    public function __construct() {
        $GLOBALS['__CI_INSTANCE'] = $this;
        $this->load = new \Loader();
        $this->config = new \Config();
    }

    protected function response($data, $http_code = 200) {
        // Bajo el servidor HTTP local (router.php) esto tiene que ser
        // JSON real con status code real, para que la app/dashboard lo
        // puedan leer igual que al servidor de produccion. Si headers()
        // ya no se puede llamar (ej. corriendo desde un script CLI de
        // prueba en vez del servidor HTTP), se ignora en silencio y
        // solo se imprime el JSON.
        if (!headers_sent()) {
            http_response_code($http_code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function get($key) {
        return $_GET[$key] ?? null;
    }
}
