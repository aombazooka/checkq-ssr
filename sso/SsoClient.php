<?php
declare(strict_types=1);

/*
 * OIDC client แบบไฟล์เดียว ไม่ต้องใช้ composer — รองรับ PHP 7.2 ขึ้นไป (ต้องมี curl, openssl, json)
 *
 *   $sso = new SsoClient([
 *       'issuer'        => 'http://localhost/sso',
 *       'client_id'     => 'booking',
 *       'client_secret' => '...',
 *       'redirect_uri'  => 'http://localhost/booking/sso/callback.php',
 *       'scope'         => 'openid profile employee offline_access',
 *       'cache_dir'     => __DIR__ . '/storage',
 *       'ca_file'       => '',   // ไฟล์ CA ขององค์กร เมื่อ SSO ใช้ใบรับรองที่ออกเอง (ว่าง = ใช้ของระบบ)
 *   ]);
 *
 * ต้อง session_start() ก่อนเรียก login() / handleCallback()
 * ห้ามใช้ไวยากรณ์ PHP 7.4+ ในไฟล์นี้ (arrow fn, ??=, typed property, str_starts_with, union type)
 */

final class SsoException extends RuntimeException {}

final class SsoClient
{
    private const LEEWAY = 60; // ยอมให้นาฬิกาสองเครื่องต่างกันได้ 60 วินาที

    /** @var array */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /** ขั้นที่ 1: ส่งผู้ใช้ไปหน้า login ของ SSO (ไม่กลับมาจากฟังก์ชันนี้) */
    public function login(string $returnTo = ''): void
    {
        $state = $this->random();
        $nonce = $this->random();
        $verifier = $this->random(48);

        // เก็บค่าชั่วคราวไว้ตรวจตอนกลับมา (รองรับเปิดหลายแท็บพร้อมกัน)
        $_SESSION['sso_tx'] = array_slice(isset($_SESSION['sso_tx']) ? (array)$_SESSION['sso_tx'] : [], -4, 4, true);
        $_SESSION['sso_tx'][$state] = ['nonce' => $nonce, 'verifier' => $verifier, 'return_to' => $returnTo, 'at' => time()];

        $query = http_build_query([
            'response_type'         => 'code',
            'client_id'             => $this->cfg['client_id'],
            'redirect_uri'          => $this->cfg['redirect_uri'],
            'scope'                 => isset($this->cfg['scope']) ? $this->cfg['scope'] : 'openid profile',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $this->b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ]);
        $discovery = $this->discovery();
        header('Location: ' . $discovery['authorization_endpoint'] . '?' . $query);
        exit;
    }

    /**
     * ขั้นที่ 2: รับ code ที่ redirect_uri → แลก token → ตรวจ id_token
     * @return array{claims: array, tokens: array, return_to: string}
     */
    public function handleCallback(): array
    {
        $state = isset($_GET['state']) && is_string($_GET['state']) ? $_GET['state'] : '';
        $tx = isset($_SESSION['sso_tx'][$state]) ? $_SESSION['sso_tx'][$state] : null;
        unset($_SESSION['sso_tx'][$state]);
        if ($tx === null || $tx['at'] < time() - 600) {
            throw new SsoException('คำขอเข้าสู่ระบบหมดอายุหรือไม่ถูกต้อง (state) กรุณาลองใหม่');
        }
        if (isset($_GET['iss']) && $_GET['iss'] !== $this->cfg['issuer']) {
            throw new SsoException('ผู้ออก token ไม่ตรงกับที่ตั้งค่าไว้');
        }
        if (isset($_GET['error'])) {
            $desc = isset($_GET['error_description']) ? $_GET['error_description'] : $_GET['error'];
            throw new SsoException('SSO ปฏิเสธคำขอ: ' . (string)$desc);
        }
        $code = isset($_GET['code']) && is_string($_GET['code']) ? $_GET['code'] : '';
        if ($code === '') {
            throw new SsoException('ไม่ได้รับ authorization code');
        }

        $tokens = $this->tokenRequest([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->cfg['redirect_uri'],
            'code_verifier' => $tx['verifier'],
        ]);
        if (isset($tokens['error'])) {
            throw new SsoException('แลก token ไม่สำเร็จ: ' . (isset($tokens['error_description']) ? $tokens['error_description'] : $tokens['error']));
        }
        $claims = $this->verifyIdToken(isset($tokens['id_token']) ? (string)$tokens['id_token'] : '', $tx['nonce']);
        return ['claims' => $claims, 'tokens' => $tokens, 'return_to' => (string)$tx['return_to']];
    }

