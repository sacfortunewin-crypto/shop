<?php
declare(strict_types=1);

require_once __DIR__ . '/pagou.php';

pagou_require_method('POST');

$secret = pagou_env('PAGOU_WEBHOOK_SECRET', pagou_env('UTMIFY_WEBHOOK_SECRET'));
if ($secret !== '') {
    $receivedSecret = (string) (
        $_GET['secret']
        ?? $_SERVER['HTTP_X_WEBHOOK_SECRET']
        ?? $_SERVER['HTTP_X_PAGOU_WEBHOOK_SECRET']
        ?? ''
    );

    if (!hash_equals($secret, $receivedSecret)) {
        pagou_json(['received' => false, 'message' => 'Webhook não autorizado.'], 401);
    }
}

$event = pagou_read_json();
$eventId = (string) ($event['id'] ?? '');

if ($eventId === '') {
    pagou_json(['error' => 'missing_event_id'], 400);
}

if (pagou_event_seen($eventId)) {
    pagou_json(['received' => true, 'duplicate' => true]);
}

$transaction = is_array($event['data'] ?? null) ? $event['data'] : [];
$eventType = (string) ($transaction['event_type'] ?? '');
$transactionId = (string) ($transaction['id'] ?? '');
$externalRef = (string) ($transaction['correlation_id'] ?? $transaction['external_ref'] ?? '');
$transaction['event_type'] = $eventType;

$order = pagou_load_order($transactionId, $externalRef);

if ($order === null) {
    $order = [
        'transactionId' => $transactionId,
        'externalRef' => $externalRef,
        'method' => $transaction['method'] ?? 'pix',
        'amountCents' => (int) ($transaction['amount'] ?? CHECKOUT_PRODUCT_PRICE_CENTS),
        'createdAt' => gmdate('c'),
        'customer' => [],
        'tracking' => [],
    ];
}

$utmify = null;
if (in_array($eventType, [
    'transaction.paid',
    'transaction.refunded',
    'transaction.partially_refunded',
    'transaction.chargedback',
    'transaction.cancelled',
], true)) {
    $utmify = pagou_notify_utmify($order, $transaction);

    if (!($utmify['sent'] ?? false)) {
        pagou_json([
            'received' => false,
            'eventId' => $eventId,
            'utmify' => $utmify,
        ], 502);
    }
}

pagou_mark_event_seen($eventId);

pagou_json([
    'received' => true,
    'eventId' => $eventId,
    'utmify' => $utmify,
]);
