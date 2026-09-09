<?php
// Stub vacio - los controllers hacen "require APPPATH.'/libraries/Format.php'"
// porque la libreria real (chriskacerguis) trae este archivo junto con
// RESTController.php, pero nuestro modulo nunca usa la clase Format en
// si misma. Solo existe para que el require no falle.
namespace chriskacerguis\RestServer;

if (class_exists(__NAMESPACE__ . '\\Format')) {
    return;
}

class Format {
}