    /**
     * ต่ออายุด้วย refresh token — IdP จะตรวจ session และสิทธิ์ล่าสุดให้
     * @return array{claims: array, tokens: array}|null  null = ถูกปฏิเสธ (logout แล้ว / ถอนสิทธิ์ / ระงับบัญชี)
     * @throws SsoException เมื่อติดต่อ IdP ไม่ได้
     */
    public function refresh(string $refreshToken): ?array
    {
        $tokens = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
        if (isset($tokens['error']) && $tokens['error'] === 'invalid_grant') {
            return null;
        }
        if (isset($tokens['error'])) {
            throw new SsoException('ต่ออายุไม่สำเร็จ: ' . $tokens['error']);
        }
        return ['claims' => $this->verifyIdToken(isset($tokens['id_token']) ? (string)$tokens['id_token'] : '', null), 'tokens' => $tokens];
    }

    /** URL สำหรับ Single Logout */
    public function logoutUrl(?string $idTokenHint, string $postLogoutRedirectUri): string
    {
        $discovery = $this->discovery();
        return $discovery['end_session_endpoint'] . '?' . http_build_query(array_filter([
            'id_token_hint'            => $idTokenHint,
            'client_id'                => $this->cfg['client_id'],
            'post_logout_redirect_uri' => $postLogoutRedirectUri,
        ]));
    }

    /** ตรวจ logout_token ที่ IdP POST มาที่ back-channel endpoint */
    public function verifyLogoutToken(string $jwt): array
    {
        list($header, $claims) = $this->verifyJwt($jwt);
        if ((isset($header['typ']) ? $header['typ'] : '') !== 'logout+jwt'
            || !isset($claims['events']['http://schemas.openid.net/event/backchannel-logout'])
            || isset($claims['nonce'])
            || (empty($claims['sid']) && empty($claims['sub']))
            || (int)(isset($claims['iat']) ? $claims['iat'] : 0) < time() - 300) {
            throw new SsoException('logout_token ไม่ถูกต้อง');
        }
        return $claims;
    }

    /**
     * ค่าตั้งของระบบนี้ที่ผู้ดูแล SSO กำหนด (เช่น การเข้าระบบโดยตรง) พร้อมส่งบันทึกเหตุการณ์ที่ค้างไว้
     * @throws SsoException เมื่อติดต่อ SSO ไม่ได้หรือ client_secret ผิด
     */
    public function clientSettings(array $events = []): array
    {
        $data = $this->http('POST', rtrim($this->cfg['issuer'], '/') . '/client_settings.php', ['events' => json_encode($events)], [
            'Authorization: Basic ' . base64_encode(rawurlencode($this->cfg['client_id']) . ':' . rawurlencode($this->cfg['client_secret'])),
        ], true);
        if (isset($data['error']) || !isset($data['local_login'])) {
            throw new SsoException('อ่านค่าตั้งจาก SSO ไม่ได้: ' . (isset($data['error']) ? $data['error'] : 'รูปแบบไม่ถูกต้อง'));
        }
        return $data;
    }

    public function discovery(): array
    {
        $self = $this;
        return $this->cached('discovery.json', 3600, function () use ($self) {
            $doc = $self->httpGet(rtrim($self->config('issuer'), '/') . '/.well-known/openid-configuration');
            if ((isset($doc['issuer']) ? $doc['issuer'] : '') !== $self->config('issuer')) {
                throw new SsoException('discovery: issuer ไม่ตรงกับที่ตั้งค่าไว้');
            }
            return $doc;
        });
    }

    /** @internal ใช้ใน closure เท่านั้น */
    public function config(string $key): string
    {
        return isset($this->cfg[$key]) ? (string)$this->cfg[$key] : '';
    }

    /** @internal ใช้ใน closure เท่านั้น */
    public function httpGet(string $url): array
    {
        return $this->http('GET', $url);
    }

    // ------------------------------------------------------------------

    private function verifyIdToken(string $jwt, ?string $nonce): array
    {
        list(, $claims) = $this->verifyJwt($jwt);
        if ($nonce !== null && !hash_equals($nonce, (string)(isset($claims['nonce']) ? $claims['nonce'] : ''))) {
            throw new SsoException('id_token: nonce ไม่ตรง');
        }
        if (empty($claims['sub'])) {
            throw new SsoException('id_token: ไม่มี sub');
        }
        return $claims;
    }

