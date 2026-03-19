<?php

use WHMCS\View\Menu\Item as MenuItem;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Inject Razorpay Affordability Widget assets into client area pages.
 *
 * This is intentionally minimal and opt-in:
 * - Controlled via Razorpay gateway settings.
 * - Does not modify core templates.
 * - Expects themes to place a <div id=\"razorpay-affordability-widget\"></div>
 *   where the widget should appear.
 */

add_hook('ClientAreaHeadOutput', 1, function (array $vars) {
    if (!function_exists('getGatewayVariables')) {
        return '';
    }

    $gatewayVars = getGatewayVariables('razorpay');
    if (empty($gatewayVars['affordabilityWidgetEnabled']) || $gatewayVars['affordabilityWidgetEnabled'] !== 'on') {
        return '';
    }

    $widgetKey = $gatewayVars['affordabilityWidgetKey'] ?? '';
    if ($widgetKey === '') {
        return '';
    }

    // Derive amount in paise when possible (fallback to 0 if unknown).
    $amountPaise = 0;
    if (!empty($vars['rawprice'])) {
        $amountPaise = (int) round($vars['rawprice'] * 100);
    } elseif (!empty($vars['total'])) {
        $amountPaise = (int) round($vars['total'] * 100);
    }

    $jsKey = json_encode($widgetKey);
    $jsAmount = (int) $amountPaise;

    $script = <<<HTML
<script src="https://cdn.razorpay.com/widgets/affordability/affordability.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    try {
        var key = {$jsKey};
        var amount = {$jsAmount};
        if (typeof RazorpayAffordabilitySuite === 'function' && amount > 0) {
            var widgetConfig = {
                key: key,
                amount: amount
            };
            var rzpAffordabilitySuite = new RazorpayAffordabilitySuite(widgetConfig);
            rzpAffordabilitySuite.render();
        }
    } catch (e) {
        if (window.console && console.warn) {
            console.warn('Razorpay Affordability Widget init failed', e);
        }
    }
});
</script>
HTML;

    return $script;
});

