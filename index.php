<?php
// header('Access-Control-Allow-Origin: *');
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, DELETE, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $file_upload_flag = "true"; // Flag to check conditions
    $file_up_size = $_FILES['file']['size'];

    $resp["data_field"]["file_name"] = $_FILES['file']['name'];
    $resp["data_field"]["file_size"] = $_FILES['file']['size'];
    $resp["data_field"]["file_type"] = $_FILES['file']['type'];
    $resp["data_field"]["file_tmp_name"] = $_FILES['file']['tmp_name'];
    $resp["data_field"]["file_error"] = $_FILES['file']['error'];

    // if ($_FILES['file']['size'] > 250000000) {
    if ($_FILES['file']['size'] > 3 * 1024 * 1024) {
        $resp["msg"] = "Su archivo es demasiado grande, por favor reduzca su peso.";
        $file_upload_flag = "false";
    }

    // allow only jpeg or gif files, remove this if not required //
    if (!($_FILES['file']['type'] == "image/jpeg" or $_FILES['file']['type'] == "image/png")) {
        $resp["msg"] = "Su archivo debe ser JPG o PNG. ";
        $file_upload_flag = "false";
    } else {
        // Set new file name
        if ($_FILES['file']['type'] == "image/jpeg") {
            $extension = ".jpg";
        } else {
            $extension = ".png";
        }

        // Crear el correlativo de el nombre de imagen de la revisión.
        // Si `undefined` la revisión será la numaro 1
        // De lo contrario debemos recibir en numero de la revisión en $GET["review"]

        if ($_GET["review"] === "undefined" || $_GET["review"] === "null") {
            $nroRev = "1";
        } else {
            $nroRev = $_GET["review"];
        }

        $file_name = $_GET["id_orden"] . "-" . $_GET['id_diseno'] . "-" . $_GET["review"] . $extension;
    }

    // the path with the file name where the file will be stored
    $add = "images/$file_name";

    if ($file_upload_flag == "true") { // checking the Flag value
        // Remove previous file
        unlink("images/" . $_GET["id_orden"] . "-" . $_GET['id_diseno'] . "-" . $_GET["review"] . ".jpg");
        unlink("images/" . $_GET["id_orden"] . "-" . $_GET['id_diseno'] . "-" . $_GET["review"] . ".jpeg");
        unlink("images/" . $_GET["id_orden"] . "-" . $_GET['id_diseno'] . "-" . $_GET["review"] . ".png");

        if (move_uploaded_file($_FILES['file']['tmp_name'], $add)) {
            // do your coding here to give a thanks message or any other thing.
            $resp["url"] = "https://cdn.nineteengreen.com/" . $add;
            $resp["uploaded"] = true;
            $resp["msg"] = "El archivo se ha subido correctamente";
        } else {
            $resp["uploaded"] = false;
            $error = error_get_last();
            $errorMessage = $error['message'];
            $resp["msg"] = "Ocurrió un error al subir el archivo por favor intente de nuevo: " . $errorMessage;
        }
    } else {
        $resp["uploaded"] = false;
        // $resp["msg"] = " Ocurrió un error al subir el archivo por favor intente de nuevo ";
    }
} else if ($method === 'GET') {
    // filter images
    // $directory = "images";
    // $imagenes  = glob("images/" . $_GET["id"] . "-*.{jpg,png,gif}", GLOB_BRACE);
    $imagenes = glob("images/" . $_GET["id_orden"] . "-" . $_GET['id_diseno'] . "-" . $_GET["review"] . ".{jpg,png,gif}", GLOB_BRACE);

    if (count($imagenes) === 0 || $imagenes === null) {
        $resp["url"] = "images/no-image.png";
    }

    $resp["url"] = $imagenes[0];

    if ($resp["url"] === null)
        $resp["url"] = "images/no-image.png";
}

echo json_encode($resp);
/*
$myJson = json_encode($respuesta);
echo $myJson; */