    /** ตรวจลายเซ็น (RS256 + JWKS), iss, aud, exp */
    private function verifyJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new SsoException('token รูปแบบไม่ถูกต้อง');
        }
        $header = json_decode((string)$this->b64urlDecode($parts[0]), true);
        $claims = json_decode((string)$this->b64urlDecode($parts[1]), true);
        if (!is_array($header) || !is_array($claims) || (isset($header['alg']) ? $header['alg'] : '') !== 'RS256') {
            throw new SsoException('token: alg ไม่รองรับ');
        }

        $key = $this->publicKey(isset($header['kid']) ? (string)$header['kid'] : '');
        if (openssl_verify($parts[0] . '.' . $parts[1], (string)$this->b64urlDecode($parts[2]), $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new SsoException('token: ลายเซ็นไม่ถูกต้อง');
        }

        $aud = isset($claims['aud']) ? (array)$claims['aud'] : [];
        if ((isset($claims['iss']) ? $claims['iss'] : '') !== $this->cfg['issuer']) {
            throw new SsoException('token: iss ไม่ถูกต้อง');
        }
        if (!in_array($this->cfg['client_id'], $aud, true)) {
            throw new SsoException('token: ไม่ได้ออกให้ระบบนี้ (aud)');
        }
        if (isset($claims['exp']) && (int)$claims['exp'] < time() - self::LEEWAY) {
            throw new SsoException('token: หมดอายุ');
        }
        if ((int)(isset($claims['iat']) ? $claims['iat'] : 0) > time() + self::LEEWAY) {
            throw new SsoException('token: เวลาออกอยู่ในอนาคต');
        }
        return [$header, $claims];
    }

    /** @return resource|OpenSSLAsymmetricKey */
    private function publicKey(string $kid)
    {
        $find = function (array $jwks) use ($kid) {
            foreach (isset($jwks['keys']) ? $jwks['keys'] : [] as $jwk) {
                if ((isset($jwk['kty']) ? $jwk['kty'] : '') === 'RSA' && ($kid === '' || (isset($jwk['kid']) ? $jwk['kid'] : '') === $kid)) {
                    return $jwk;
                }
            }
            return null;
        };
        $self = $this;
        $loader = function () use ($self) {
            $discovery = $self->discovery();
            return $self->httpGet($discovery['jwks_uri']);
        };
        $jwk = $find($this->cached('jwks.json', 86400, $loader));
        if ($jwk === null) {
            // ไม่เจอ kid = IdP เพิ่งเปลี่ยนกุญแจ → โหลด JWKS ใหม่หนึ่งครั้ง
            $jwk = $find($this->cached('jwks.json', 0, $loader));
        }
        $key = $jwk !== null ? openssl_pkey_get_public(self::jwkToPem($jwk)) : false;
        if ($key === false) {
            throw new SsoException('ไม่พบ public key ของ SSO (kid ' . $kid . ')');
        }
        return $key;
    }

    private function tokenRequest(array $params): array
    {
        $discovery = $this->discovery();
        return $this->http('POST', $discovery['token_endpoint'], $params, [
            'Authorization: Basic ' . base64_encode(rawurlencode($this->cfg['client_id']) . ':' . rawurlencode($this->cfg['client_secret'])),
        ], true);
    }

    private function http(string $method, string $url, ?array $form = null, array $headers = [], bool $allowErrorBody = false): array
    {
        if (!function_exists('curl_init')) {
            throw new SsoException('PHP ไม่ได้เปิด extension curl');
        }
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if (!empty($this->cfg['ca_file'])) {
            $options[CURLOPT_CAINFO] = $this->cfg['ca_file'];
        }
        curl_setopt_array($ch, $options);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form === null ? [] : $form));
        }
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new SsoException('ติดต่อ SSO ไม่ได้: ' . $error);
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data) || ($status >= 400 && !$allowErrorBody)) {
            throw new SsoException('SSO ตอบกลับผิดรูปแบบ (HTTP ' . $status . ')');
        }
        return $data;
    }

    private function cached(string $name, int $ttl, callable $loader): array
    {
        $file = rtrim($this->cfg['cache_dir'], '/\\') . '/' . md5($this->cfg['issuer']) . '-' . $name;
        if ($ttl > 0 && is_file($file) && filemtime($file) > time() - $ttl) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data)) {
                return $data;
            }
        }
        $data = $loader();
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return $data;
    }

    /** แปลง JWK (n, e) เป็น PEM ด้วย ASN.1 DER — PHP ไม่มีฟังก์ชันนี้ในตัว */
    private static function jwkToPem(array $jwk): string
    {
        $len = function (int $n): string {
            if ($n < 0x80) {
                return chr($n);
            }
            $bytes = ltrim(pack('N', $n), "\0");
            return chr(0x80 | strlen($bytes)) . $bytes;
        };
        $int = function (string $bin) use ($len): string {
            $bin = ltrim($bin, "\0");
            if ($bin === '' || ord($bin[0]) > 0x7f) {
                $bin = "\0" . $bin;
            }
            return "\x02" . $len(strlen($bin)) . $bin;
        };
        $decode = function (string $s): string {
            return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
        };

        $rsa = $int($decode((string)$jwk['n'])) . $int($decode((string)$jwk['e']));
        $rsa = "\x30" . $len(strlen($rsa)) . $rsa;
        $bitString = "\x03" . $len(strlen($rsa) + 1) . "\x00" . $rsa;
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"; // rsaEncryption
        $spki = "\x30" . $len(strlen($algorithm . $bitString)) . $algorithm . $bitString;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function random(int $bytes = 32): string
    {
        return $this->b64url(random_bytes($bytes));
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** @return string|false */
    private function b64urlDecode(string $data)
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    }
}
