<?php
declare(strict_types=1);

/**
 * Task 2 - Shoptet webhook handler
 *
 * Demo flow:
 * Shoptet webhook -> signature validation -> order detail fetch -> send prepared data to Google Sheets bridge
 *
 * Note:
 * This is an illustrative assignment solution. In production, secrets would be stored
 * in environment variables or secure config, not directly in the file.
 */

// =========================
// 1) BASIC CONFIG
// =========================

$signatureKey = 'REPLACE_WITH_SHOPTET_SIGNATURE_KEY';
$apiAccessToken = 'REPLACE_WITH_SHOPTET_API_ACCESS_TOKEN';

/**
 * Placeholder base URL for Shoptet Orders API.
 * The exact API host / token handling depends on the real installation setup.
 */
$ordersApiBaseUrl = 'https://api.myshoptet.com/api/orders/';

/**
 * This URL will point to the Google Apps Script web app in the next step.
 */
$googleSheetsWebhookUrl = 'https://script.google.com/macros/s/REPLACE_WITH_GOOGLE_WEB_APP_ID/exec';

// =========================
// 2) SMALL HELPERS
// =========================

function getHeaderValue(string $headerName): ?string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
    return $_SERVER[$serverKey] ?? null;
}

function jsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function firstValueByPaths(array $source, array $paths, mixed $default = null): mixed
{
    foreach ($paths as $path) {
        $value = $source;
        $found = true;

        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $found = false;
                break;
            }
            $value = $value[$segment];
        }

        if ($found && $value !== null && $value !== '') {
            return $value;
        }
    }

    return $default;
}

function buildItemsSummary(array $items): string
{
    if (empty($items)) {
        return '';
    }

    $parts = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = $item['name'] ?? $item['productName'] ?? 'Položka';
        $quantity = $item['quantity'] ?? $item['amount'] ?? 1;

        $parts[] = sprintf('%s (%sx)', $name, $quantity);
    }

    return implode(', ', $parts);
}

function callJsonApi(
    string $method,
    string $url,
    array $headers = [],
    ?array $body = null
): array {
    $ch = curl_init($url);

    $finalHeaders = array_merge(
        [
            'Accept: application/json',
        ],
        $headers
    );

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $finalHeaders,
        CURLOPT_TIMEOUT => 15,
    ]);

    if ($body !== null) {
        $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        $finalHeaders[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $finalHeaders);
    }

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('cURL error: ' . $curlError);
    }

    $decoded = json_decode($responseBody, true);

    return [
        'status_code' => $statusCode,
        'body' => $decoded,
        'raw_body' => $responseBody,
    ];
}

// =========================
// 3) READ WEBHOOK BODY
// =========================

$rawBody = file_get_contents('php://input');

if ($rawBody === false || $rawBody === '') {
    jsonResponse(400, ['error' => 'Empty webhook body.']);
    exit;
}

$signatureHeader = getHeaderValue('Shoptet-Webhook-Signature');

if (!$signatureHeader) {
    jsonResponse(400, ['error' => 'Missing Shoptet-Webhook-Signature header.']);
    exit;
}

$calculatedSignature = hash_hmac('sha1', $rawBody, $signatureKey);

if (!hash_equals($calculatedSignature, $signatureHeader)) {
    jsonResponse(401, ['error' => 'Invalid webhook signature.']);
    exit;
}

$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    jsonResponse(400, ['error' => 'Invalid JSON payload.']);
    exit;
}

// =========================
// 4) VALIDATE EVENT TYPE
// =========================

$eventType = $payload['event'] ?? '';
$eshopId = $payload['eshopId'] ?? null;
$orderCode = $payload['eventInstance'] ?? null;

if ($eventType !== 'order:create') {
    jsonResponse(200, [
        'status' => 'ignored',
        'reason' => 'Unsupported event type',
        'event' => $eventType,
    ]);
    exit;
}

if (!$orderCode) {
    jsonResponse(422, ['error' => 'Missing eventInstance / order code.']);
    exit;
}

// =========================
// 5) ACKNOWLEDGE QUICKLY
// =========================

/**
 * For webhook best practice:
 * return 200 quickly, then continue processing.
 */
