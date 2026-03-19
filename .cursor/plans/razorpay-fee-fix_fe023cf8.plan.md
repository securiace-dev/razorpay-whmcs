---
name: razorpay-fee-fix
overview: Fix fee-aware payment handling so WHMCS correctly records Razorpay payments when Razorpay applies fee-bearer surcharge (screenshots show `₹9,100` invoice becoming `₹9,368.45` = `+2.95%` surcharge). The widget “extra charges” come from Razorpay’s fee-bearer/surcharge configuration; our integration bugs are (1) webhook strict amount matching and (2) incorrect `feeMode` handling when computing WHMCS `amount`/`fees`. Also reduce OTP/saved-card issues by normalizing `prefill.contact`.
todos:
  - id: webhook-fee-aware-validation
    content: Update `orderPaid()` in `modules/gateways/razorpay/razorpay-webhook.php` to allow fee-bearer surcharge cases (`actual >= expected`) without failing strict equality, and to compute WHMCS `amount`/`fees` according to `feeMode` (use Razorpay `paymentDetails['fee']` when available).
    status: pending
  - id: callback-fee-calculation-baseline
    content: Fix callback fee/baseline logic in `modules/gateways/razorpay/callback/razorpay.php` so fee is derived from Razorpay `paymentDetails['fee']` when available, and `feeMode` semantics are respected (separate “expected base” vs “actual payment”).
    status: pending
  - id: normalize-prefill-contact
    content: Normalize `clientdetails.phonenumber` to E.164 (+91 for India 10-digit) before injecting into `data-prefill.contact` in `modules/gateways/razorpay.php`.
    status: pending
  - id: add-safe-amount-logging
    content: Add non-sensitive debug logging in `modules/gateways/razorpay.php` around order creation amount/currency and feeMode, to confirm the amount used when the widget loads.
    status: pending
  - id: manual-verification
    content: "Verify with one invoice under client-fee-bearer mode: compare Razorpay payment `amount/fee` with WHMCS recorded `amount/fees`, and re-check saved-card OTP flow."
    status: pending
isProject: false
---

## Root causes (from code audit)

- From screenshots, the widget’s `₹9,368.45` base amount matches `₹9,100 * 1.0295`, consistent with Razorpay applying a `2.95%` fee-bearer surcharge when `fee_mode=client_pays` (your Razorpay payment details also show `surcharge_amount: 2.95`). So the checkout inflation is coming from Razorpay’s fee-bearer/surcharge config (expected), not from WHMCS passing the wrong base invoice amount.
- In `[modules/gateways/razorpay/razorpay-webhook.php](modules/gateways/razorpay/razorpay-webhook.php)`, `orderPaid()` marks the payment as failed unless the Razorpay payment `amount` exactly equals the WHMCS order amount (strict equality).
- The same `orderPaid()` feeMode handling can record `paymentAmount`/`fees` in a way that doesn’t align with `feeMode=client_pays` semantics when surcharges are present.
- In `[modules/gateways/razorpay/callback/razorpay.php](modules/gateways/razorpay/callback/razorpay.php)`, the callback overwrites the reference `$amount` with `max(paymentAmount, invoiceBalance)`, making subsequent fee/baseline calculations confusing and error-prone when surcharges are present.
- OTP/saved-cards behavior is influenced by `data-prefill.contact` in `[modules/gateways/razorpay.php](modules/gateways/razorpay.php)`. Normalizing the phone to E.164 reduces risk of OTP being sent to the wrong number or saved-card identity mismatches.

## Implementation tasks

1. **Make webhook fee-aware instead of strict-equality**
  - Update `[modules/gateways/razorpay/razorpay-webhook.php](modules/gateways/razorpay/razorpay-webhook.php)` inside `orderPaid()`.
  - Replace the strict check:

```php
     if($data['payload']['payment']['entity']['amount'] === $amount) { ... }
     

```

```
with logic that allows fee-bearer surcharge cases (`actual >= expected`), while still failing on major underpayment.
```

