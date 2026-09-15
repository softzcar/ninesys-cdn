<?php
// Cargador mínimo de .env (este repo nunca tuvo Composer/dotenv) -- auditoría
// de seguridad 2026-09-10, ver jwt_verify.php.
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}
require_once __DIR__ . '/jwt_verify.php';

// Configurar encabezados CORS -- antes '*' (cualquier sitio en Internet podía
// hacer fetch() cross-origin contra este CDN desde el navegador de un
// visitante, auditoría de seguridad 2026-09-09/10). Solo app_multi llama
// desde el navegador (subir/listar/borrar de Galería); las imágenes servidas
// vía <img src> no necesitan CORS en absoluto (el navegador las carga cross-
// origin igual, con o sin esta cabecera).
$allowedOrigins = [
    'https://app.ninesys19.com',
    'https://app.nineteengreen.com',
    'http://localhost:3000',
];
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($requestOrigin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
}
header('Vary: Origin');
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization');

// Obtener el método HTTP
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    header('HTTP/1.1 200 OK');
    exit();
}

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$baseUrl  = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/';

// Get params -- id_empresa/id_orden/id se usan crudos para armar rutas de
// archivo y patrones glob (abajo); sin sanear, un id_orden como "../../../etc"
// permite escapar del directorio images/ tanto para lectura como para
// BORRADO (unlink) -- auditoría de seguridad 2026-09-09. Son identificadores
// numéricos de negocio (id de empresa / id de orden), así que castear a
// entero es seguro y no cambia el comportamiento para uso legítimo.
$id_empresa = isset($_REQUEST['id_empresa']) && $_REQUEST['id_empresa'] !== '' ? intval($_REQUEST['id_empresa']) : null;
$id_orden   = isset($_REQUEST['id_orden'])   && $_REQUEST['id_orden']   !== '' ? intval($_REQUEST['id_orden'])   : null;
$id         = isset($_REQUEST['id'])         && $_REQUEST['id']         !== '' ? intval($_REQUEST['id'])         : null;
// $review no es necesariamente numérico -- se sanea a alfanumérico (sin
// '/', '.', '\') en vez de intval() para no romper valores legítimos.
$review     = isset($_REQUEST['review']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_REQUEST['review']) : null;
$aprobada   = isset($_REQUEST['aprobada'])   ? $_REQUEST['aprobada']   : null;

// Create Path
$imagePath = 'images/' . $id_empresa . '/';

// ── Subida de imágenes de galería de catálogo (se maneja antes del bloque POST) ──
$gallery_action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
if ($gallery_action === 'gallery_upload') {
    $id      = intval($_REQUEST['id_empresa'] ?? 0);
    exigirSesionCdn($id ?: null);
    $product = preg_replace('/[^a-z0-9\-]/', '', strtolower($_REQUEST['product'] ?? ''));
    if (!$id || !$product || empty($_FILES['file']['tmp_name'])) {
        echo json_encode(['uploaded' => false, 'msg' => 'Parámetros incompletos']);
        exit();
    }
    $valid_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($_FILES['file']['type'], $valid_types)) {
        echo json_encode(['uploaded' => false, 'msg' => 'Tipo de archivo no permitido']);
        exit();
    }
    $orig = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);
    $orig = strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '-', $orig));
    $orig = trim(preg_replace('/-+/', '-', $orig), '-');
    if (!$orig) $orig = 'imagen';
    $galleryDir = 'images/' . $id . '/gallery/' . $product . '/';
    if (!file_exists($galleryDir)) mkdir($galleryDir, 0755, true);
    $filename = $orig . '.png';
    $i = 1;
    while (file_exists($galleryDir . $filename)) {
        $filename = $orig . '-' . $i . '.png';
        $i++;
    }
    $dest = $galleryDir . $filename;
    $tmp  = $_FILES['file']['tmp_name'];
    switch ($_FILES['file']['type']) {
        case 'image/jpeg': $img = imagecreatefromjpeg($tmp); break;
        case 'image/png':  $img = imagecreatefrompng($tmp);  break;
        case 'image/gif':  $img = imagecreatefromgif($tmp);  break;
        case 'image/webp': $img = imagecreatefromwebp($tmp); break;
        default: $img = false;
    }
    if (!$img) {
        echo json_encode(['uploaded' => false, 'msg' => 'No se pudo leer la imagen']);
        exit();
    }
    $w = imagesx($img); $h = imagesy($img);
    $nw = 800; $nh = intval(($h / $w) * $nw);
    $out = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagepng($out, $dest, 8);
    imagedestroy($img); imagedestroy($out);
    echo json_encode(['uploaded' => true, 'url' => $baseUrl . $dest, 'filename' => $filename]);
    exit();
}