jsonResponse(200, [
    'status' => 'accepted',
    'event' => $eventType,
    'order_code' => $orderCode,
]);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

ignore_user_abort(true);

// =========================
// 6) FETCH ORDER DETAIL
// =========================

try {
    $orderDetailUrl = $ordersApiBaseUrl . rawurlencode((string) $orderCode);

    $orderResponse = callJsonApi(
        'GET',
        $orderDetailUrl,
        [
            'Authorization: Bearer ' . $apiAccessToken,
        ]
    );

    if ($orderResponse['status_code'] < 200 || $orderResponse['status_code'] >= 300) {
        throw new RuntimeException('Failed to fetch order detail. HTTP ' . $orderResponse['status_code']);
    }

    $orderBody = $orderResponse['body'];
    $orderData = $orderBody['data'] ?? $orderBody;

    if (!is_array($orderData)) {
        throw new RuntimeException('Order detail response is not in expected format.');
    }

    // =========================
    // 7) MAP DATA FOR SHEETS
    // =========================

    $orderCreated = firstValueByPaths($orderData, [
        ['created'],
        ['dateCreated'],
        ['creationTime'],
    ], '');

    $customerName = firstValueByPaths($orderData, [
        ['customer', 'name'],
        ['customer', 'fullName'],
        ['billingAddress', 'fullName'],
        ['deliveryAddress', 'fullName'],
    ], '');

    $customerEmail = firstValueByPaths($orderData, [
        ['customer', 'email'],
        ['billingAddress', 'email'],
    ], '');

    $totalToPay = firstValueByPaths($orderData, [
        ['priceToPay'],
        ['summary', 'priceToPay'],
        ['totals', 'priceToPay'],
    ], '');

    $currency = firstValueByPaths($orderData, [
        ['currency', 'code'],
        ['currency'],
    ], '');

    $paymentMethod = firstValueByPaths($orderData, [
        ['payment', 'name'],
        ['paymentMethod', 'name'],
    ], '');

    $shippingMethod = firstValueByPaths($orderData, [
        ['shipping', 'name'],
        ['deliveryMethod', 'name'],
    ], '');

    $orderStatus = firstValueByPaths($orderData, [
        ['status', 'name'],
        ['orderStatus', 'name'],
    ], '');

    $paid = firstValueByPaths($orderData, [
        ['paid'],
        ['isPaid'],
    ], false);

    $items = firstValueByPaths($orderData, [
        ['items'],
        ['orderItems'],
    ], []);

    $itemsSummary = is_array($items) ? buildItemsSummary($items) : '';

    $sheetPayload = [
        'received_at' => date('c'),
        'eshop_id' => $eshopId,
        'order_code' => $orderCode,
        'order_created' => $orderCreated,
        'customer_name' => $customerName,
        'customer_email' => $customerEmail,
        'total_to_pay' => $totalToPay,
        'currency' => $currency,
        'payment_method' => $paymentMethod,
        'shipping_method' => $shippingMethod,
        'order_status' => $orderStatus,
        'paid' => $paid ? 'yes' : 'no',
        'items_summary' => $itemsSummary,
    ];

// =========================
// 8) SEND TO GOOGLE SHEETS BRIDGE
// =========================

$sheetResponse = callJsonApi(
    'POST',
    $googleSheetsWebhookUrl,
    [],
    $sheetPayload
);

if ($sheetResponse['status_code'] < 200 || $sheetResponse['status_code'] >= 300) {
    throw new RuntimeException('Failed to send data to Google Sheets bridge. HTTP ' . $sheetResponse['status_code']);
}

$sheetBody = $sheetResponse['body'];

if (!is_array($sheetBody)) {
    throw new RuntimeException('Unexpected response from Google Sheets bridge.');
}

if (($sheetBody['status'] ?? '') !== 'success') {
    $bridgeMessage = $sheetBody['message'] ?? 'Unknown Google Sheets bridge error.';
    throw new RuntimeException('Google Sheets bridge returned an error: ' . $bridgeMessage);
}

} catch (Throwable $e) {
    error_log('Task 2 webhook handler error: ' . $e->getMessage());
}
