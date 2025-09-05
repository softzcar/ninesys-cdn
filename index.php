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

// Create Path
$imagePath = 'images/' . $_GET['id_empresa'] . '/' . $_GET['id_orden'] . '/';
$imagePathBack = 'images/' . $_GET['id_empresa'] . '/' . $_GET['id_orden'] . '/';

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
        $file_name = $_GET['id_orden'] . '-' . $_GET['review'] . '-' . $_GET['id_empleado'] . $extension;

        // Create directories if they do not exist
        if (!file_exists($imagePath)) {
            mkdir($imagePath, 0777, true);
        }
    }

    // The path with the file name where the file will be stored
    $add = $imagePath . $file_name;

    if ($file_upload_flag) {  // Checking the Flag value
        // Remove previous file
        unlink($imagePath . $_GET['id_orden'] . '-' . $_GET['review'] . '-' . $_GET['id_empleado'] . '.jpg');
        unlink($imagePath . $_GET['id_orden'] . '-' . $_GET['review'] . '-' . $_GET['id_empleado'] . '.jpeg');
        unlink($imagePath . $_GET['id_orden'] . '-' . $_GET['review'] . '-' . $_GET['id_empleado'] . '.png');
        unlink($imagePath . $_GET['id_orden'] . '-' . $_GET['review'] . '-' . $_GET['id_empleado'] . '.gif');
        unlink($imagePath . $_GET['id_orden'] . '-' . $_GET['review'] . '-' . $_GET['id_empleado'] . '.webp');
        unlink($imagePath . $_GET['id_orden'] . '-' . $_GET['review'] . '-' . $_GET['id_empleado'] . '.tif');
        unlink($imagePath . $_GET['id_orden'] . '-' . $_GET['review'] . '-' . $_GET['id_empleado'] . '.tiff');

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
    $_GET['review'] = ($_GET['review'] === 'undefined' || $_GET['review'] === 'null') ? '1' : $_GET['review'];
    $imagenes = glob($imagePath . $_GET['id_orden'] . '-' . $_GET['review'] . '-' . $_GET['id_empleado'] . '.{jpg,png,gif,webp,tif,tiff}', GLOB_BRACE);

    if (count($imagenes) === 0 || $imagenes === null) {
        $resp['url'] = 'images/no-image.png';
        $resp['mensaje'] = 'No se encontró el diseño';
        $resp['type_images'] = $imagenes;
        $resp['GET'] = $_GET;
        $resp['url_bak'] = $imagePathBack;
    } else {
        $resp['url'] = $imagenes[0];
    }

    if ($resp['url'] === null) {
        $resp['url'] = 'images/no-image.png';
        $resp['mensaje'] = 'No se encontró el diseño';
        $resp['type_url'] = 'NONE';
    }
}

echo json_encode($resp);
