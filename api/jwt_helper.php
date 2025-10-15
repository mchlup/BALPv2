<?php
// /balp2/api/jwt_helper.php
function jwt_b64url_encode($data) {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function jwt_b64url_decode($data) {
  $remainder = strlen($data) % 4;
  if ($remainder) $data .= str_repeat('=', 4 - $remainder);
  return base64_decode(strtr($data, '-_', '+/'));
}
function jwt_encode($payload, $secret) {
  $header = ['typ' => 'JWT', 'alg' => 'HS256'];
  $segments = [
    jwt_b64url_encode(json_encode($header)),
    jwt_b64url_encode(json_encode($payload)),
  ];
  $signing_input = implode('.', $segments);
  $signature = hash_hmac('sha256', $signing_input, $secret, true);
  $segments[] = jwt_b64url_encode($signature);
  return implode('.', $segments);
}
function jwt_decode($jwt, $secret, $verifyExp=true) {
  $parts = explode('.', $jwt);
  if (count($parts) !== 3) throw new Exception('Invalid token format');
  list($b64_header, $b64_payload, $b64_sig) = $parts;
  $payload = json_decode(jwt_b64url_decode($b64_payload), true);
  $sig = jwt_b64url_decode($b64_sig);
  $expected = hash_hmac('sha256', $b64_header.'.'.$b64_payload, $secret, true);
  if (!hash_equals($expected, $sig)) throw new Exception('Invalid signature');
  if ($verifyExp && isset($payload['exp']) && time() >= $payload['exp']) throw new Exception('Token expired');
  return $payload;
}

