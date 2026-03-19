<?php

// Include the main gateway file for sync functions
require_once __DIR__ . '/../razorpay.php';

/**
 * WHMCS Razorpay Compatibility Layer
 * PHP 5.6+ safe with WHMCS 6/7/8 support
 */
if (!class_exists('RzpWhmcsCompat')) {
class RzpWhmcsCompat
{
    private static $whmcsVersion = null;
    private static $phpVersion = null;
    
    /**
     * Detect WHMCS capabilities via feature detection
     */
    public static function hasMetaDataFn()
    {
        return function_exists('razorpay_MetaData');
    }
    
    /**
     * Check if NoLocalCreditCardInput is supported
     */
    public static function supportsNoLocalCC()
    {
        return self::hasMetaDataFn();
    }
    
    /**
     * Unified payment recording with fallbacks
     */
    public static function addPayment($invoiceId, $transId, $amount, $fees = 0, $gateway = 'razorpay', $date = null)
    {
        // Try modern localAPI first (WHMCS 7+)
        if (function_exists('localAPI')) {
            $result = localAPI('AddInvoicePayment', array(
                'invoiceid' => $invoiceId,
                'transid' => $transId,
                'gateway' => $gateway,
                'amount' => $amount,
                'fees' => $fees,
                'date' => $date ?: date('Y-m-d H:i:s')
            ));
            
            if ($result['result'] === 'success') {
                return true;
            }
        }
        
        // Fallback to legacy addInvoicePayment (WHMCS 6)
        if (function_exists('addInvoicePayment')) {
            addInvoicePayment($invoiceId, $transId, $amount, $fees, $gateway);
            return true;
        }
        
        return false;
    }
    
    /**
     * Constant-time string comparison
     */
    public static function constantTimeEquals($a, $b)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($a, $b);
        }
        
        // PHP 5.6 polyfill
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        
        $result = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $result |= ord($a[$i]) ^ ord($b[$i]);
        }
        
        return $result === 0;
    }
    
    /**
     * Convert Razorpay Unix timestamp to WHMCS datetime using WHMCS timezone
     */
    public static function tzConvertFromUnix($timestamp)
    {
        // Get WHMCS timezone setting
        $whmcsTimezone = 'Asia/Calcutta'; // Default fallback
        if (class_exists('Illuminate\Database\Capsule\Manager')) {
            try {
                $result = self::safeQuery('tblconfiguration', 'value', array('setting' => 'cronTimeZone'));
                if ($result && !empty($result->value)) {
                    $whmcsTimezone = $result->value;
                }
            } catch (Exception $e) {
                // Fallback to default if database query fails
            }
        }
        
        // Create DateTime object with WHMCS timezone
        $dateTime = new DateTime();
        $dateTime->setTimestamp($timestamp);
        $dateTime->setTimezone(new DateTimeZone($whmcsTimezone));
        
        return $dateTime->format('Y-m-d H:i:s');
    }
    
    /**
     * Safe query method with fallbacks
     */
    public static function safeQuery($table, $fields, $where)
    {
        // Try Capsule first (WHMCS 6+)
        if (class_exists('Illuminate\Database\Capsule\Manager')) {
            $query = \Illuminate\Database\Capsule\Manager::table($table)->select($fields);
            foreach ($where as $key => $value) {
                $query->where($key, $value);
            }
            return $query->first();
        }
        
        // Fallback to legacy select_query
        if (function_exists('select_query')) {
            $result = select_query($table, $fields, $where);
            if (function_exists('mysql_fetch_assoc')) {
                return mysql_fetch_assoc($result);
            }
        }
        
        return false;
    }
}
}

// Polyfills for PHP 5.6
if (!function_exists('hash_equals')) {
    function hash_equals($a, $b)
    {
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        
        $result = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $result |= ord($a[$i]) ^ ord($b[$i]);
        }
        
        return $result === 0;
    }
}

if (!function_exists('random_bytes')) {
    function random_bytes($length)
    {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes($length, $strong);
            if ($strong === true) {
                return $bytes;
            }
        }
        
        // Fallback to mt_rand (less secure but functional)
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr(mt_rand(0, 255));
        }
        
        return $bytes;
    }
}

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/lib/razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

/**
 * Event constants
 */
const PAYMENT_CAPTURED = 'payment.captured';
const ORDER_PAID  = 'order.paid';
const REFUND_CREATED = 'refund.created';
const REFUND_PROCESSED = 'refund.processed';

// Detect module name from filename.
$gatewayModuleName = 'razorpay';

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Security: Only allow POST requests
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

// Security: Basic body size limit to reduce DoS risk
$post = file_get_contents('php://input');
if (!is_string($post) || $post === '') {
    http_response_code(400);
    exit('Bad Request');
}
if (strlen($post) > 1024 * 1024) { // 1MB
    http_response_code(413);
    exit('Payload Too Large');
}