- Fix `feeMode` semantics:
  - Fetch Razorpay `paymentDetails` and derive `razorpayFeeRuples` from `paymentDetails['fee']/100` (if present).
  - If `feeMode === 'client_pays'`: record `amount = actualPaymentAmount` and `fees = 0`.
  - If `feeMode === 'merchant_absorbs'`: record `amount = orderAmount` and `fees = razorpayFeeRuples`.
- Add log fields (no secrets): `expected_amount_rupees`, `actual_amount_rupees`, `razorpay_fee_rupees`, `fee_mode_config`, `surcharge_diff_rupees`.

1. **Fix callback amount/fee baseline logic**
  - Update `[modules/gateways/razorpay/callback/razorpay.php](modules/gateways/razorpay/callback/razorpay.php)`.
  - Use separate variables for “expected base” (`$invoiceBalance`) and “actual payment” (`paymentDetails['amount']/100`).
  - Compute Razorpay fee from `paymentDetails['fee']/100` when available; otherwise fall back to `max(0, actualPaymentAmount - invoiceBalance)`.
  - Then set WHMCS `amount` and `fees` according to `feeMode`:
    - `merchant_absorbs`: record base amount, set `fees = razorpayFee`.
    - `client_pays`: record `amount = actualPaymentAmount`, `fees = 0`.
2. **Normalize phone number before passing to Razorpay checkout**
  - Update `[modules/gateways/razorpay.php](modules/gateways/razorpay.php)` in `razorpay_link()` where `$contact = $params['clientdetails']['phonenumber'];` and injected into `data-prefill.contact`.
  - Implement a small helper (local to the file) to:
    - trim spaces,
    - remove non-digits,
    - if it’s a 10-digit number starting with India mobile prefix rules, prefix `+91`.
  - Ensure the normalized contact is HTML/JS safe when inserted into the checkout script attributes.
3. **Add verification logging around order creation**
  - In `[modules/gateways/razorpay.php](modules/gateways/razorpay.php)` inside `createRazorpayOrderId()` and/or right before calling `order->create()`, log:
    - `invoiceid`, `params.amount` (rupees), `currency`, `paymentAction`, and current `feeMode` config value.
  - This will let you confirm whether WHMCS is passing `params['amount']` equal to the invoice base you expect.
4. **Test plan**
  - Test with `Gateway Fee Mode = client_pays` in WHMCS and “client bears gateway fee” enabled in Razorpay account.
  - For one problematic invoice:
    - Capture Razorpay `payment.amount` and `payment.fee` from Razorpay dashboard.
    - Confirm webhook now records the payment and doesn’t log “Amount mismatch”.
    - Confirm callback records `fees` correctly (merchant_absorbs should separate; client_pays should not).
  - Re-run the saved-card flow and confirm OTP prompts (if they reappear) use the expected mobile shown in the widget.

## Files to change

- `[modules/gateways/razorpay/razorpay-webhook.php](modules/gateways/razorpay/razorpay-webhook.php)`
- `[modules/gateways/razorpay/callback/razorpay.php](modules/gateways/razorpay/callback/razorpay.php)`
- `[modules/gateways/razorpay.php](modules/gateways/razorpay.php)`

## Key expected outcomes

- The checkout widget’s “extra amount” inflation (e.g., ₹9,100 -> ₹9,368.45) is explained by Razorpay’s `2.95%` fee-bearer surcharge config; WHMCS should not treat this as a “wrong base amount”.
- Webhook/callback should record the correct WHMCS `amount`/`fees` according to `feeMode`:
  - `client_pays`: record full `amount` (including surcharge), keep `fees = 0`
  - `merchant_absorbs`: record base `amount`, set `fees` from Razorpay payment `fee`
- Webhook should stop failing legitimate fee-bearing payments with “Amount mismatch”.
- OTP/saved-card identity mismatches should be reduced due to normalized `data-prefill.contact`.

