<?php
/**
 * FirebaseService — lightweight Firebase REST API client for PHP.
 * Handles Firestore CRUD, Storage uploads, and ID-token verification
 * without requiring the gRPC extension.
 */
class FirebaseService
{
    private array  $sa;
    private string $projectId;
    private string $bucket;
    private ?string $cachedToken   = null;
    private int     $tokenExpiry   = 0;

    private const FS_BASE    = 'https://firestore.googleapis.com/v1';
    private const STORE_API  = 'https://storage.googleapis.com/storage/v1';
    private const STORE_UP   = 'https://storage.googleapis.com/upload/storage/v1';
    private const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    private const KEYS_URL   = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    public function __construct(string $serviceAccountPath)
    {
        if (!file_exists($serviceAccountPath)) {
            throw new \RuntimeException("Firebase service account not found: {$serviceAccountPath}");
        }
        $this->sa        = json_decode(file_get_contents($serviceAccountPath), true);
        $this->projectId = $this->sa['project_id'];
        $this->bucket    = getenv('FIREBASE_STORAGE_BUCKET') ?: ($this->projectId . '.firebasestorage.app');
    }

    // ----------------------------------------------------------------
    // ACCESS TOKEN
    // ----------------------------------------------------------------
    private function token(): string
    {
        // Try in-process cache first
        if ($this->cachedToken && time() < $this->tokenExpiry - 60) {
            return $this->cachedToken;
        }
        // Try file cache
        $cacheFile = sys_get_temp_dir() . '/fbsvc_' . md5($this->sa['client_email']) . '.json';
        if (file_exists($cacheFile)) {
            $c = json_decode(file_get_contents($cacheFile), true);
            if ($c && !empty($c['token']) && ($c['exp'] ?? 0) > time() + 60) {
                $this->cachedToken = $c['token'];
                $this->tokenExpiry = $c['exp'];
                return $this->cachedToken;
            }
        }
        $now = time();
        $jwt = $this->makeJWT([
            'iss'   => $this->sa['client_email'],
            'sub'   => $this->sa['client_email'],
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
            'scope' => 'https://www.googleapis.com/auth/datastore https://www.googleapis.com/auth/devstorage.read_write',
        ]);
        $res  = $this->http('POST', self::TOKEN_URL,
            http_build_query(['grant_type' => 'urn:ietf:params:oauth2:grant-type:jwt-bearer', 'assertion' => $jwt]),
            'application/x-www-form-urlencoded', false);
        $data = json_decode($res ?? '', true);
        if (empty($data['access_token'])) {
            throw new \RuntimeException('Firebase token request failed: ' . ($res ?? 'no response from Google'));
        }
        $this->cachedToken = $data['access_token'];
        $this->tokenExpiry = $now + ($data['expires_in'] ?? 3600);
        @file_put_contents($cacheFile, json_encode(['token' => $this->cachedToken, 'exp' => $this->tokenExpiry]));
        return $this->cachedToken;
    }

    private function makeJWT(array $payload): string
    {
        $h = $this->b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $p = $this->b64u(json_encode($payload));
        $in = "$h.$p";
        // Replace literal \n with real newlines in case the key came through an env var
        $rawKey = str_replace('\n', "\n", $this->sa['private_key']);
        $pkey = openssl_pkey_get_private($rawKey);
        if ($pkey === false) {
            throw new \RuntimeException('Invalid Firebase private key — check FIREBASE_SERVICE_ACCOUNT_JSON formatting');
        }
        openssl_sign($in, $sig, $pkey, OPENSSL_ALGO_SHA256);
        return "$in." . $this->b64u($sig);
    }

    // ----------------------------------------------------------------
    // FIRESTORE – CRUD
    // ----------------------------------------------------------------
    private function fsUrl(string $path = ''): string
    {
        return self::FS_BASE . "/projects/{$this->projectId}/databases/(default)/documents{$path}";
    }

    /** Fetch a single document by ID. Returns null if not found. */
    public function getDoc(string $col, string $id): ?array
    {
        $res = $this->http('GET', $this->fsUrl("/$col/$id"));
        if (!$res) return null;
        $d = json_decode($res, true);
        return isset($d['error']) ? null : $this->parseDoc($d);
    }

    /** Create or overwrite a document with a known ID. */
    public function setDoc(string $col, string $id, array $fields): bool
    {
        $res = $this->http('PATCH', $this->fsUrl("/$col/$id"),
            json_encode(['fields' => $this->encFields($fields)]), 'application/json');
        return $res !== null && !isset(json_decode($res, true)['error']);
    }

    /** Add a document with an auto-generated ID. Returns the new document ID. */
    public function addDoc(string $col, array $fields): ?string
    {
        $res = $this->http('POST', $this->fsUrl("/$col"),
            json_encode(['fields' => $this->encFields($fields)]), 'application/json');
        if (!$res) return null;
        $d = json_decode($res, true);
        return isset($d['error']) ? null : basename($d['name'] ?? '');
    }

