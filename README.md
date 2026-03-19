# Razorpay Payment Gateway for WHMCS

[![MIT License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![WHMCS](https://img.shields.io/badge/WHMCS-6.x%20|%207.x%20|%208.x-blue.svg)](https://www.whmcs.com)
[![PHP](https://img.shields.io/badge/PHP-5.6%2B-purple.svg)](https://php.net)

A production-ready Razorpay payment gateway integration for WHMCS, enabling Indian merchants to accept credit cards, debit cards, netbanking, UPI, and wallet payments seamlessly.

## ✨ Features

- **Multi-WHMCS Support** – Compatible with WHMCS 6.x, 7.x, 8.x, and 9.x
- **PHP 5.6+ Compatible** – Graceful fallbacks for older PHP versions
- **Webhook Processing** – Automatic payment confirmation and recording
- **Full & Partial Refunds** – Process refunds directly from WHMCS
- **Multi-Currency** – Support for INR, USD, EUR, GBP, and more
- **Gateway Fee Handling** – Merchant absorbs or client pays options
- **Secure** – PCI compliant, offsite processing, signature verification

## 📦 Directory Structure

```
razorpay-whmcs/
├── modules/gateways/
│   ├── razorpay.php                 # Main gateway module
│   ├── callback/razorpay.php        # Payment callback handler
│   └── razorpay/
│       ├── razorpay-webhook.php     # Webhook processor
│       ├── rzpordermapping.php      # Order mapping utility
│       ├── lib/razorpay-sdk/        # Official Razorpay PHP SDK
│       └── README.md                # Module documentation
├── razorpay-whmcs-module/           # Distributable package
│   ├── CHANGELOG.md
│   ├── INSTALLATION.md
│   └── scripts/                     # Utility & test scripts
├── AGENT.md                         # AI coding guidelines
└── .cursor/rules/                   # Cursor AI rules
```

## 🚀 Quick Start

### Prerequisites

- WHMCS 6.0+ installed and running
- PHP 5.6+ with cURL extension
- SSL certificate (required for webhooks)
- [Razorpay account](https://dashboard.razorpay.com/signup) with API keys

### Installation

1. **Download** the latest release or clone this repository
2. **Upload** module files to your WHMCS installation:
   ```bash
   cp modules/gateways/razorpay.php /path/to/whmcs/modules/gateways/
   cp modules/gateways/callback/razorpay.php /path/to/whmcs/modules/gateways/callback/
   cp -r modules/gateways/razorpay /path/to/whmcs/modules/gateways/
   ```
3. **Configure** in WHMCS Admin → Setup → Payments → Payment Gateways

### Configuration

| Setting                  | Description                                                                  |
| ------------------------ | ---------------------------------------------------------------------------- |
| **Key ID**               | Your Razorpay Key ID ([Get keys](https://dashboard.razorpay.com/#/app/keys)) |
| **Key Secret**           | Your Razorpay Key Secret                                                     |
| **Webhook Secret**       | Secret for webhook signature verification                                    |
| **Enable Webhook**       | Must be **Yes** for payments to be recorded                                  |
| **Gateway Fee Mode**     | Merchant absorbs (default) or Client pays                                    |
| **Supported Currencies** | Comma-separated list (e.g., `INR,USD,EUR`)                                   |

## ⚡ Webhook Setup (Critical)

> ⚠️ **Without webhooks, payments will NOT be recorded in WHMCS!**

1. Go to [Razorpay Dashboard → Settings → Webhooks](https://dashboard.razorpay.com/#/app/webhooks)
2. Click **Add New Webhook**
3. Enter URL: `https://yourdomain.com/modules/gateways/razorpay/razorpay-webhook.php`
4. Select events:
   - ✅ `payment.captured`
   - ✅ `order.paid`
   - ✅ `refund.created`
   - ✅ `refund.processed`
5. Copy the webhook secret to WHMCS gateway configuration

## 🧪 Testing

Use Razorpay test mode credentials and test cards:

| Card Number           | Result  |
| --------------------- | ------- |
| `4111 1111 1111 1111` | Success |
| `4000 0000 0000 0002` | Failure |

Verify in: WHMCS Admin → Utilities → Logs → Gateway Log

## 🔧 Utility Scripts

```bash
# Sync payments from Razorpay
php modules/gateways/razorpay/scripts/sync-payments.php --since=2025-01-01

# Webhook diagnostics
php modules/gateways/razorpay/scripts/webhook-diagnostic.php

# Cross-check payments
php modules/gateways/razorpay/scripts/cross-check-tool.php
```

## 🔍 Troubleshooting

| Issue                         | Solution                                     |
| ----------------------------- | -------------------------------------------- |
| Payments not recorded         | Verify webhook is enabled and URL is correct |
| Signature verification failed | Check webhook secret matches in both places  |
| Currency not supported        | Add currency to supported currencies list    |

For detailed troubleshooting, see [INSTALLATION.md](razorpay-whmcs-module/INSTALLATION.md#troubleshooting).

## 📖 Documentation

- [Installation Guide](razorpay-whmcs-module/INSTALLATION.md)
- [Changelog](razorpay-whmcs-module/CHANGELOG.md)
- [Module README](modules/gateways/razorpay/README.md)
- [Razorpay API Docs](https://razorpay.com/docs/)
- [WHMCS Gateway Development](https://developers.whmcs.com/payment-gateways/)

### Optional: Affordability Widget

To surface Razorpay EMI² affordability information on product or cart pages:

- Enable **Affordability Widget** and set **Affordability Widget Key** in the Razorpay gateway settings.
- Ensure your active WHMCS theme includes a container where the widget should render, for example:

```html
<div id="razorpay-affordability-widget"></div>
```

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Follow the coding rules in [AGENT.md](AGENT.md)
4. Test thoroughly with WHMCS test environment
5. Submit a pull request

## 📄 License

This project is licensed under the MIT License – see [LICENSE](razorpay-whmcs-module/LICENSE) for details.

## 🙏 Support

- **Issues**: [GitHub Issues](https://github.com/razorpay/razorpay-whmcs/issues)
- **Razorpay Support**: [integrations@razorpay.com](mailto:integrations@razorpay.com)
- **Documentation**: [razorpay.com/docs](https://razorpay.com/docs/)

---

**Version**: 2.2.1 | **Tested on**: WHMCS 6.3, 7.10, 8.13 | **PHP**: 5.6, 7.4, 8.0, 8.1, 8.2
