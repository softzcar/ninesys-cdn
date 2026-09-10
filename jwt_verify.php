<?php
// Verificación mínima de JWT HS256, sin librerías (este repo nunca tuvo
// Composer/vendor) -- auditoría de seguridad 2026-09-10, cierra el hallazgo
// C6 (ver [[project_fase_seguridad_pendiente]]). Valida el MISMO JWT de
// sesión que emite ninesys-api en /login (secreto compartido
// NINESYS_API_JWT_SECRET, nunca visible en el navegador) -- solo verifica,
// nunca firma nada acá.

function base64UrlDecode(string $data): string
{
    $padded = strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4);
    return base64_decode($padded);
}

/**
 * Verifica la firma HS256 y la expiración de un JWT. Devuelve los claims
 * decodificados o null si el token es inválido, está mal formado, tiene un
 * algoritmo distinto a HS256, o ya expiró.
 */
function verificarSesionJwt(string $jwt): ?array
{
    $secret = getenv('NINESYS_API_JWT_SECRET') ?: '';
    if ($secret === '' || $jwt === '') {
        return null;
    }

    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    [$headerB64, $payloadB64, $sigB64] = $parts;

    $header = json_decode(base64UrlDecode($headerB64), true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
        return null;
    }

    $expectedSig = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true);
    $actualSig = base64UrlDecode($sigB64);
    if (!hash_equals($expectedSig, $actualSig)) {
        return null;
    }

    $payload = json_decode(base64UrlDecode($payloadB64), true);
    if (!is_array($payload)) {
        return null;
    }
    if (!isset($payload['exp']) || time() >= (int) $payload['exp']) {
        return null;
    }

    return $payload;
}

/**
 * Exige un JWT de sesión válido en el header Authorization: Bearer, y que
 * su id_empresa coincida con el id_empresa pedido. Si algo falla, escribe
 * la respuesta de error (401/403) y termina la ejecución -- se llama al
 * inicio de cada acción que necesita autenticación.
 */
function obtenerAuthorizationHeader(): string
{
    // El .htaccess de este repo hace un rewrite interno (RewriteRule (.*) /$1),
    // lo que en Apache/LiteSpeed suele renombrar HTTP_AUTHORIZATION a
    // REDIRECT_HTTP_AUTHORIZATION en $_SERVER -- se prueban ambos, más
    // getallheaders() como respaldo (case-insensitive, PHP normaliza el
    // nombre en LSAPI/Apache).
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                return $value;
            }
        }
    }
    return '';
}

function exigirSesionCdn(?int $idEmpresaSolicitado): void
{
    $authHeader = obtenerAuthorizationHeader();
    $token = (stripos($authHeader, 'Bearer ') === 0) ? trim(substr($authHeader, 7)) : '';

    $claims = verificarSesionJwt($token);
    if ($claims === null) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_token', 'message' => 'Sesión inválida o expirada.']);
        exit();
    }

    if ($idEmpresaSolicitado !== null && (int) ($claims['id_empresa'] ?? 0) !== $idEmpresaSolicitado) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden', 'message' => 'No tiene permiso para operar esta empresa.']);
        exit();
    }
}
