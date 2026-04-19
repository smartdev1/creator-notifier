=== MP Creator Notifier Pro ===
Contributors: baanabaana
Tags: woocommerce, creators, notifications, brands, api, webhook
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Notify creators when their products are sold in WooCommerce. Includes REST API, Laravel CRM integration, and comprehensive statistics.

== Description ==

**MP Creator Notifier Pro** is a powerful WordPress plugin that automatically notifies content creators when their products are sold through your WooCommerce store. Perfect for marketplaces with multiple creators, brand partnerships, or collaborative e-commerce platforms.

= Key Features =

* **Automatic Email Notifications** - Creators receive instant email alerts when their products are sold
* **Brand-Based Assignment** - Associate creators with specific brands or product categories
* **Comprehensive REST API** - Full-featured API (v2) for external integrations
* **Laravel CRM Integration** - Built-in webhook support for Laravel applications
* **Real-Time Statistics** - Track sales, orders, and performance per creator or brand
* **Secure Authentication** - Token-based API authentication with hash verification
* **Customizable Email Templates** - Personalize notification emails with dynamic variables
* **Order Status Triggers** - Configure which order statuses trigger notifications
* **Multi-Creator Support** - Manage unlimited creators with unique brand associations
* **Developer-Friendly** - Clean code, hooks, filters, and extensive documentation

= Perfect For =

* Multi-vendor marketplaces
* Creator economy platforms
* Brand partnership programs
* Dropshipping operations
* Affiliate marketing stores
* Print-on-demand services
* Digital product marketplaces

= API Endpoints =

The plugin provides a comprehensive REST API at `/wp-json/mp/v2/`:

**Creators Management**
* `GET /creators` - List all creators
* `GET /creators/{id}` - Get single creator
* `POST /creators` - Create new creator
* `PUT /creators/{id}` - Update creator
* `DELETE /creators/{id}` - Delete creator

**Orders & Products**
* `GET /creators/{id}/orders` - Get creator's orders
* `GET /creators/{id}/products` - Get creator's products
* `GET /orders` - List all orders
* `GET /orders/{id}` - Get order details
* `POST /products/{id}/brand` - Assign brand to product
* `POST /products/brands-bulk` - Get brands for multiple products
* `POST /products/creators` - Get creators for multiple products

**Statistics**
* `GET /stats` - Global statistics
* `GET /creators/{id}/stats` - Creator-specific stats
* `GET /brands/{slug}/stats` - Brand-specific stats

**System**
* `GET /system/health` - Check system health
* `GET /system/test` - Test API connection

= Laravel Integration =

Seamlessly integrate with Laravel CRM systems:

1. Configure webhook URL in plugin settings
2. Generate secure webhook secret token
3. Add token to Laravel `.env` file
4. Receive real-time creator and order events

Webhook events include:
* `creator.created` - New creator added
* `creator.updated` - Creator information updated
* `order.created` - New order received
* `order.status_changed` - Order status updated

= Email Template Variables =

Customize email notifications with these dynamic variables:

* `{creator_name}` - Creator's name
* `{order_id}` - Order ID
* `{order_date}` - Order date and time
* `{order_total}` - Total amount for creator's products
* `{products_list}` - Formatted list of products sold

= Developer Hooks & Filters =

**Actions:**
* `mp_creator_created` - Fires after creator is created
* `mp_notification_sent` - Fires after notification is sent
* `mp_webhook_sent` - Fires after webhook is delivered

**Filters:**
* `mp_email_template` - Modify email template
* `mp_notification_data` - Filter notification data
* `mp_api_rate_limit` - Adjust API rate limit (default: 100/hour)
* `mp_dev_tokens` - Add development tokens

= Requirements =

* WordPress 5.8 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* MySQL 5.6 or higher

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Navigate to **Plugins → Add New**
3. Search for "MP Creator Notifier Pro"
4. Click **Install Now** and then **Activate**

= Manual Installation =

1. Download the plugin ZIP file
2. Log in to WordPress admin panel
3. Navigate to **Plugins → Add New → Upload Plugin**
4. Choose the ZIP file and click **Install Now**
5. Click **Activate Plugin**