// Security: Simple rate limiting (best-effort; only when APCu is enabled)
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitKey = 'razorpay_webhook_' . md5($clientIp);
if (function_exists('apcu_fetch') && function_exists('apcu_store')) {
    $rateLimitCount = apcu_fetch($rateLimitKey) ?: 0;
    if ($rateLimitCount >= 60) { // 60 requests/minute/IP
        logActivity('Razorpay Webhook Security: Rate limit exceeded for IP - ' . $clientIp);
        http_response_code(429);
        exit('Too Many Requests');
    }
    apcu_store($rateLimitKey, $rateLimitCount + 1, 60);
}

// Validate gateway configuration early (avoid notices and undefined index usage)
if (empty($gatewayParams['keyId']) || empty($gatewayParams['keySecret'])) {
    logActivity('Razorpay Webhook Config Error: Missing API keys');
    http_response_code(500);
    exit('Server Misconfigured');
}

$api = new Api($gatewayParams['keyId'], $gatewayParams['keySecret']);

/**
 * Process a Razorpay Webhook. We exit in the following cases:
 * - Successful processed
 * - Exception while fetching the payment
 *
 * It passes on the webhook in the following cases:
 * - invoice_id set in payment.authorized
 * - order refunded
 * - Invalid JSON
 * - Signature mismatch
 * - Secret isn't setup
 * - Event not recognized
 *
 * @return void|WP_Error
 * @throws Exception
 */

$data = json_decode($post, true);

if (json_last_error() !== 0)
{
    http_response_code(400);
    exit('Invalid JSON');
}

$enabled = $gatewayParams['enableWebhook'];

// Log minimal webhook metadata (avoid logging full payload / PII)
logTransaction($gatewayParams['name'], [
    'webhook_enabled' => $enabled,
    'event' => $data['event'] ?? 'unknown',
    'has_signature' => isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']),
], 'Webhook Received');

// Validate webhook structure early (avoid notices and junk processing)
if (!validateWebhookData($data)) {
    http_response_code(400);
    exit('Bad Request');
}

if ($enabled === 'on' and
    (empty($data['event']) === false))
{
    if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) === true)
    {
        $razorpayWebhookSecret = $gatewayParams['webhookSecret'];

        //
        // If the webhook secret isn't set on wordpress, return
        //
        if (empty($razorpayWebhookSecret) === true)
        {
            logActivity('Razorpay Webhook Config Error: Missing webhook secret');
            http_response_code(500);
            exit('Server Misconfigured');
        }

        try
        {
            $expectedSignature = hash_hmac('sha256', $post, $razorpayWebhookSecret);
            $actualSignature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'];
            if (!RzpWhmcsCompat::constantTimeEquals($expectedSignature, $actualSignature)) {
                throw new Errors\SignatureVerificationError('Invalid signature');
            }
        }
        catch (Errors\SignatureVerificationError $e)
        {
            logTransaction($gatewayParams["name"], array(
                'message' => $e->getMessage(),
                'event' => $data['event'] ?? 'unknown',
            ), "Unsuccessful-" . $e->getMessage());

            http_response_code(401);
            exit('Unauthorized');
        }

        switch ($data['event'])
        {
            case PAYMENT_CAPTURED:
                return paymentCaptured($data, $gatewayParams);
            case ORDER_PAID:
                return orderPaid($data, $gatewayParams);
            case REFUND_CREATED:
            case REFUND_PROCESSED:
                return refundProcessed($data, $gatewayParams);
            default:
                // Unknown event: acknowledge (avoid retries) but do nothing.
                http_response_code(200);
                exit('OK');
        }
    }
}
else
{
    // CRITICAL: Log when webhook is disabled
    logTransaction($gatewayParams['name'], [
        'webhook_enabled' => $enabled,
        'event' => $data['event'] ?? 'unknown',
        'message' => 'Webhook processing disabled - payments will not be recorded!'
    ], 'Webhook Disabled Warning');

    // Acknowledge to avoid repeated retries when intentionally disabled.
    http_response_code(200);
    exit('OK');
}


/**
 * Order Paid webhook
 *
 * @param array $data
 */
