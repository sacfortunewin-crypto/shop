<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

const CHECKOUT_PRODUCT_ID = 'moedor-angulo-portatil-eletrico';
const CHECKOUT_PRODUCT_NAME = 'Moedor de ângulo portátil elétrico sem fio com discos e caixa de armazenamento';
const CHECKOUT_PRODUCT_PRICE_CENTS = 8990;
const CHECKOUT_EXPRESS_SHIPPING_CENTS = 1990;

function pagou_starts_with(string $value, string $prefix): bool
{
    return substr($value, 0, strlen($prefix)) === $prefix;
}

function pagou_ends_with(string $value, string $suffix): bool
{
    if ($suffix === '') {
        return true;
    }

    return substr($value, -strlen($suffix)) === $suffix;
}

function pagou_load_env(): array
{
    static $env = null;

    if ($env !== null) {
        return $env;
    }

    $env = [];
    $path = __DIR__ . '/.env';

    if (!is_readable($path)) {
        return $env;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || pagou_starts_with($line, '#') || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (
            (pagou_starts_with($value, '"') && pagou_ends_with($value, '"')) ||
            (pagou_starts_with($value, "'") && pagou_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '') {
            $env[$key] = $value;
        }
    }

    return $env;
}

function pagou_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    if (is_string($value) && $value !== '') {
        return $value;
    }

    $env = pagou_load_env();
    return isset($env[$key]) && $env[$key] !== '' ? $env[$key] : $default;
}

function pagou_environment(): string
{
    return strtolower(pagou_env('PAGOU_ENVIRONMENT', 'production')) === 'sandbox' ? 'sandbox' : 'production';
}

function pagou_base_url(): string
{
    $configured = rtrim(pagou_env('PAGOU_BASE_URL'), '/');

    if ($configured !== '') {
        return $configured;
    }

    return pagou_environment() === 'sandbox'
        ? 'https://api-sandbox.pagou.ai'
        : 'https://api.pagou.ai';
}

function pagou_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pagou_require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        pagou_json(['message' => 'Método não permitido.'], 405);
    }
}

function pagou_read_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        pagou_json(['message' => 'JSON inválido.'], 400);
    }

    return $data;
}

function pagou_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?: '';
}