= Configuration =

1. Navigate to **MP Creators** in the WordPress admin menu
2. Click **Settings**
3. Generate your API token (save this securely!)
4. Configure notification settings:
   - Select order statuses that trigger notifications
   - Customize email template
5. (Optional) Configure Laravel webhook integration:
   - Add your Laravel CRM webhook URL
   - Generate webhook secret token
   - Add token to your Laravel `.env` file

= Creating Your First Creator =

1. Go to **MP Creators** in the admin menu
2. Click **Add New Creator**
3. Fill in the required information:
   - Name
   - Email address
   - Brand slug (associates creator with products)
4. Click **Create Creator**

= Assigning Brands to Products =

**Method 1: Product Edit Page**
1. Edit a WooCommerce product
2. Add custom field `brand_slug` with the creator's brand
3. Update product

**Method 2: Via API**
```bash
curl -X POST "https://yoursite.com/wp-json/mp/v2/products/123/brand" \
  -H "X-MP-Token: YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"brand_slug": "brand-name"}'
```

**Method 3: Using Product Taxonomies**
The plugin automatically detects `product_brand` taxonomy if configured in WooCommerce.

== Frequently Asked Questions ==

= How does the plugin know which creator to notify? =

The plugin matches products to creators using the `brand_slug` field. When a product with brand X is sold, all creators associated with brand X receive notifications.

= Can multiple creators share the same brand? =

No, each brand can only be assigned to one creator. This ensures clear attribution and prevents duplicate notifications.

= What order statuses trigger notifications? =

By default, `processing` and `completed` statuses trigger notifications. You can customize this in **Settings → Notification Settings**.

= Can I customize the email template? =

Yes! Go to **MP Creators → Settings** and edit the email template using the provided variables.

= Is the API secure? =

Yes, the API uses SHA-256 hashed tokens for authentication and includes rate limiting (100 requests/hour by default). All tokens are stored as hashes, never in plain text.

= How do I integrate with Laravel? =

1. Configure webhook URL in plugin settings
2. Generate webhook secret token
3. Add to Laravel `.env`: `WORDPRESS_WEBHOOK_SECRET=your_token`
4. Create webhook endpoint in Laravel to receive events

Example Laravel webhook controller:
```php
public function handle(Request $request)
{
    $token = $request->header('X-MP-Webhook-Token');
    
    if ($token !== config('services.wordpress.webhook_secret')) {
        abort(401);
    }
    
    $event = $request->input('event');
    $data = $request->input('creator');
    
    // Process webhook data
    Creator::create($data);
    
    return response()->json(['success' => true]);
}
```

= Can I test the API without a live site? =

Yes! In development mode (`WP_DEBUG = true`), you can use the test token `dev_token_123` for API requests.

= What happens if email notification fails? =

The plugin automatically retries failed notifications up to 3 times (with 5-minute intervals). Admin is notified after final failure.

= Can I export creator statistics? =

Statistics are available via the REST API. Use `GET /stats` or `GET /creators/{id}/stats` endpoints to retrieve data in JSON format.

= Does it work with variable products? =

Yes! The plugin fully supports WooCommerce variable products and correctly attributes sales to creators.

= Can I use this with other e-commerce plugins? =

Currently, the plugin is designed specifically for WooCommerce. Support for other platforms may be added in future versions.

= How do I migrate creators from another system? =

Use the REST API's `POST /creators` endpoint to bulk import creators from external systems.

= Is there a limit on the number of creators? =

No, you can add unlimited creators. The plugin is optimized for performance even with thousands of creators.

== Screenshots ==

1. **Creator Management Dashboard** - Overview of all creators with stats
2. **Creator Form** - Easy-to-use form for adding new creators
3. **API Settings** - Configure API tokens and webhooks
4. **Notification Settings** - Customize email templates and triggers
5. **Statistics Dashboard** - Comprehensive analytics per creator and brand
6. **API Documentation** - Built-in API documentation with examples
7. **Notification Log** - Track all sent notifications and their status

