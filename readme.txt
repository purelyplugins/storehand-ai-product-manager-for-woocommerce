=== StoreHand AI Product Manager for WooCommerce ===
Contributors: marthast
Tags: woocommerce, ai, products, assistant, natural-language
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage WooCommerce products using natural language. Works with WordPress 7.0 AI — Anthropic, OpenAI, Google. No change without confirmation.

== Description ==

StoreHand AI Product Manager for WooCommerce is a natural language assistant for your product catalog. Describe what you want — create a product, update a price, adjust stock, change a description — and the plugin handles the WooCommerce operations for you. It reliably creates, edits, and manages products through a conversational interface backed by the WordPress 7.0 native AI platform.

**Provider agnostic — your choice of AI**

The plugin is built on WordPress 7.0's native AI infrastructure and works with any provider you configure in Settings → Connectors. Set it up once and the plugin uses it automatically. Supported providers:

* Anthropic Claude
* OpenAI GPT
* Google Gemini

Your API key stays in WordPress — the plugin never handles it directly.

**Getting started:**

* [AI Provider for Anthropic](https://wordpress.org/plugins/ai-provider-for-anthropic/) (recommended — new accounts include $5 free credit)
* [AI Provider for OpenAI](https://wordpress.org/plugins/ai-provider-for-openai/)
* [AI Provider for Google](https://wordpress.org/plugins/ai-provider-for-google/)

**Confirmation before every write**

A confirmation step is required before any write operation executes. The plugin shows you exactly what will change — product name, price, status, stock — and waits for your approval. No changes happen without it.

**Features:**

* Create products with natural language
* Create and manage variable products (multiple sizes, colours, options)
* Update prices for single or multiple products
* Edit product details (title, description, SKU, stock)
* Update stock levels
* Change product status (draft/publish)
* Multi-turn conversations — asks clarifying questions when needed
* Confirmation required before any change is executed
* Works with Anthropic, OpenAI, or Google via Settings → Connectors
* Free plugin — you only pay your AI provider for usage

**WordPress 7.0 AI Integration:**

* Tested and working with WordPress 7.0 native AI connectors
* Uses WordPress AI Client for all AI interactions
* Respects site-wide AI settings
* Provider-agnostic architecture
* Secure key management via WordPress Connectors

**How it works:**

1. Install the plugin and activate it
2. Install an AI provider plugin (e.g. AI Provider for Anthropic)
3. Add your API key in Settings → Connectors
4. Start managing products with natural language

New Anthropic accounts include $5 in free credits — approximately 50-100 product operations.

**Examples:**

* "Create a product called Blue Widget for £29.99"
* "Update the price for Summer T-Shirt to £19.99"
* "Change the description for product 123"
* "Set stock to 50 for Red Hoodie"
* "Change product 456 to draft status"

Requires WordPress 7.0+ and WooCommerce 8.0+.

== Important Safety Information ==

**Please Read Before Using**

StoreHand AI Product Manager makes real changes to your WooCommerce products based on AI interpretation of your requests.

**We strongly recommend:**

* **Test on a staging site first** — Try the plugin on a test environment before using on your live store
* **Keep regular backups** — Maintain database backups in case you need to restore
* **Review changes** — Always review what the plugin plans to do before confirming
* **Start simple** — Begin with basic requests to understand how it works

**AI Limitations:**

While the plugin uses advanced AI technology, it may occasionally misinterpret requests. You are responsible for reviewing and approving all changes. The plugin is designed to be safe (changing products to draft instead of deleting them), but you should always verify changes match your intentions.

**Your Responsibility:**

By using this plugin, you acknowledge that you are responsible for all changes made to your store. The plugin developers are not liable for data loss, incorrect changes, or any damages arising from use of the plugin.

== Installation ==

**Automatic Installation:**

1. Log in to your WordPress dashboard
2. Go to Plugins > Add New
3. Search for "StoreHand AI Product Manager for WooCommerce"
4. Click "Install Now" and then "Activate"

**Manual Installation:**

1. Download the plugin zip file
2. Go to Plugins > Add New > Upload Plugin
3. Choose the zip file and click "Install Now"
4. Click "Activate Plugin"

**Setup:**

1. After activation, you'll see the StoreHand AI Product Manager button in your WordPress dashboard
2. Install an AI provider plugin (e.g. AI Provider for Anthropic)
3. Go to Settings → Connectors and add your API key
4. Start managing products with natural language

== Frequently Asked Questions ==

= Does this require WordPress 7.0? =

Yes. StoreHand AI Product Manager is built on WordPress 7.0's AI platform and requires the native AI client infrastructure introduced in that version.

= Which AI provider should I use? =

StoreHand AI Product Manager works with any provider configured in Settings → Connectors:

* Anthropic Claude (recommended — includes $5 free credit)
* OpenAI GPT-4
* Google Gemini

Install the appropriate AI provider plugin from WordPress.org and configure your API key in Settings → Connectors.

= How much does it cost? =

The plugin is completely free. You only pay your AI provider for usage. The plugin itself has no subscription fees or premium tiers.

* **Anthropic:** New accounts include $5 in free credits (~50-100 operations)
* **OpenAI:** Pay-as-you-go, see platform.openai.com for pricing
* **Google Gemini:** Free tier available, see ai.google.dev for details
* Typical cost per request: £0.01-0.10 depending on provider and model
* Set spending limits in your provider account to control costs

= How do I set up an AI provider? =

StoreHand AI Product Manager uses the WordPress 7.0 AI platform. To get started:

1. Install one of the supported AI provider plugins:
   * [AI Provider for Anthropic](https://wordpress.org/plugins/ai-provider-for-anthropic/) (recommended)
   * [AI Provider for OpenAI](https://wordpress.org/plugins/ai-provider-for-openai/)
   * [AI Provider for Google](https://wordpress.org/plugins/ai-provider-for-google/)
2. Go to Settings → Connectors in your WordPress dashboard
3. Enter your API key for your chosen provider
4. StoreHand AI Product Manager will automatically use whichever provider you configure

= Is my API key secure? =

Yes. API keys are managed by WordPress in Settings → Connectors. They are stored securely in your WordPress database and are only used when making AI requests.

= What if I make a mistake? =

StoreHand AI Product Manager is designed with safety in mind:

* Creates new products as drafts (not published) - you review before they go live
* Never deletes products — changes them to draft instead. Publishing is always a deliberate, confirmed action
* Asks for confirmation before making changes
* Shows you what will change before executing
* All changes are reversible through WooCommerce

You can always manually undo any changes StoreHand AI Product Manager makes.

= Can I use this on a live production site? =

While StoreHand AI Product Manager is safe to use, we strongly recommend testing on a staging site first to understand how it works and ensure it meets your needs. Once comfortable, you can use it on your production site with confidence.

= Does StoreHand AI Product Manager support variable products? =

Yes. You can create variable products with multiple sizes, colours, or other options, and manage individual variation prices and stock through natural language.

= Does this work with WooCommerce Bookings? =

Not yet. Version 1.0 focuses on product management. Bookings support is planned for a future update based on user feedback.

= What WordPress and WooCommerce versions do I need? =

* WordPress 7.0 or higher (required for the AI platform)
* WooCommerce 8.0 or higher
* PHP 8.0 or higher
* One of the supported AI provider plugins

= Which AI providers are supported? =

StoreHand AI Product Manager works with any provider available through the WordPress 7.0 AI platform:

* **Anthropic Claude** — install [AI Provider for Anthropic](https://wordpress.org/plugins/ai-provider-for-anthropic/)
* **OpenAI GPT** — install [AI Provider for OpenAI](https://wordpress.org/plugins/ai-provider-for-openai/)
* **Google Gemini** — install [AI Provider for Google](https://wordpress.org/plugins/ai-provider-for-google/)

We recommend starting with Anthropic — new accounts include $5 in free credits.

= What happens if StoreHand AI Product Manager misunderstands my request? =

StoreHand AI Product Manager will show you what it plans to do before executing. If it misunderstood, simply click "No" or "Cancel" and rephrase your request. StoreHand AI Product Manager learns from the conversation and you can clarify exactly what you want.

= Why are products created as drafts instead of published? =

For your safety, StoreHand AI Product Manager creates all new products as drafts. This gives you a chance to review pricing, descriptions, images, and other details in WooCommerce before making them live on your store. You decide when products are ready to publish.

This prevents accidental publishing of incomplete or incorrect products. You stay in full control of what goes live.

= How do I get support? =

For issues or questions:

* Check the FAQ above
* Visit the WordPress.org support forum
* Email hello@purelyplugins.com
* Visit https://purelyplugins.com

== Screenshots ==

1. WordPress admin products page showing the floating assistant button
2. Chat sidebar open with welcome message and quick action buttons
3. User typing a create product request with image attachment
4. Confirmation screen before executing the action
5. Product created as draft with success message
6. User typing a price edit request
7. Price updated with before/after confirmation

== Source Code ==

The full source code for this plugin, including all unminified JavaScript and CSS, is publicly available on GitHub:

https://github.com/purelyplugins/storehand-ai-product-manager-for-woocommerce

**Build instructions:**

The frontend is built with Vite. To compile from source:

1. Clone or download the repository
2. Run `npm install` to install dependencies
3. Run `npm run build` to compile — output goes to the `build/` directory

Source files are in the `src/` directory. The compiled `build/index.js` included in the plugin is generated from this source.

== Changelog ==

= 1.0.0 - June 2026 =
* Initial release
* Create products with natural language
* Update product prices (single and bulk)
* Edit product details (title, description, SKU, stock)
* Update stock levels
* Change product status (draft/publish)
* Multi-turn conversations with clarifying questions
* WordPress 7.0 AI platform integration (Anthropic, OpenAI, Google)
* AI provider configured via Settings → Connectors
* Safety confirmations before changes
* WordPress 7.0+ compatibility

== Upgrade Notice ==

= 1.0.0 =
Initial release of StoreHand AI Product Manager for WooCommerce. AI-powered product management with natural language.

== Privacy & Data ==

**Third-Party Services:**

This plugin routes requests through the WordPress 7.0 AI platform to your configured AI provider. When you use StoreHand AI Product Manager:

* Your natural language requests are sent to your AI provider's servers
* Product information (titles, prices, descriptions, SKUs) may be included
* No customer personal data is sent
* No data is sent without an AI provider configured in Settings → Connectors

**Provider Privacy Policies:**

Depending on which AI provider you configure, the relevant privacy policy applies:

* Anthropic: https://www.anthropic.com/legal/privacy
* OpenAI: https://openai.com/policies/privacy-policy
* Google: https://policies.google.com/privacy

**Data You Control:**

* All product data remains in your WordPress database
* Your AI provider API key is managed by WordPress in Settings → Connectors
* You can remove your API key and stop using the service at any time

**User Consent:**

By configuring an AI provider and using StoreHand AI Product Manager, you consent to sending product data to that provider for AI processing in accordance with their privacy policy.

== Privacy Policy ==

See the Privacy & Data section above for full details on data handling and third-party services.

== Additional Information ==

**Links:**

* Website: https://purelyplugins.com
* Support: hello@purelyplugins.com
* Documentation: https://purelyplugins.com
* Terms of Service: https://purelyplugins.com/terms-and-conditions.html

**Credits:**

Built on the WordPress 7.0 AI platform. Works with Anthropic Claude, OpenAI GPT, and Google Gemini.

== Terms of Service ==

By using StoreHand AI Product Manager for WooCommerce, you agree to:

* Review all AI-generated changes before confirming them
* Take responsibility for changes made to your WooCommerce store
* Maintain backups of your product data
* Use the plugin in accordance with WordPress and WooCommerce terms

The plugin is provided "as is" without warranty. See full terms at https://purelyplugins.com/terms-and-conditions.html
