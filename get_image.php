<?php
// Permitir peticiones desde cualquier origen
header("Access-Control-Allow-Origin: *");
// --- Validación de Seguridad ---
// Obtenemos la ruta de la imagen desde el parámetro 'path'
$imagePath = isset($_GET['path']) ? $_GET['path'] : '';
// Limpiamos la ruta para evitar ataques de Directory Traversal
// Elimina '..' y asegúrate de que no empiece con '/'
$imagePath = str_replace('..', '', $imagePath);
if (substr($imagePath, 0, 1) === '/') {
    $imagePath = substr($imagePath, 1);
}
// Construimos la ruta completa y segura al archivo
$fullPath = __DIR__ . '/' . $imagePath;
// Verificamos que el archivo exista y esté dentro de un directorio permitido (ej. 'images')
$baseDir = realpath(__DIR__ . '/images');
$realFullPath = realpath($fullPath);
if (!$realFullPath || strpos($realFullPath, $baseDir) !== 0 || !file_exists($realFullPath)) {
    header("HTTP/1.0 404 Not Found");
    exit('Image not found.');
}
// --- Fin de la Validación ---
// Obtenemos el tipo MIME del archivo para enviarlo en la cabecera correcta
$mimeType = mime_content_type($realFullPath);
header("Content-Type: " . $mimeType);
// Imprimimos el contenido del archivo de imagen
readfile($realFullPath);
exit;

