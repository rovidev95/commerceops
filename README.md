# CommerceOps

WooCommerce utility plugins: AI-ready schema, order webhooks, bulk CSV sync.

## Download (install in WordPress)

| Plugin | Install |
|--------|---------|
| **Woo AI Schema** | [woo-ai-schema.zip](https://github.com/rovidev95/commerceops/releases/latest/download/woo-ai-schema.zip) |
| **Order Webhook Hub** | [order-webhook-hub.zip](https://github.com/rovidev95/commerceops/releases/latest/download/order-webhook-hub.zip) |
| **Bulk Product Sync** | [bulk-product-sync.zip](https://github.com/rovidev95/commerceops/releases/latest/download/bulk-product-sync.zip) |

WordPress: **Plugins → Add New → Upload Plugin** → choose zip → Activate.

Requires **WooCommerce 7+**.

## Pro licenses

Configure Lemon Squeezy in `config.json`, or use dev key `CO-DEV-TEST` in plugin settings.

## Where we publish

| Channel | Status | URL |
|---------|--------|-----|
| GitHub Releases | Live | https://github.com/rovidev95/commerceops/releases |
| GitHub Pages (landing) | Live | https://rovidev95.github.io/commerceops/ |
| WordPress.org | Pending account submit | https://wordpress.org/plugins/developers/add/ |
| Chrome Web Store | Pending | Extension in `extensions/schema-validator/` |
| Lemon Squeezy | Pending products | https://lemonsqueezy.com |

## Develop

```powershell
.\scripts\Build-CommerceOpsPlugins.ps1 -All
.\scripts\Invoke-CommerceOpsAudit.ps1
```

## WordPress.org submit

```powershell
.\scripts\Deploy-WooAiSchemaWpOrg.ps1
```

Upload `dist/woo-ai-schema.zip` at https://wordpress.org/plugins/developers/add/
