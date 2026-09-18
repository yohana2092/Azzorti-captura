<?php
/**
 * Punto de entrada del servidor local real (php -S ... router.php).
 * Traduce una URL tipo "http://<ip>:8000/capturas/homologacion_sugerencias/5"
 * a Catalogos::homologacion_sugerencias_get(5), igual que hace CodeIgniter
 * en el servidor real (controlador/metodo/parametro) - ver bootstrap.php
 * para el resto de la imitacion de CodeIgniter.
 */
require __DIR__ . '/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = trim((string) $uri, '/');
$segmentos = $uri === '' ? [] : explode('/', $uri);

$controlador_segmento = $segmentos[0] ?? '';
if ($controlador_segmento === '') {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['estado' => 'Backend local activo. Usa una ruta como /capturas o /catalogos.']);
    exit;
}

$clase = ucfirst($controlador_segmento);
$archivo = MODULE_PATH . "/controllers/captura_v1/{$clase}.php";
if (!file_exists($archivo)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => false, 'message' => "Controller no encontrado: {$clase}"]);
    exit;
}
require_once $archivo;

$metodo_base = $segmentos[1] ?? 'index';
$parametro = $segmentos[2] ?? null;
$verbo = strtolower($_SERVER['REQUEST_METHOD']);
$metodo = "{$metodo_base}_{$verbo}";

try {
    $controller = new $clase();
    if (!method_exists($controller, $metodo)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => false, 'message' => "Metodo no encontrado: {$clase}::{$metodo}"]);
        exit;
    }
    if ($parametro !== null) {
        $controller->$metodo($parametro);
    } else {
        $controller->$metodo();
    }
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => false,
        'message' => $e->getMessage(),
        'archivo' => $e->getFile() . ':' . $e->getLine(),
    ]);
}