    /** Partially update specific fields in a document. */
    public function updateDoc(string $col, string $id, array $fields): bool
    {
        $masks = implode('&', array_map(
            fn($k) => 'updateMask.fieldPaths=' . urlencode($k), array_keys($fields)
        ));
        $url = $this->fsUrl("/$col/$id") . '?' . $masks;
        $res = $this->http('PATCH', $url,
            json_encode(['fields' => $this->encFields($fields)]), 'application/json');
        return $res !== null && !isset(json_decode($res, true)['error']);
    }

    /** Delete a document. */
    public function deleteDoc(string $col, string $id): bool
    {
        $res = $this->http('DELETE', $this->fsUrl("/$col/$id"));
        return $res !== null;
    }

    /**
     * Query a collection.
     * $wheres: array of [field, operator, value] triples.
     *   Operators: '==', '!=', '<', '<=', '>', '>=', 'in', 'not-in', 'array-contains'
     * $orderField / $orderDir: e.g. 'created_at', 'DESCENDING'
     */
    public function query(
        string  $col,
        array   $wheres     = [],
        ?string $orderField = null,
        ?string $orderDir   = 'DESCENDING',
        ?int    $limit      = null
    ): array {
        $q = ['from' => [['collectionId' => $col]]];

        if (!empty($wheres)) {
            $filters = array_map(fn($w) => [
                'fieldFilter' => [
                    'field' => ['fieldPath' => $w[0]],
                    'op'    => $this->fsOp($w[1]),
                    'value' => $this->encVal($w[2]),
                ],
            ], $wheres);
            $q['where'] = count($filters) === 1
                ? $filters[0]
                : ['compositeFilter' => ['op' => 'AND', 'filters' => $filters]];
        }

        if ($orderField) {
            $q['orderBy'] = [['field' => ['fieldPath' => $orderField], 'direction' => $orderDir ?? 'DESCENDING']];
        }

        if ($limit !== null) {
            $q['limit'] = $limit;
        }

        $res = $this->http('POST', $this->fsUrl(':runQuery'),
            json_encode(['structuredQuery' => $q]), 'application/json');
        if (!$res) return [];

        $docs = [];
        foreach (json_decode($res, true) as $row) {
            if (isset($row['document'])) {
                $docs[] = $this->parseDoc($row['document']);
            }
        }
        return $docs;
    }

    /** Query and return the count only (avoids loading all document fields for simple counts). */
    public function count(string $col, array $wheres = []): int
    {
        return count($this->query($col, $wheres));
    }

    /** Find documents where a field is within a set of values (IN query). */
    public function queryIn(string $col, string $field, array $values, ?string $orderField = null): array
    {
        if (empty($values)) return [];
        return $this->query($col, [[$field, 'in', $values]], $orderField);
    }

    // ----------------------------------------------------------------
    // FIRESTORE – HELPERS FOR COMMON PATTERNS
    // ----------------------------------------------------------------

    /** Get one document matching the where clause. */
    public function getFirst(string $col, array $wheres): ?array
    {
        $docs = $this->query($col, $wheres, null, null, 1);
        return $docs[0] ?? null;
    }

    /** Check if any document matches the where clauses. */
    public function exists(string $col, array $wheres): bool
    {
        return !empty($this->query($col, $wheres, null, null, 1));
    }

    // ----------------------------------------------------------------
    // FIREBASE STORAGE
    // ----------------------------------------------------------------

    /** Upload raw file data to Firebase Storage. Returns the public URL or null. */
    public function uploadData(string $data, string $path, string $mime = 'application/octet-stream'): ?string
    {
        $url = self::STORE_UP . "/b/{$this->bucket}/o?uploadType=media&name=" . urlencode($path);
        $res = $this->http('POST', $url, $data, $mime);
        if (!$res) return null;
        $d = json_decode($res, true);
        return isset($d['name']) ? $this->storageUrl($path) : null;
    }

    /** Upload a local file to Firebase Storage. Returns the public URL or null. */
    public function uploadFile(string $localPath, string $remotePath, string $mime = 'application/octet-stream'): ?string
    {
        if (!file_exists($localPath)) return null;
        return $this->uploadData(file_get_contents($localPath), $remotePath, $mime);
    }

    /** Delete a file from Firebase Storage. */
    public function deleteFile(string $path): bool
    {
        $res = $this->http('DELETE', self::STORE_API . "/b/{$this->bucket}/o/" . urlencode($path));
        return $res !== null;
    }

    /** Get the public URL for a Firebase Storage file (Firebase REST format). */
    public function storageUrl(string $path): string
    {
        return 'https://firebasestorage.googleapis.com/v0/b/'
            . rawurlencode($this->bucket) . '/o/'
            . rawurlencode($path) . '?alt=media';
    }

    // ----------------------------------------------------------------
    // FIREBASE AUTH – ID TOKEN VERIFICATION
    // ----------------------------------------------------------------