function pagou_client_ip(): ?string
{
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

    foreach ($headers as $header) {
        $value = $_SERVER[$header] ?? '';
        $ip = trim(explode(',', $value)[0] ?? '');

        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return null;
}

function pagou_default_webhook_url(): string
{
    $configured = pagou_env('PAGOU_NOTIFY_URL');

    if ($configured !== '') {
        return $configured;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';

    if ($host === '' || preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/', $host)) {
        return '';
    }

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/checkout/api/transaction.php');
    $dir = rtrim(dirname($scriptName), '/');
    $url = 'https://' . $host . $dir . '/webhook.php';
    $secret = pagou_env('PAGOU_WEBHOOK_SECRET', pagou_env('UTMIFY_WEBHOOK_SECRET'));

    if ($secret !== '') {
        $url .= '?secret=' . rawurlencode($secret);
    }

    return $url;
}

function pagou_selected_amount_cents(array $input): int
{
    $requested = isset($input['amountCents']) ? (int) $input['amountCents'] : CHECKOUT_PRODUCT_PRICE_CENTS;
    $expressTotal = CHECKOUT_PRODUCT_PRICE_CENTS + CHECKOUT_EXPRESS_SHIPPING_CENTS;

    return $requested === $expressTotal ? $expressTotal : CHECKOUT_PRODUCT_PRICE_CENTS;
}

function pagou_validate_checkout_payload(array $input): void
{
    $customer = $input['customer'] ?? null;
    $address = $input['address'] ?? null;

    if (!is_array($customer) || !is_array($address)) {
        pagou_json(['message' => 'Dados do checkout incompletos.'], 422);
    }

    $requiredCustomer = ['name', 'email', 'phone', 'cpf'];
    foreach ($requiredCustomer as $field) {
        if (trim((string) ($customer[$field] ?? '')) === '') {
            pagou_json(['message' => 'Preencha seus dados pessoais.'], 422);
        }
    }

    if (!filter_var((string) $customer['email'], FILTER_VALIDATE_EMAIL)) {
        pagou_json(['message' => 'E-mail inválido.'], 422);
    }

    if (strlen(pagou_digits((string) $customer['cpf'])) !== 11) {
        pagou_json(['message' => 'CPF inválido.'], 422);
    }

    $requiredAddress = ['cep', 'street', 'number', 'district', 'city', 'state'];
    foreach ($requiredAddress as $field) {
        if (trim((string) ($address[$field] ?? '')) === '') {
            pagou_json(['message' => 'Preencha o endereço de entrega.'], 422);
        }
    }
}

function pagou_build_transaction_payload(array $input): array
{
    pagou_validate_checkout_payload($input);

    $method = (string) ($input['method'] ?? 'pix');
    if (!in_array($method, ['pix', 'credit_card'], true)) {
        pagou_json(['message' => 'Forma de pagamento inválida.'], 422);
    }

    $customer = $input['customer'];
    $address = $input['address'];
    $amountCents = pagou_selected_amount_cents($input);
    $shippingCents = max(0, $amountCents - CHECKOUT_PRODUCT_PRICE_CENTS);
    $cpf = pagou_digits((string) $customer['cpf']);
    $phone = pagou_digits((string) $customer['phone']);

    $payload = [
        'external_ref' => 'checkout_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)),
        'amount' => $amountCents,
        'currency' => 'BRL',
        'method' => $method,
        'buyer' => [
            'name' => trim((string) $customer['name']),
            'email' => trim((string) $customer['email']),
            'phone' => $phone,
            'document' => [
                'type' => 'CPF',
                'number' => $cpf,
            ],
            'address' => [
                'street' => trim((string) $address['street']),
                'number' => trim((string) $address['number']),
                'complement' => trim((string) ($address['complement'] ?? '')) ?: null,
                'neighborhood' => trim((string) $address['district']),
                'city' => trim((string) $address['city']),
                'state' => strtoupper(trim((string) $address['state'])),
                'zipCode' => pagou_digits((string) $address['cep']),
                'country' => 'BR',
            ],
        ],
        'products' => [[
            'name' => CHECKOUT_PRODUCT_NAME,
            'price' => CHECKOUT_PRODUCT_PRICE_CENTS,
            'quantity' => 1,
            'tangible' => true,
            'sku' => CHECKOUT_PRODUCT_ID,
        ]],
        'metadata' => json_encode([
            'productId' => CHECKOUT_PRODUCT_ID,
            'shippingCents' => $shippingCents,
            'tracking' => is_array($input['tracking'] ?? null) ? $input['tracking'] : [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'traceable' => true,
    ];

    $ip = pagou_client_ip();
    if ($ip !== null) {
        $payload['ip_address'] = $ip;
    }

    $notifyUrl = pagou_default_webhook_url();
    if ($notifyUrl !== '' && pagou_starts_with($notifyUrl, 'https://')) {
        $payload['notify_url'] = $notifyUrl;
    }

    if ($method === 'credit_card') {
        $token = trim((string) ($input['cardToken'] ?? $input['token'] ?? ''));
        $installments = max(1, min(3, (int) ($input['installments'] ?? 1)));

        if ($token === '') {
            pagou_json(['message' => 'Token do cartão não informado.'], 422);
        }

        $payload['token'] = $token;
        $payload['installments'] = $installments;
    }

    return $payload;
}

function pagou_api_request(string $method, string $path, ?array $payload = null): array
{
    $secret = pagou_env('PAGOU_SECRET_KEY');

    if ($secret === '') {
        pagou_json(['message' => 'Chave secreta do Pagou não configurada.'], 500);
    }

    $url = pagou_base_url() . $path;
    $headers = [
        'Authorization: Bearer ' . $secret,
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($raw === false) {
            pagou_json(['message' => $error !== '' ? $error : 'Falha de comunicação com a Pagou.'], 502);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\n", $headers),
                'content' => $body ?? '',
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $raw = file_get_contents($url, false, $context);
        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                $status = (int) $matches[1];
                break;
            }
        }

        if ($raw === false) {
            pagou_json(['message' => 'Falha de comunicação com a Pagou.'], 502);
        }
    }

    $decoded = json_decode((string) $raw, true);
    $decoded = is_array($decoded) ? $decoded : ['raw' => $raw];

    return [
        'status' => $status,
        'body' => $decoded,
    ];
}

function pagou_error_message(array $body): string
{
    return (string) (
        $body['message']
        ?? $body['detail']
        ?? $body['title']
        ?? $body['error']
        ?? 'Falha ao processar pagamento.'
    );
}

function pagou_storage_dir(string $name): string
{
    $dir = __DIR__ . '/storage/' . $name;

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

function pagou_store_order(array $transaction, array $requestPayload, array $checkoutInput): void
{
    $transactionId = (string) ($transaction['id'] ?? '');
    $externalRef = (string) ($transaction['external_ref'] ?? $requestPayload['external_ref'] ?? '');

    if ($transactionId === '' && $externalRef === '') {
        return;
    }

    $customer = is_array($checkoutInput['customer'] ?? null) ? $checkoutInput['customer'] : [];
    $tracking = is_array($checkoutInput['tracking'] ?? null) ? $checkoutInput['tracking'] : [];

    $order = [
        'transactionId' => $transactionId,
        'externalRef' => $externalRef,
        'method' => $transaction['method'] ?? $requestPayload['method'] ?? 'pix',
        'amountCents' => (int) ($transaction['amount'] ?? $requestPayload['amount'] ?? CHECKOUT_PRODUCT_PRICE_CENTS),
        'createdAt' => $transaction['created_at'] ?? gmdate('c'),
        'customer' => [
            'name' => trim((string) ($customer['name'] ?? '')),
            'email' => trim((string) ($customer['email'] ?? '')),
            'phone' => pagou_digits((string) ($customer['phone'] ?? '')),
            'document' => pagou_digits((string) ($customer['cpf'] ?? '')),
            'ip' => pagou_client_ip(),
        ],
        'tracking' => [
            'src' => $tracking['src'] ?? null,
            'sck' => $tracking['sck'] ?? null,
            'utm_source' => $tracking['utm_source'] ?? null,
            'utm_campaign' => $tracking['utm_campaign'] ?? null,
            'utm_medium' => $tracking['utm_medium'] ?? null,
            'utm_content' => $tracking['utm_content'] ?? null,
            'utm_term' => $tracking['utm_term'] ?? null,
        ],
    ];

    $dir = pagou_storage_dir('orders');
    $json = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    foreach (array_filter([$transactionId, $externalRef]) as $key) {
        file_put_contents($dir . '/' . sha1((string) $key) . '.json', $json);
    }
}

function pagou_load_order(?string $transactionId, ?string $externalRef): ?array
{
    $dir = pagou_storage_dir('orders');

    foreach (array_filter([$transactionId, $externalRef]) as $key) {
        $path = $dir . '/' . sha1((string) $key) . '.json';

        if (is_readable($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            return is_array($data) ? $data : null;
        }
    }

    return null;
}

function pagou_event_seen(string $eventId): bool
{
    if ($eventId === '') {
        return false;
    }

    $dir = pagou_storage_dir('events');
    $path = $dir . '/' . sha1($eventId) . '.json';

    return is_readable($path);
}

function pagou_mark_event_seen(string $eventId): void
{
    if ($eventId === '') {
        return;
    }

    $dir = pagou_storage_dir('events');
    $path = $dir . '/' . sha1($eventId) . '.json';

    file_put_contents($path, json_encode(['id' => $eventId, 'seenAt' => gmdate('c')]));
}

function pagou_format_utmify_date(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return gmdate('Y-m-d H:i:s');
    }

    return gmdate('Y-m-d H:i:s', $timestamp);
}

function pagou_utmify_status(string $pagouStatus, string $eventType): string
{
    if ($eventType === 'transaction.paid' || in_array($pagouStatus, ['paid', 'captured', 'authorized'], true)) {
        return 'paid';
    }

    if (in_array($pagouStatus, ['refunded', 'partially_refunded'], true)) {
        return 'refunded';
    }

    if ($pagouStatus === 'chargedback') {
        return 'chargedback';
    }

    if (in_array($pagouStatus, ['refused', 'canceled', 'cancelled', 'expired'], true)) {
        return 'refused';
    }

    return 'waiting_payment';
}

function pagou_post_json(string $url, array $payload, array $headers): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'Accept: application/json';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => $body,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($raw === false) {
            return ['status' => 0, 'body' => ['message' => $error]];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\n", $headers),
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $raw = file_get_contents($url, false, $context);
        $status = 0;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                $status = (int) $matches[1];
                break;
            }
        }

        if ($raw === false) {
            return ['status' => 0, 'body' => ['message' => 'Falha de comunicação.']];
        }
    }

    $decoded = json_decode((string) $raw, true);
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : ['raw' => $raw]];
}

function pagou_notify_utmify(array $order, array $transaction): array
{
    $token = pagou_env('UTMIFY_API_TOKEN');

    if ($token === '') {
        return ['sent' => false, 'status' => 0, 'message' => 'UTMify não configurado.'];
    }

    $pagouStatus = (string) ($transaction['status'] ?? 'pending');
    $eventType = (string) ($transaction['event_type'] ?? '');
    $status = pagou_utmify_status($pagouStatus, $eventType);
    $createdAt = pagou_format_utmify_date((string) ($order['createdAt'] ?? $transaction['created_at'] ?? gmdate('c')));
    $approvedDate = $status === 'paid'
        ? pagou_format_utmify_date((string) ($transaction['paid_at'] ?? $transaction['updated_at'] ?? gmdate('c')))
        : null;
    $refundedAt = $status === 'refunded'
        ? pagou_format_utmify_date((string) ($transaction['updated_at'] ?? gmdate('c')))
        : null;
    $amountCents = (int) ($transaction['amount'] ?? $order['amountCents'] ?? CHECKOUT_PRODUCT_PRICE_CENTS);
    $feeCents = max(0, (int) ($transaction['fee'] ?? 0));
    $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
    $tracking = is_array($order['tracking'] ?? null) ? $order['tracking'] : [];

    $payload = [
        'orderId' => (string) ($order['transactionId'] ?? $transaction['id'] ?? $order['externalRef'] ?? ''),
        'platform' => 'ShopeeCheckout',
        'paymentMethod' => (($transaction['method'] ?? $order['method'] ?? 'pix') === 'credit_card') ? 'credit_card' : 'pix',
        'status' => $status,
        'createdAt' => $createdAt,
        'approvedDate' => $approvedDate,
        'refundedAt' => $refundedAt,
        'customer' => [
            'name' => (string) ($customer['name'] ?? ''),
            'email' => (string) ($customer['email'] ?? ''),
            'phone' => $customer['phone'] ?? null,
            'document' => $customer['document'] ?? null,
            'country' => 'BR',
            'ip' => $customer['ip'] ?? pagou_client_ip(),
        ],
        'products' => [[
            'id' => CHECKOUT_PRODUCT_ID,
            'name' => CHECKOUT_PRODUCT_NAME,
            'planId' => null,
            'planName' => null,
            'quantity' => 1,
            'priceInCents' => CHECKOUT_PRODUCT_PRICE_CENTS,
        ]],
        'trackingParameters' => [
            'src' => $tracking['src'] ?? null,
            'sck' => $tracking['sck'] ?? null,
            'utm_source' => $tracking['utm_source'] ?? null,
            'utm_campaign' => $tracking['utm_campaign'] ?? null,
            'utm_medium' => $tracking['utm_medium'] ?? null,
            'utm_content' => $tracking['utm_content'] ?? null,
            'utm_term' => $tracking['utm_term'] ?? null,
        ],
        'commission' => [
            'totalPriceInCents' => $amountCents,
            'gatewayFeeInCents' => $feeCents,
            'userCommissionInCents' => max(0, $amountCents - $feeCents),
            'currency' => $transaction['currency'] ?? 'BRL',
        ],
        'isTest' => pagou_environment() === 'sandbox',
    ];

    $response = pagou_post_json('https://api.utmify.com.br/api-credentials/orders', $payload, [
        'x-api-token: ' . $token,
    ]);

    return [
        'sent' => $response['status'] >= 200 && $response['status'] < 300,
        'status' => $response['status'],
        'body' => $response['body'],
    ];
}
