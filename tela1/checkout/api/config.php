<?php
declare(strict_types=1);

require_once __DIR__ . '/pagou.php';

pagou_require_method('GET');

pagou_json([
    'publicKey' => pagou_env('PAGOU_PUBLIC_KEY'),
    'environment' => pagou_environment(),
    'product' => [
        'id' => CHECKOUT_PRODUCT_ID,
        'name' => CHECKOUT_PRODUCT_NAME,
        'priceCents' => CHECKOUT_PRODUCT_PRICE_CENTS,
    ],
]);