function orderPaid(array $data, $gatewayParams)
{
    // We don't process subscription/invoice payments here
    if (isset($data['payload']['payment']['entity']['invoice_id']) === true)
    {
        logTransaction($gatewayParams['name'], "returning order.paid webhook", "Invoice ID exists");
        return;
    }

    //
    // Order entity should be sent as part of the webhook payload
    //
    $orderId = $data['payload']['order']['entity']['notes']['whmcs_order_id'];
    $razorpayPaymentId = $data['payload']['payment']['entity']['id'];
    $razorpayOrderId = $data['payload']['order']['entity']['id'];

    // Idempotency: if WHMCS already recorded this transaction, acknowledge and exit.
    try {
        $existingTxn = Capsule::table('tblaccounts')
            ->where('transid', $razorpayPaymentId)
            ->first();
        if ($existingTxn) {
            logTransaction($gatewayParams['name'], array(
                'razorpay_payment_id' => $razorpayPaymentId,
                'message' => 'Duplicate webhook delivery (transid already exists)'
            ), 'Webhook Idempotent Skip');
            http_response_code(200);
            exit('OK');
        }
    } catch (Exception $e) {
        // If idempotency check fails, continue (do not block payment recording)
        logActivity('Razorpay Webhook Idempotency Check Error: ' . $e->getMessage());
    }

    // Validate Callback Invoice ID.
    $merchant_order_id = checkCbInvoiceID($orderId, $gatewayParams['name']);
    
    // Check Callback Transaction ID.
    checkCbTransID($razorpayPaymentId);

    // MAJOR FIX: Replace deprecated mysql_fetch_assoc with Capsule
    $orderTableId = Capsule::table('tblorders')
        ->select('id')
        ->where('invoiceid', $orderId)
        ->first();

    // CRITICAL FIX: Handle null result
    if (!$orderTableId) {
        logTransaction($gatewayParams['name'], "Order not found for invoice ID: $orderId", "Order Not Found");
        return;
    }

    $command = 'GetOrders';

    $postData = array(
        'id' => $orderTableId->id,
    );

    $order = localAPI($command, $postData);

    // If order detail not found then ignore.
    // If it is already marked as paid or failed ignore the event
    // MAJOR FIX: Enhanced idempotency check
    if($order['totalresults'] == 0 or $order['orders']['order'][0]['paymentstatus'] === 'Paid')
    {
        logTransaction($gatewayParams['name'], "order detail not found or already paid or failed", "INFO");
        return;
    }

    // Additional idempotency check using transaction ID

    $success = false;
    $error = "";
    $error = 'The payment has failed.';

    $expectedAmount = getOrderAmountAsInteger($order);
    $actualAmount = (int) ($data['payload']['payment']['entity']['amount'] ?? 0);
    $actualPaymentAmountRuplesFromPayload = $actualAmount / 100;

    // Fee-bearer surcharge flows legitimately make the Razorpay payment amount higher than the WHMCS order amount.
    // Allow that direction, but still fail on major underpayment.
    $tolerance = max(1, (int) round($expectedAmount * 0.005)); // 0.5% tolerance in paise
    if ($actualAmount + $tolerance >= $expectedAmount) {
        $success = true;
    } else {
        $error = 'WHMCS_ERROR: Payment to Razorpay Failed. Amount underpayment.';
        logTransaction(
            $gatewayParams['name'],
            "Amount underpayment: Expected $expectedAmount, Got $actualAmount, Tolerance $tolerance",
            "Amount Mismatch"
        );
    }

    $log = [
        'merchant_order_id'   => $orderId,
        'razorpay_payment_id' => $razorpayPaymentId,
        'razorpay_order_id' => $razorpayOrderId,
        'expected_amount' => $expectedAmount,
        'actual_amount' => $actualAmount,
        'amount_diff' => ($actualAmount - $expectedAmount),
        'payment_created_at' => $data['payload']['payment']['entity']['created_at'],
        'webhook_received_at' => time(),
        'webhook' => true
    ];

    if ($success === true)
    {
        # Successful
        # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
        $razorpayCreatedAt = $data['payload']['payment']['entity']['created_at'];
        $orderAmount = $order['orders']['order'][0]['amount'];
        
        // Handle gateway fees based on configuration
        $feeMode = $gatewayParams['feeMode'] ?? 'merchant_absorbs';
        $paymentAmount = $orderAmount; // base by default (never include surcharge)
        $gatewayFee = 0;
        $razorpayFeeRuples = 0;

        // Fetch Razorpay payment fee so we don't treat surcharge-inflated amount as “gateway fee”.
        // (Razorpay’s UI/records can separate surcharge vs Razorpay fee.)
        try {
            $api = new \Razorpay\Api\Api($gatewayParams['keyId'], $gatewayParams['keySecret']);
            $paymentDetails = $api->payment->fetch($razorpayPaymentId);

            $actualPaymentAmountRuples = $paymentDetails['amount'] / 100;
            if (isset($paymentDetails['fee']) && $paymentDetails['fee'] !== null) {
                $razorpayFeeRuples = ((float) $paymentDetails['fee']) / 100;
            } else {
                // Fallback: if fee field is missing, treat difference as fee.
                $razorpayFeeRuples = max(0, $actualPaymentAmountRuples - $orderAmount);
            }

            if ($feeMode === 'client_pays') {
                // Client pays everything: apply only the base order amount as payment, keep WHMCS fees as 0.
                $paymentAmount = $orderAmount;
                $gatewayFee = 0;
            } else {
                // Merchant absorbs: record base order amount and keep Razorpay fee in `fees`.
                $paymentAmount = $orderAmount;
                $gatewayFee = $razorpayFeeRuples;
            }
        } catch (Exception $e) {
            // Fallback: always apply only the base order amount, regardless of fee mode.
            $paymentAmount = $orderAmount;
            $gatewayFee = 0;
            $razorpayFeeRuples = 0;
        }

        $log['fee_mode_config'] = $feeMode;
        $log['razorpay_fee_rupees'] = $razorpayFeeRuples;
        $log['amount_recorded_rupees'] = $paymentAmount;
        $log['fees_recorded_rupees'] = $gatewayFee;
        
        // Convert Razorpay Unix timestamp to WHMCS datetime format (IST timezone)
        $paymentDate = RzpWhmcsCompat::tzConvertFromUnix($razorpayCreatedAt);
        
        // Use compatibility layer for payment recording
        $feeCreditBehavior = $gatewayParams['feeCreditBehavior'] ?? 'disabled';
        $success = RzpWhmcsCompat::addPayment(
            $orderId,
            $razorpayPaymentId,
            $paymentAmount,
            $gatewayFee,
            'razorpay',
            $paymentDate,
            $feeCreditBehavior
        );
        
        logTransaction($gatewayParams["name"], $log, "Successful"); # Save to Gateway Log: name, data array, status
    }
    else
    {
        # Unsuccessful
        # Save to Gateway Log: name, data array, status
        logTransaction($gatewayParams["name"], $log, "Unsuccessful-".$error . ". Please check razorpay dashboard for Payment id: ".$razorpayPaymentId);
    }

    // Graceful exit since payment is now processed.
    exit;
}

