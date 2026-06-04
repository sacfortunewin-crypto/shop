<?php
declare(strict_types=1);

require_once __DIR__ . '/pagou.php';

pagou_require_method('POST');

$input = pagou_read_json();
$payload = pagou_build_transaction_payload($input);
$response = pagou_api_request('POST', '/v2/transactions', $payload);
$body = $response['body'];

if ($response['status'] < 200 || $response['status'] >= 300) {
    pagou_json([
        'message' => pagou_error_message($body),
        'pagouStatus' => $response['status'],
        'requestId' => $body['requestId'] ?? null,
    ], $response['status'] > 0 ? $response['status'] : 502);
}

$data = is_array($body['data'] ?? null) ? $body['data'] : $body;
$transactionId = $data['id'] ?? $body['transactionId'] ?? null;

pagou_store_order($data, $payload, $input);

if (($payload['method'] ?? '') === 'pix') {
    $pix = is_array($data['pix'] ?? null) ? $data['pix'] : [];

    pagou_json([
        'transactionId' => $transactionId,
        'status' => $data['status'] ?? null,
        'method' => $data['method'] ?? 'pix',
        'amount' => $data['amount'] ?? $payload['amount'],
        'currency' => $data['currency'] ?? 'BRL',
        'requestId' => $body['requestId'] ?? null,
        'pix' => [
            'qrCode' => $pix['qr_code'] ?? $body['pixQrCode'] ?? null,
            'qrCodeImage' => $pix['qr_code_image'] ?? $body['pixQrCodeImage'] ?? null,
            'expirationDate' => $pix['expiration_date'] ?? null,
            'receiptUrl' => $pix['receipt_url'] ?? null,
        ],
    ]);
}

$data['transactionId'] = $transactionId;
$data['requestId'] = $body['requestId'] ?? null;
$data['transaction'] = $data;

pagou_json($data);