if ($method === 'POST') {
    exigirSesionCdn($id_empresa);
    $file_upload_flag = true;
    $file_up_size = $_FILES['file']['size'];

    $resp['data_field']['file_name']     = $_FILES['file']['name'];
    $resp['data_field']['file_size']     = $_FILES['file']['size'];
    $resp['data_field']['file_type']     = $_FILES['file']['type'];
    $resp['data_field']['file_tmp_name'] = $_FILES['file']['tmp_name'];
    $resp['data_field']['file_error']    = $_FILES['file']['error'];

    $valid_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff'];
    if (!in_array($_FILES['file']['type'], $valid_types)) {
        $resp['msg'] = 'Su archivo debe ser JPG, PNG, GIF, WebP o TIFF.';
        $file_upload_flag = false;
    } else {
        $extension = '.png';
        $orden_id  = $id ?: $id_orden;
        if ($aprobada === 'true') {
            $file_name = $orden_id . '-a' . $extension;
        } else {
            $file_name = $orden_id . '-' . $review . $extension;
        }
        if (!file_exists($imagePath)) mkdir($imagePath, 0755, true);
    }

    $add = $imagePath . $file_name;

    if ($file_upload_flag) {
        $fileTmpPath = $_FILES['file']['tmp_name'];
        switch ($_FILES['file']['type']) {
            case 'image/jpeg': $image = imagecreatefromjpeg($fileTmpPath); break;
            case 'image/png':  $image = imagecreatefrompng($fileTmpPath);  break;
            case 'image/gif':  $image = imagecreatefromgif($fileTmpPath);  break;
            case 'image/webp': $image = imagecreatefromwebp($fileTmpPath); break;
            case 'image/tiff':
            case 'image/tif':  $image = imagecreatefromstring(file_get_contents($fileTmpPath)); break;
            default: $image = false; break;
        }
        if ($image === false) {
            $resp['uploaded'] = false;
            $resp['msg'] = 'Error al crear la imagen desde el contenido.';
        } else {
            $width  = imagesx($image);
            $height = imagesy($image);
            $new_width  = 600;
            $new_height = intval(($height / $width) * $new_width);
            $resized_image = imagecreatetruecolor($new_width, $new_height);
            if ($resized_image === false) {
                $resp['uploaded'] = false;
                $resp['msg'] = 'Error al crear la nueva imagen redimensionada.';
            } else {
                $resampleResult = imagecopyresampled($resized_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                if ($resampleResult === false) {
                    $resp['uploaded'] = false;
                    $resp['msg'] = 'Error al redimensionar la imagen.';
                } else {
                    $saveResult = imagepng($resized_image, $add, 8);
                    if ($saveResult === false) {
                        $resp['uploaded'] = false;
                        $resp['msg'] = 'Error al guardar la imagen redimensionada.';
                    } else {
                        imagedestroy($image);
                        imagedestroy($resized_image);
                        $resp['url']      = $baseUrl . $add;
                        $resp['uploaded'] = true;
                        $resp['msg']      = 'El archivo se ha subido y redimensionado correctamente.';
                    }
                }
            }
        }
    } else {
        $resp['uploaded'] = false;
    }

} else if ($method === 'GET') {
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : null;

    if ($action === 'list') {
        if (!$id_empresa) {
            echo json_encode(['images' => [], 'count' => 0]);
            exit();
        }
        exigirSesionCdn($id_empresa);
        $galleryPath = 'images/' . intval($id_empresa) . '/';
        if (!is_dir($galleryPath)) {
            echo json_encode(['images' => [], 'count' => 0]);
            exit();
        }
        $allFiles = glob($galleryPath . '*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [];
        rsort($allFiles);
        $filtered = [];
        foreach ($allFiles as $file) {
            $filtered[] = $baseUrl . $file;
        }
        $filtered = array_slice($filtered, 0, 100);
        echo json_encode(['images' => $filtered, 'count' => count($filtered)]);
        exit();
    }

    if ($action === 'catalog') {
        $id      = intval($id_empresa ?? 0);
        $product = preg_replace('/[^a-z0-9\-]/', '', strtolower($_REQUEST['product'] ?? ''));
        if (!$id || !$product) {
            echo json_encode(['images' => [], 'count' => 0]);
            exit();
        }
        exigirSesionCdn($id);
        $galleryPath = 'images/' . $id . '/gallery/' . $product . '/';
        if (!is_dir($galleryPath)) {
            // Buscar coincidencia por prefijo: 'chaqueta' encuentra 'chaquetas' y viceversa
            $base = 'images/' . $id . '/gallery/';
            foreach (glob($base . '*', GLOB_ONLYDIR) ?: [] as $dir) {
                $name = basename($dir);
                if (strpos($name, $product) === 0 || strpos($product, $name) === 0) {
                    $galleryPath = $dir . '/';
                    break;
                }
            }
        }
        if (!is_dir($galleryPath)) {
            echo json_encode(['images' => [], 'count' => 0]);
            exit();
        }
        $allFiles = glob($galleryPath . '*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [];
        sort($allFiles);
        $images = array_map(fn($f) => $baseUrl . $f, $allFiles);
        echo json_encode(['images' => $images, 'count' => count($images)]);
        exit();
    }

    if ($action === 'gallery_categories') {
        $id = intval($id_empresa ?? 0);
        if (!$id) { echo json_encode(['categories' => []]); exit(); }
        exigirSesionCdn($id);
        $base = 'images/' . $id . '/gallery/';
        $cats = [];
        if (is_dir($base)) {
            foreach (glob($base . '*', GLOB_ONLYDIR) ?: [] as $dir) {
                $name  = basename($dir);
                $count = count(glob($dir . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: []);
                $cats[] = ['name' => $name, 'count' => $count];
            }
        }
        echo json_encode(['categories' => $cats]);
        exit();
    }

    // Fallback: búsqueda de imagen de revisión/orden (uso original del CDN)
    if ($aprobada === 'true') {
        $imagenes = glob($imagePath . $id_orden . '-a.{jpg,png,gif,webp,tif,tiff}', GLOB_BRACE);
    } elseif ($review) {
        $imagenes = glob($imagePath . $id_orden . '-' . $review . '.{jpg,png,gif,webp,tif,tiff}', GLOB_BRACE);
    } else {
        $imagenes = glob($imagePath . $id_orden . '.{jpg,png,gif,webp,tif,tiff}', GLOB_BRACE);
    }

    if (count($imagenes) === 0 || $imagenes === null) {
        $resp['url']        = 'images/no-image.png';
        $resp['mensaje']    = 'No se encontró el diseño';
        $resp['type_images'] = $imagenes;
        $resp['REQUEST']    = $_REQUEST;
    } else {
        $resp['url'] = $imagenes[0];
    }

    if ($resp['url'] === null) {
        $resp['url']      = 'images/no-image.png';
        $resp['mensaje']  = 'No se encontró el diseño';
        $resp['type_url'] = 'NONE';
    }

} else if ($method === 'DELETE') {
    exigirSesionCdn($id_empresa);
    $del_action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

    // ── Creación de categoría de galería (directorio vacío) ───────────────
    if ($del_action === 'gallery_create_category') {
        $id      = intval($_REQUEST['id_empresa'] ?? 0);
        $product = preg_replace('/[^a-z0-9\-]/', '', strtolower($_REQUEST['product'] ?? ''));
        if (!$id || strlen($product) < 2) {
            echo json_encode(['created' => false, 'msg' => 'Parámetros incompletos']);
            exit();
        }
        $categoryDir = 'images/' . $id . '/gallery/' . $product . '/';
        if (is_dir($categoryDir)) {
            echo json_encode(['created' => false, 'msg' => 'La categoría ya existe']);
            exit();
        }
        $ok = mkdir($categoryDir, 0755, true);
        echo json_encode(['created' => $ok, 'name' => $product]);
        exit();
    }

    // ── Eliminación de imagen individual de galería ───────────────────────
    if ($del_action === 'gallery_delete') {
        $id       = intval($_REQUEST['id_empresa'] ?? 0);
        $product  = preg_replace('/[^a-z0-9\-]/', '', strtolower($_REQUEST['product'] ?? ''));
        $filename = basename($_REQUEST['filename'] ?? '');
        $filename = preg_replace('/[^a-zA-Z0-9\-_.]/', '', $filename);
        $filePath = 'images/' . $id . '/gallery/' . $product . '/' . $filename;
        if ($id && $product && $filename && file_exists($filePath)) {
            unlink($filePath);
            echo json_encode(['deleted' => true]);
        } else {
            echo json_encode(['deleted' => false, 'msg' => 'Archivo no encontrado']);
        }
        exit();
    }

    // ── Eliminación completa de categoría de galería ──────────────────────
    if ($del_action === 'gallery_delete_category') {
        $id      = intval($_REQUEST['id_empresa'] ?? 0);
        $product = preg_replace('/[^a-z0-9\-]/', '', strtolower($_REQUEST['product'] ?? ''));
        if (!$id || !$product) {
            echo json_encode(['deleted' => false, 'msg' => 'Parámetros incompletos']);
            exit();
        }
        $categoryDir = 'images/' . $id . '/gallery/' . $product . '/';
        if (!is_dir($categoryDir)) {
            echo json_encode(['deleted' => false, 'msg' => 'Categoría no encontrada']);
            exit();
        }
        $files   = glob($categoryDir . '*') ?: [];
        $deleted = 0;
        foreach ($files as $file) {
            if (is_file($file) && unlink($file)) $deleted++;
        }
        $rmResult = rmdir($categoryDir);
        echo json_encode(['deleted' => $rmResult, 'images_deleted' => $deleted]);
        exit();
    }

    // ── Eliminación de imagen de revisión/orden (uso original del CDN) ────
    if (!$id_empresa || !$id_orden) {
        $resp['deleted'] = false;
        $resp['msg']     = 'id_empresa and id_orden are required for deletion.';
    } else {
        if ($aprobada === 'true') {
            $imagenes = glob($imagePath . $id_orden . '-a.{jpg,png,gif,webp,tif,tiff}', GLOB_BRACE);
        } elseif ($review) {
            $imagenes = glob($imagePath . $id_orden . '-' . $review . '.{jpg,png,gif,webp,tif,tiff}', GLOB_BRACE);
        } else {
            $imagenes = glob($imagePath . $id_orden . '.{jpg,png,gif,webp,tif,tiff}', GLOB_BRACE);
        }

        if (count($imagenes) > 0) {
            $deleted_count = 0;
            foreach ($imagenes as $imagen) {
                if (unlink($imagen)) $deleted_count++;
            }
            if ($deleted_count > 0) {
                $resp['deleted'] = true;
                $resp['msg']     = $deleted_count . ' image(s) deleted successfully.';
            } else {
                $resp['deleted'] = false;
                $resp['msg']     = 'Could not delete the image(s). Check file permissions.';
            }
        } else {
            $resp['deleted'] = false;
            $resp['msg']     = 'No image found to delete.';
        }
    }
}

echo json_encode($resp);