/**
 * Returns the order amount, rounded as integer
 * @param WHMCS_Order $order WHMCS Order instance
 * @return int Order Amount
 */
function getOrderAmountAsInteger($order)
{
    return (int) round($order['orders']['order'][0]['amount'] * 100);
}

/**
 * Payment Captured webhook - Enhanced payment processing
 * @param array $data
 * @param array $gatewayParams
 */
function paymentCaptured(array $data, $gatewayParams)
{
    // Handle payment.captured events for better payment tracking
    $paymentId = $data['payload']['payment']['entity']['id'];
    $amount = $data['payload']['payment']['entity']['amount'];
    
    logTransaction($gatewayParams['name'], array(
        'payment_id' => $paymentId,
        'amount' => $amount,
        'status' => 'captured'
    ), 'Payment Captured');
    
    // Additional processing if needed
    return;
}

/**
 * Refund Processed webhook - MINOR FIX
 * @param array $data
 * @param array $gatewayParams
 */
function refundProcessed(array $data, $gatewayParams)
{
    $refundId = $data['payload']['refund']['entity']['id'];
    $paymentId = $data['payload']['refund']['entity']['payment_id'];
    $amount = $data['payload']['refund']['entity']['amount'];
    $status = $data['payload']['refund']['entity']['status'];
    
    logTransaction($gatewayParams['name'], array(
        'refund_id' => $refundId,
        'payment_id' => $paymentId,
        'amount' => $amount,
        'status' => $status
    ), 'Refund Processed');
    
    // Additional refund processing if needed
    return;
}

/**
 * Enhanced error handling and logging
 * @param string $message
 * @param array $data
 * @param string $level
 */
function logWebhookError($message, $data = array(), $level = 'Error')
{
    global $gatewayParams;
    
    $logData = array(
        'message' => $message,
        // Avoid logging full webhook payload (can contain PII). Log only safe metadata.
        'data' => array(
            'event' => $data['event'] ?? null,
            'has_payload' => isset($data['payload']),
        ),
        'timestamp' => date('Y-m-d H:i:s'),
        'level' => $level
    );
    
    logTransaction($gatewayParams['name'], $logData, $level);
}

/**
 * Validate webhook data structure
 * @param array $data
 * @return bool
 */
function validateWebhookData($data)
{
    if (!isset($data['event']) || empty($data['event'])) {
        logWebhookError('Missing event type', $data);
        return false;
    }
    
    if (!isset($data['payload']) || !is_array($data['payload'])) {
        logWebhookError('Missing or invalid payload', $data);
        return false;
    }
    
    return true;
}

// Update sync timestamp after successful webhook processing
if (function_exists('updateLastSyncTimestamp')) {
    updateLastSyncTimestamp('razorpay');
}

?>