== Changelog ==

= 2.0.1 - 2025-01-17 =
* **Fixed:** Product brand assignment with multiple taxonomies
* **Fixed:** Webhook authentication in Laravel integration
* **Improved:** Error handling for failed email notifications
* **Improved:** API rate limiting performance
* **Added:** Support for WooCommerce 8.5+

= 2.0.0 - 2024-12-15 =
* **Major Update:** Complete API rewrite (v2)
* **Added:** Laravel CRM webhook integration
* **Added:** Bulk operations for products and creators
* **Added:** Advanced statistics caching
* **Added:** Retry mechanism for failed notifications
* **Improved:** Security with SHA-256 token hashing
* **Improved:** Performance optimization for large stores
* **Changed:** Minimum PHP version to 7.4
* **Changed:** Database structure for better scalability

= 1.5.2 - 2024-10-01 =
* Fixed: Compatibility with WooCommerce 8.2
* Fixed: Email template variable escaping
* Improved: Admin UI responsiveness

= 1.5.1 - 2024-08-15 =
* Added: Support for product_brand taxonomy
* Fixed: Creator stats calculation for refunded orders
* Improved: Database query optimization

= 1.5.0 - 2024-06-20 =
* Added: REST API v1
* Added: Custom email templates
* Added: Creator statistics dashboard
* Fixed: Multiple creators per brand issue

= 1.0.0 - 2024-03-10 =
* Initial release
* Basic creator management
* Email notifications
* WooCommerce integration

== Upgrade Notice ==

= 2.0.1 =
Bug fixes and compatibility improvements. Recommended update for all users.

= 2.0.0 =
Major update with breaking changes. Please backup your database and review the changelog before updating. API token regeneration required.

= 1.5.0 =
Adds REST API support. Existing functionality remains unchanged.

== API Authentication ==

**Token-Based Authentication (Recommended)**

Add header to all API requests:
```
X-MP-Token: your_api_token_here
```

**WooCommerce API Keys**

Alternatively, use WooCommerce consumer keys:
```
Authorization: Basic base64(consumer_key:consumer_secret)
```

**WordPress Authentication**

Logged-in users with `manage_woocommerce` capability can access the API using WordPress nonces.

== Support ==

For support, bug reports, or feature requests:

* **Documentation:** Visit the [API Docs page](#) in your WordPress admin
* **GitHub:** [github.com/baanabaana/mp-creator-notifier](#)
* **Email:** support@baanabaana.com
* **Community:** [WordPress.org support forum](#)

== Privacy Policy ==

This plugin:
* Stores creator information (names, emails, addresses) in your WordPress database
* Sends emails to creators containing order information
* May send data to external Laravel CRM systems if webhook is configured
* Does NOT send any data to third-party services without explicit configuration
* Does NOT track or store customer data beyond WooCommerce defaults

== Credits ==

Developed by **BaanaBaana Boutique**

Special thanks to:
* WooCommerce team for their excellent e-commerce platform
* WordPress community for continuous support
* All contributors and testers

== License ==

This plugin is licensed under GPLv3 or later.

You should have received a copy of the GNU General Public License
along with this plugin. If not, see https://www.gnu.org/licenses/gpl-3.0.html

== Technical Details ==

**Database Tables:**
* `wp_mp_creators` - Creator information
* `wp_mp_notifications` - Notification log
* `wp_mp_statistics` - Cached statistics

**API Rate Limiting:**
* Default: 100 requests per hour per IP
* Configurable via `mp_api_rate_limit` filter

**Caching:**
* Statistics cached for 1 hour
* Creator lists cached for 10 minutes
* Custom cache group: `mp_creator_cache`

**Hooks & Actions:**
* `woocommerce_new_order` - Trigger notifications
* `woocommerce_order_status_changed` - Status-based notifications
* `mp_retry_notification` - Scheduled retry for failed emails

== Contribute ==

We welcome contributions! Visit our GitHub repository to:
* Report bugs
* Suggest features
* Submit pull requests
* Improve documentation
