# Razorpay WHMCS Gateway Module – AI Agent Guidelines

> Production-grade AI coding rules for the Razorpay WHMCS Payment Gateway Module.

---

## Project Overview

| Property      | Value                       |
| ------------- | --------------------------- |
| **Language**  | PHP 5.6+                    |
| **Framework** | WHMCS 6.x/7.x/8.x/9.x       |
| **SDK**       | Razorpay PHP SDK 2.8+       |
| **ORM**       | Illuminate Database Capsule |
| **License**   | MIT                         |

### Directory Structure

```
modules/gateways/
├── razorpay.php                    # Main gateway module
├── callback/razorpay.php           # Callback stub
└── razorpay/
    ├── razorpay-webhook.php        # Webhook handler
    ├── rzpordermapping.php         # Order mapping + compat layer
    └── lib/razorpay-sdk/           # Razorpay PHP SDK
```

---

## Critical Rules

### Security (Non-Negotiable)

1. **Never log secrets** – API keys, webhook secrets, passwords must never appear in logs
2. **Validate all input** – Check type, range, format for all external data
3. **Verify webhook signatures** – Use `hash_equals()` for constant-time comparison
4. **Use Capsule ORM** – Never use raw SQL or `mysql_*` functions
5. **Sanitize output** – Escape data for HTML/JS context

### PHP 5.6+ Compatibility

```php
// ✅ Correct: PHPDoc for types (PHP 5.6 compatible)
/**
 * @param array $params
 * @return string|false
 */
function createOrder($params)

// ❌ Wrong: Return type hints (PHP 7+ only)
function createOrder(array $params): string|false
```

**Required Polyfills** (already in codebase):

- `hash_equals()` – for PHP < 5.6.17
- `random_bytes()` – for PHP < 7.0

### WHMCS Integration

```php
// Always start files with WHMCS guard
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Use WHMCS logging
logTransaction($gatewayName, $data, $status);

// Use localAPI for WHMCS operations
$result = localAPI('AddInvoicePayment', array(
    'invoiceid' => $invoiceId,
    'transid' => $transId,
    'gateway' => 'razorpay',
    'amount' => $amount
));

// Use Capsule for database
$invoice = Capsule::table('tblinvoices')->where('id', $id)->first();
```

---

## Code Style

### Naming Conventions

| Type      | Convention  | Example                   |
| --------- | ----------- | ------------------------- |
| Functions | camelCase   | `createRazorpayOrderId()` |
| Constants | UPPER_SNAKE | `RAZORPAY_PAYMENT_ID`     |
| Classes   | PascalCase  | `RZPOrderMapping`         |
| Variables | camelCase   | `$razorpayOrderId`        |

### Function Structure

```php
/**
 * Brief description of function purpose
 *
 * @param array $params Gateway parameters
 * @return array Result with 'status' key
 */
function razorpay_refund($params)
{
    // 1. Validate input
    if (empty($params['transid'])) {
        return array('status' => 'error', 'rawdata' => 'Missing transaction ID');
    }

    // 2. Main logic in try-catch
    try {
        $api = getRazorpayApiInstance($params);
        $refund = $api->payment->refund($params['transid'], $refundData);

        // 3. Log success
        logTransaction($gatewayName, $refund, 'Refund Successful');

        return array('status' => 'success', 'transid' => $refund['id']);
    } catch (Exception $e) {
        // 4. Log and return error
        logTransaction($gatewayName, $e->getMessage(), 'Refund Error');
        return array('status' => 'error', 'rawdata' => $e->getMessage());
    }
}
```

---

## Error Handling Patterns

### API Calls

```php
try {
    $order = $api->order->create($data);
} catch (Exception $e) {
    logTransaction($name, $e->getMessage(), 'API Error - Order Creation Failed');
    return false;
}
```

### WHMCS Return Format

```php
// Success
return array(
    'status' => 'success',
    'rawdata' => $response,
    'transid' => $paymentId,
    'fees' => $gatewayFee
);

// Error
return array(
    'status' => 'error',
    'rawdata' => $errorMessage,
    'declinereason' => 'User-friendly error message'
);
```

---

## Webhook Processing Checklist

1. ☐ Accept POST only: `$_SERVER['REQUEST_METHOD'] === 'POST'`
2. ☐ Read raw body: `file_get_contents('php://input')`
3. ☐ Parse JSON: `json_decode($post, true)`
4. ☐ Verify signature: Compare `HTTP_X_RAZORPAY_SIGNATURE` with computed HMAC
5. ☐ Check idempotency: Don't reprocess existing transactions
6. ☐ Validate amount: Compare with invoice total
7. ☐ Record payment: Use `localAPI('AddInvoicePayment', ...)`
8. ☐ Log everything: Use `logTransaction()` for audit trail

---

## Testing

### Syntax Check

```bash
php -l modules/gateways/razorpay.php
php -l modules/gateways/razorpay/razorpay-webhook.php
```

### Test Scripts

```bash
php razorpay-whmcs-module/scripts/test-razorpay-api.php
php razorpay-whmcs-module/scripts/test-specific-invoice.php
```

### Manual Testing

1. Create test invoice in WHMCS
2. Process payment with Razorpay test credentials
3. Verify payment recorded in WHMCS
4. Test refund functionality
5. Check gateway logs for proper logging

---

## Priority Order

When requirements conflict:

1. **Security** – Never compromise on input validation, signature verification
2. **WHMCS Compatibility** – Must work on WHMCS 6/7/8
3. **PHP 5.6 Support** – Maintain backward compatibility
4. **Code Clarity** – Readable, maintainable code
5. **Minimal Diff** – Surgical changes for easier review

---

## Quick Reference

### Key Functions

- `razorpay_config()` – Gateway configuration options
- `razorpay_link()` – Payment form HTML
- `razorpay_refund()` – Process refunds
- `razorpay_capture()` – Capture authorized payments
- `createRazorpayOrderId()` – Create Razorpay order

### Key Classes

- `RZPOrderMapping` – Invoice to Razorpay order mapping
- `RzpWhmcsCompat` – Cross-version compatibility layer
- `Razorpay\Api\Api` – Razorpay SDK client

### Important Tables

- `tblinvoices` – WHMCS invoices
- `tblaccounts` – WHMCS transactions
- `tblrzpordermapping` – Custom order mapping table