    /**
     * Verify a Firebase Auth ID token (from the JS SDK).
     * Returns the decoded payload or null if invalid.
     */
    public function verifyIdToken(string $idToken): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) return null;

        $header  = json_decode($this->b64ud($parts[0]), true);
        $payload = json_decode($this->b64ud($parts[1]), true);
        $sig     = $this->b64ud($parts[2]);

        if (($payload['exp'] ?? 0) < time()) return null;
        if (($payload['iss'] ?? '') !== "https://securetoken.google.com/{$this->projectId}") return null;
        if (($payload['aud'] ?? '') !== $this->projectId) return null;

        $keys  = $this->googlePublicKeys();
        $keyId = $header['kid'] ?? '';
        if (!isset($keys[$keyId])) return null;

        $sigInput = $parts[0] . '.' . $parts[1];
        $valid    = openssl_verify($sigInput, $sig, openssl_pkey_get_public($keys[$keyId]), OPENSSL_ALGO_SHA256);

        return $valid === 1 ? $payload : null;
    }

    private function googlePublicKeys(): array
    {
        $cacheFile = sys_get_temp_dir() . '/fb_gkeys.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            return json_decode(file_get_contents($cacheFile), true) ?? [];
        }
        $res = $this->http('GET', self::KEYS_URL, null, null, false);
        if ($res) {
            file_put_contents($cacheFile, $res);
            return json_decode($res, true) ?? [];
        }
        return [];
    }

    // ----------------------------------------------------------------
    // ENCODE / DECODE FIRESTORE VALUES
    // ----------------------------------------------------------------
    private function encFields(array $data): array
    {
        return array_map([$this, 'encVal'], $data);
    }

    private function encVal(mixed $v): array
    {
        if ($v === null)      return ['nullValue' => null];
        if (is_bool($v))      return ['booleanValue' => $v];
        if (is_int($v))       return ['integerValue' => (string)$v];
        if (is_float($v))     return ['doubleValue' => $v];
        if (is_array($v)) {
            if (empty($v) || array_is_list($v)) {
                return ['arrayValue' => ['values' => array_map([$this, 'encVal'], $v)]];
            }
            return ['mapValue' => ['fields' => $this->encFields($v)]];
        }
        return ['stringValue' => (string)$v];
    }

    private function parseDoc(array $doc): array
    {
        $out = ['id' => basename($doc['name'] ?? '')];
        foreach ($doc['fields'] ?? [] as $k => $v) {
            $out[$k] = $this->decVal($v);
        }
        return $out;
    }

    private function decVal(array $v): mixed
    {
        if (array_key_exists('stringValue',    $v)) return $v['stringValue'];
        if (array_key_exists('integerValue',   $v)) return (int)$v['integerValue'];
        if (array_key_exists('doubleValue',    $v)) return (float)$v['doubleValue'];
        if (array_key_exists('booleanValue',   $v)) return (bool)$v['booleanValue'];
        if (array_key_exists('nullValue',      $v)) return null;
        if (array_key_exists('timestampValue', $v)) return $v['timestampValue'];
        if (array_key_exists('arrayValue',     $v)) {
            return array_map([$this, 'decVal'], $v['arrayValue']['values'] ?? []);
        }
        if (array_key_exists('mapValue', $v)) {
            $m = [];
            foreach ($v['mapValue']['fields'] ?? [] as $k => $fv) $m[$k] = $this->decVal($fv);
            return $m;
        }
        return null;
    }

    private function fsOp(string $op): string
    {
        return match ($op) {
            '=='             => 'EQUAL',
            '!='             => 'NOT_EQUAL',
            '<'              => 'LESS_THAN',
            '<='             => 'LESS_THAN_OR_EQUAL',
            '>'              => 'GREATER_THAN',
            '>='             => 'GREATER_THAN_OR_EQUAL',
            'in'             => 'IN',
            'not-in'         => 'NOT_IN',
            'array-contains' => 'ARRAY_CONTAINS',
            default          => 'EQUAL',
        };
    }

    // ----------------------------------------------------------------
    // HTTP HELPER
    // ----------------------------------------------------------------
    private function http(
        string  $method,
        string  $url,
        ?string $body = null,
        ?string $ct   = null,
        bool    $auth = true
    ): ?string {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($auth)  $headers[] = 'Authorization: Bearer ' . $this->token();
        if ($ct)    $headers[] = "Content-Type: $ct";

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($res !== false && $code < 500) ? (string)$res : null;
    }

    // ----------------------------------------------------------------
    // BASE64URL
    // ----------------------------------------------------------------
    private function b64u(string $d): string
    {
        return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    }

    private function b64ud(string $d): string
    {
        $pad = strlen($d) % 4;
        if ($pad) $d .= str_repeat('=', 4 - $pad);
        return base64_decode(strtr($d, '-_', '+/'));
    }
}
