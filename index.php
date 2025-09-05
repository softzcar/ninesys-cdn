<?php
// Configurar encabezados CORS
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization');

// Obtener el método HTTP
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    // Manejar la solicitud preflight CORS
    header('HTTP/1.1 200 OK');
    exit();
}

// Get params
$id_empresa = isset($_REQUEST['id_empresa']) ? $_REQUEST['id_empresa'] : null;
$id_orden = isset($_REQUEST['id_orden']) ? $_REQUEST['id_orden'] : null;

// Create Path
$imagePath = 'images/' . $id_empresa . '/';

if ($method === 'POST') {
    $file_upload_flag = true;  // Flag to check conditions
    $file_up_size = $_FILES['file']['size'];

    $resp['data_field']['file_name'] = $_FILES['file']['name'];
    $resp['data_field']['file_size'] = $_FILES['file']['size'];
    $resp['data_field']['file_type'] = $_FILES['file']['type'];
    $resp['data_field']['file_tmp_name'] = $_FILES['file']['tmp_name'];
    $resp['data_field']['file_error'] = $_FILES['file']['error'];

    // Check file type
    $valid_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff'];
    if (!in_array($_FILES['file']['type'], $valid_types)) {
        $resp['msg'] = 'Su archivo debe ser JPG, PNG, GIF, WebP o TIFF.';
        $file_upload_flag = false;
    } else {
        // Set new file name with .png extension
        $extension = '.png';

        // Create the correlated name for the review image.
        $file_name = $id_orden . $extension;

        // Create directories if they do not exist
        if (!file_exists($imagePath)) {
            mkdir($imagePath, 0777, true);
        }
    }

    // The path with the file name where the file will be stored
    $add = $imagePath . $file_name;

    if ($file_upload_flag) {  // Checking the Flag value
        // Obtener contenido del archivo
        $fileTmpPath = $_FILES['file']['tmp_name'];

        // Crear imagen desde el contenido dependiendo del tipo de archivo
        switch ($_FILES['file']['type']) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($fileTmpPath);
                break;
            case 'image/png':
                $image = imagecreatefrompng($fileTmpPath);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($fileTmpPath);
                break;
            case 'image/webp':
                $image = imagecreatefromwebp($fileTmpPath);
                break;
            case 'image/tiff':
            case 'image/tif':
                $image = imagecreatefromstring(file_get_contents($fileTmpPath));
                break;
            default:
                $image = false;
                break;
        }

        if ($image === false) {
            $resp['uploaded'] = false;
            $resp['msg'] = 'Error al crear la imagen desde el contenido.';
        } else {
            // Obtener dimensiones actuales
            $width = imagesx($image);
            $height = imagesy($image);

            // Calcular nuevas dimensiones manteniendo la proporción
            $new_width = 600;
            $new_height = intval(($height / $width) * $new_width);

            // Crear una nueva imagen en blanco
            $resized_image = imagecreatetruecolor($new_width, $new_height);
            if ($resized_image === false) {
                $resp['uploaded'] = false;
                $resp['msg'] = 'Error al crear la nueva imagen redimensionada.';
            } else {
                // Redimensionar la imagen
                $resampleResult = imagecopyresampled($resized_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                if ($resampleResult === false) {
                    $resp['uploaded'] = false;
                    $resp['msg'] = 'Error al redimensionar la imagen.';
                } else {
                    // Guardar la imagen redimensionada siempre como .png
                    $saveResult = imagepng($resized_image, $add, 8);  // Comprimir y guardar como PNG con calidad de 8

                    if ($saveResult === false) {
                        $resp['uploaded'] = false;
                        $resp['msg'] = 'Error al guardar la imagen redimensionada.';
                    } else {
                        imagedestroy($image);
                        imagedestroy($resized_image);

                        $resp['url'] = 'https://cdn.nineteengreen.com/' . $add;
                        $resp['uploaded'] = true;
                        $resp['msg'] = 'El archivo se ha subido y redimensionado correctamente.';
                    }
                }
            }
        }
    } else {
        $resp['uploaded'] = false;
    }
} else if ($method === 'GET') {
    $imagenes = glob($imagePath . $id_orden . '.{jpg,png,gif,webp,tif,tiff}', GLOB_BRACE);

    if (count($imagenes) === 0 || $imagenes === null) {
        $resp['url'] = 'images/no-image.png';
        $resp['mensaje'] = 'No se encontró el diseño';
        $resp['type_images'] = $imagenes;
        $resp['REQUEST'] = $_REQUEST;
    } else {
        $resp['url'] = $imagenes[0];
    }

    if ($resp['url'] === null) {
        $resp['url'] = 'images/no-image.png';
        $resp['mensaje'] = 'No se encontró el diseño';
        $resp['type_url'] = 'NONE';
    }
} else if ($method === 'DELETE') {
    if (!$id_empresa || !$id_orden) {
        $resp['deleted'] = false;
        $resp['msg'] = 'id_empresa and id_orden are required for deletion.';
    } else {
        $imagenes = glob($imagePath . $id_orden . '.{jpg,png,gif,webp,tif,tiff}', GLOB_BRACE);

        if (count($imagenes) > 0) {
            $deleted_count = 0;
            foreach ($imagenes as $imagen) {
                if (unlink($imagen)) {
                    $deleted_count++;
                }
            }
            if ($deleted_count > 0) {
                $resp['deleted'] = true;
                $resp['msg'] = $deleted_count . ' image(s) deleted successfully.';
            } else {
                $resp['deleted'] = false;
                $resp['msg'] = 'Could not delete the image(s). Check file permissions.';
            }
        } else {
            $resp['deleted'] = false;
            $resp['msg'] = 'No image found to delete.';
        }
    }
}

echo json_encode($resp);
