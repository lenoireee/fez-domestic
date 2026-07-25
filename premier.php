<?php
/**
 * Plugin Name: Premier Dispatch Domestic
 * Description: v3.0 — Nigerian shipping with cached rates and shared Fez auth layer.
 * Version:     3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Load shared Fez auth/logging/HTTP layer.
// function_exists guards inside pd-shared.php mean whichever plugin loads
// first defines the functions — the second plugin safely skips them.
require_once plugin_dir_path( __FILE__ ) . 'pd-shared.php';


// ---------------------------------------------------------------------------
// DOMESTIC PRICE CACHE HELPERS
// Keyed by state name so every Nigerian state gets its own cached price.
// TTL is 7 days — freshness controlled by cached_at timestamp, not expiry.
// ---------------------------------------------------------------------------
function pdd_price_key( $state ) {
    return 'pdd_price_' . sanitize_key( strtolower( $state ) ) . '_' . get_current_blog_id();
}

function pdd_write_price( $state, $price ) {
    set_transient( pdd_price_key( $state ), [
        'price'     => (float) $price,
        'cached_at' => time(),
        'state'     => $state,
    ], 7 * DAY_IN_SECONDS );
}

function pdd_key( $key ) {
    return $key . '_' . get_current_blog_id();
}


// ---------------------------------------------------------------------------
// DOMESTIC SHIPPING METHOD
// ---------------------------------------------------------------------------
function pd_domestic_init_v30() {
    if ( ! class_exists( 'WC_Shipping_Method' ) ) return;

    class WC_Shipping_PD_Domestic extends WC_Shipping_Method {

        public function __construct( $instance_id = 0 ) {
            $this->id                 = 'pd_domestic';
            $this->instance_id        = absint( $instance_id );
            $this->method_title       = 'Premier Dispatch (Domestic)';
            $this->method_description = 'Dynamic domestic rates via Fez Delivery.';
            $this->supports           = [ 'shipping-zones', 'instance-settings', 'settings' ];
            $this->init();
        }

        public function init() {
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option( 'title', 'Standard Delivery' );

            add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
            add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'on_settings_saved' ] );

            // Manual sync — admin only, nonce-protected, runs inline (states API is fast)
            if ( is_admin() && isset( $_POST['pd_dom_manual_sync'] ) ) {
                if ( check_admin_referer( 'pd_dom_sync_action', 'pd_dom_sync_nonce' ) ) {
                    $result = $this->sync_states();
                    $msg    = $result
                        ? 'States synced successfully — ' . count( get_option( pdd_key( 'pd_domestic_states' ), [] ) ) . ' states cached.'
                        : 'Sync failed. Check your error log.';
                    $type   = $result ? 'success' : 'error';
                    add_action( 'admin_notices', function() use ( $msg, $type ) {
                        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>'
                            . '<strong>Premier Dispatch Domestic:</strong> ' . esc_html( $msg ) . '</p></div>';
                    } );
                }
            }
        }

        // -----------------------------------------------------------------------
        // SETTINGS SAVED
        // -----------------------------------------------------------------------
        public function on_settings_saved() {
            $new_hours = (int) $this->get_option( 'cache_hours' ) ?: 24;
            $prev_key  = pdd_key( 'pdd_prev_cache_hours' );
            $old_hours = (int) get_option( $prev_key );

            if ( $new_hours !== $old_hours ) {
                $this->flush_price_cache();
                update_option( $prev_key, $new_hours, false );
            }

            $this->reschedule_sync();
        }

        // -----------------------------------------------------------------------
        // FORM FIELDS
        // -----------------------------------------------------------------------
        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title'   => 'Enable',
                    'type'    => 'checkbox',
                    'default' => 'yes',
                ],
                'title' => [
                    'title'   => 'Method Title',
                    'type'    => 'text',
                    'default' => 'Standard Delivery',
                ],
                'extra_expense' => [
                    'title'             => 'Handling Fee (NGN)',
                    'type'              => 'number',
                    'default'           => '4500',
                    'custom_attributes' => [ 'step' => '1', 'min' => '0' ],
                ],
                'shipping_exchange_rate' => [
                    'title'             => 'Exchange Rate (NGN per 1 store unit)',
                    'type'              => 'number',
                    'default'           => '1300',
                    'description'       => 'Applied live at checkout.',
                    'custom_attributes' => [ 'step' => '1', 'min' => '1' ],
                ],
                'stale_multiplier' => [
                    'title'             => 'Stale Price Multiplier',
                    'type'              => 'number',
                    'default'           => '1.15',
                    'description'       => 'Applied when cached price is stale and API refresh fails. E.g. 1.15 = +15% buffer.',
                    'custom_attributes' => [ 'step' => '0.01', 'min' => '1.00' ],
                ],
                'cache_hours' => [
                    'title'             => 'Price Cache Duration (Hours)',
                    'type'              => 'number',
                    'default'           => '24',
                    'description'       => 'How long a fetched price is considered fresh. Changing this flushes all cached prices.',
                    'custom_attributes' => [ 'step' => '1', 'min' => '1' ],
                ],
                'sync_interval_hours' => [
                    'title'             => 'State List Sync Interval (Hours)',
                    'type'              => 'number',
                    'default'           => '168',
                    'description'       => 'How often to refresh the list of supported Nigerian states from Fez.',
                    'custom_attributes' => [ 'step' => '1', 'min' => '1' ],
                ],
                'sync_button' => [
                    'title' => 'Status',
                    'type'  => 'pdd_sync_button',
                ],
            ];
        }

        // -----------------------------------------------------------------------
        // ADMIN STATUS PANEL
        // -----------------------------------------------------------------------
        public function generate_pdd_sync_button_html() {
            $states      = get_option( pdd_key( 'pd_domestic_states' ), [] );
            $last_sync   = get_option( pdd_key( 'pd_dom_last_sync' ), null );
            $next_sync   = wp_next_scheduled( 'pdd_sync_event' );
            $last_error  = get_option( 'pd_last_error_' . get_current_blog_id(), null );
            $stale_count = (int) get_option( pdd_key( 'pdd_stale_count' ), 0 );
            $stale_last  = get_option( pdd_key( 'pdd_stale_last' ), null );

            $cache_h  = (int) $this->get_option( 'cache_hours' ) ?: 24;
            $sync_h   = (int) $this->get_option( 'sync_interval_hours' ) ?: 168;

            ob_start(); ?>
            <tr valign="top">
                <th scope="row" class="titledesc">Status</th>
                <td class="forminp">
                    <?php wp_nonce_field( 'pd_dom_sync_action', 'pd_dom_sync_nonce' ); ?>
                    <button name="pd_dom_manual_sync" type="submit" class="button-secondary" value="1">
                        Sync States Now
                    </button>

                    <p class="description">
                        <?php if ( ! empty( $states ) ): ?>
                            &#9989; <strong><?php echo count( $states ); ?> states cached</strong>
                            <?php if ( $last_sync ): ?>
                                , last synced <?php echo esc_html( human_time_diff( $last_sync ) ); ?> ago
                            <?php endif; ?>
                        <?php else: ?>
                            &#9888; <strong>No states cached.</strong> Click Sync States Now.
                        <?php endif; ?>
                        &nbsp;|&nbsp; Next sync:
                        <strong><?php echo $next_sync ? esc_html( human_time_diff( time(), $next_sync ) . ' from now' ) : '&#10060; not scheduled — re-save settings'; ?></strong>
                    </p>

                    <?php if ( $stale_count > 0 ): ?>
                    <p class="description" style="color:#b32d2e;">
                        &#9888; <strong>Stale multiplier applied <?php echo (int) $stale_count; ?> time(s).</strong>
                        <?php if ( $stale_last ): ?>
                            Last: <strong><?php echo esc_html( $stale_last['state'] ); ?></strong>,
                            <?php echo esc_html( human_time_diff( $stale_last['at'] ) ); ?> ago.
                        <?php endif; ?>
                        <a href="<?php echo esc_url( add_query_arg( 'pdd_reset_stale', '1' ) ); ?>">Reset</a>
                    </p>
                    <?php endif; ?>

                    <?php if ( $last_error && ( time() - $last_error['at'] ) < DAY_IN_SECONDS ): ?>
                    <p class="description" style="color:#b32d2e;">
                        &#10060; <strong>Last error (<?php echo esc_html( $last_error['context'] ); ?>):</strong>
                        <?php echo esc_html( $last_error['message'] ); ?>
                        &mdash; <?php echo esc_html( human_time_diff( $last_error['at'] ) ); ?> ago.
                    </p>
                    <?php endif; ?>

                    <p class="description" style="color:#555;margin-top:6px;">
                        Server cron for reliable scheduling:<br>
                        <code>0 */<?php echo esc_html( $sync_h ); ?> * * * wget -q -O /dev/null "<?php echo esc_url( site_url( '/wp-cron.php?doing_wp_cron' ) ); ?>"</code>
                    </p>
                </td>
            </tr>
            <?php
            return ob_get_clean();
        }

        // -----------------------------------------------------------------------
        // CALCULATE SHIPPING
        //
        // Price flow:
        //   Fresh cache (age < cache_hours)    → return immediately, zero API calls
        //   Stale cache (age >= cache_hours)   → live refresh
        //       success                        → cache + return new price
        //       fail                           → return stale * stale_multiplier
        //   Cache miss                         → live fetch
        //       success                        → cache + return price
        //       fail                           → retry once (300ms pause)
        //           success                    → cache + return price
        //           fail                       → show checkout notice
        // -----------------------------------------------------------------------
        public function calculate_shipping( $package = [] ) {
            if ( $this->get_option( 'enabled' ) !== 'yes' ) return;
            if ( ( $package['destination']['country'] ?? '' ) !== 'NG' ) return;

            $state_code = $package['destination']['state'] ?? '';
            if ( ! $state_code ) return;

            $target_state = $this->resolve_state_name( $state_code );
            if ( ! $target_state ) return;

            $naira_price = $this->resolve_price( $target_state );

            if ( $naira_price === null ) {
                $this->add_checkout_notice(
                    __( 'We\'re unable to load shipping options for your location at the moment. Please try again in a few minutes.', 'pd-domestic' )
                );
                return;
            }

            $total_ngn  = ceil( ( $naira_price + (float) $this->get_option( 'extra_expense' ) ) / 100 ) * 100;
            $exchange   = (float) $this->get_option( 'shipping_exchange_rate' ) ?: 1300;
            $cost       = $total_ngn / $exchange;

            $this->add_rate( [
                'id'        => $this->get_rate_id(),
                'label'     => $this->title,
                'cost'      => $cost,
                'meta_data' => [
                    'pd_raw_naira' => $total_ngn,
                    'pd_state'     => $target_state,
                ],
            ] );
        }

        // -----------------------------------------------------------------------
        // STATE NAME RESOLUTION
        // Converts WooCommerce state code to Fez-compatible state name.
        // -----------------------------------------------------------------------
        private function resolve_state_name( $state_code ) {
            $wc_states = WC()->countries->get_states( 'NG' );
            $wc_name   = $wc_states[ $state_code ] ?? $state_code;
            $clean     = trim( str_ireplace( ' State', '', $wc_name ) );

            // FCT / Abuja — Fez uses 'FCT'
            if ( preg_match( '/abuja|federal capital/i', $clean ) ) {
                return 'FCT';
            }

            return $clean ?: null;
        }

        // -----------------------------------------------------------------------
        // PRICE RESOLUTION
        // -----------------------------------------------------------------------
        private function resolve_price( $state ) {
            $cache_hours = (int) $this->get_option( 'cache_hours' ) ?: 24;
            $price_key   = pdd_price_key( $state );
            $cached      = get_transient( $price_key );
            $now         = time();

            if ( $cached && isset( $cached['price'], $cached['cached_at'] ) ) {
                $age      = $now - $cached['cached_at'];
                $is_fresh = $age < ( $cache_hours * HOUR_IN_SECONDS );

                if ( $is_fresh ) {
                    // Fresh — return immediately, zero API calls
                    return $cached['price'];
                }

                // Stale — attempt live refresh
                $fresh = $this->fetch_price( $state );
                if ( $fresh !== false ) {
                    return $fresh;
                }

                // Refresh failed — serve stale with multiplier
                $multiplier = (float) $this->get_option( 'stale_multiplier' ) ?: 1.15;
                pd_log( 'FETCH', 'WARN', 'Domestic stale price served with multiplier', [
                    'state'      => $state,
                    'age_hours'  => round( $age / 3600, 2 ),
                    'multiplier' => $multiplier,
                ] );
                $this->record_stale( $state );
                return $cached['price'] * $multiplier;
            }

            // Cache miss — live fetch
            $fresh = $this->fetch_price( $state );
            if ( $fresh !== false ) return $fresh;

            // Retry once after short pause
            usleep( 300000 ); // 0.3s
            $retry = $this->fetch_price( $state );
            if ( $retry !== false ) return $retry;

            pd_log( 'FETCH', 'ERROR', 'All domestic price fetch attempts failed', [ 'state' => $state ] );
            return null;
        }

        // -----------------------------------------------------------------------
        // LIVE PRICE FETCH
        // Uses pd_api_request (shared layer) — handles 401 retry automatically.
        // -----------------------------------------------------------------------
        private function fetch_price( $state ) {
            $res = pd_api_request( 'POST', FEZ_API_BASE . '/order/cost', [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'state' => $state ] ),
                'timeout' => 10,
            ] );

            if ( is_wp_error( $res ) ) {
                pd_log( 'FETCH', 'ERROR', 'Domestic price fetch failed', [
                    'state' => $state,
                    'error' => $res->get_error_message(),
                ] );
                delete_transient( PD_SHARED_AUTH_KEY ); // clear token — may be auth-related
                return false;
            }

            $http_code = (int) wp_remote_retrieve_response_code( $res );
            $data      = json_decode( wp_remote_retrieve_body( $res ), true );
            $price     = ! empty( $data['totalCost'] ) ? (float) $data['totalCost'] : null;

            if ( ! $price ) {
                pd_log( 'FETCH', 'ERROR', 'Domestic price fetch returned empty', [
                    'state'     => $state,
                    'http_code' => $http_code,
                    'body'      => $data,
                ] );
                if ( $http_code === 401 || $http_code === 403 ) {
                    delete_transient( PD_SHARED_AUTH_KEY );
                }
                return false;
            }

            pdd_write_price( $state, $price );
            return $price;
        }

        // -----------------------------------------------------------------------
        // STATE LIST SYNC
        // Fetches supported Nigerian states from Fez API.
        // Runs on schedule and on manual trigger.
        // Fast — one API call.
        // -----------------------------------------------------------------------
        public function sync_states() {
            $res = pd_api_request( 'GET', FEZ_API_BASE . '/states', [
                'timeout' => 20,
            ] );

            if ( is_wp_error( $res ) ) {
                pd_log( 'SYNC', 'ERROR', 'Domestic states sync failed', [ 'error' => $res->get_error_message() ] );
                return false;
            }

            $body   = json_decode( wp_remote_retrieve_body( $res ), true );
            $states = $body['states'] ?? [];

            if ( empty( $states ) ) {
                pd_log( 'SYNC', 'ERROR', 'Domestic states sync returned empty' );
                return false;
            }

            update_option( pdd_key( 'pd_domestic_states' ), $states, false );
            update_option( pdd_key( 'pd_dom_last_sync' ), time(), false );
            pd_log( 'SYNC', 'INFO', 'Domestic states synced', [ 'count' => count( $states ) ] );
            return true;
        }

        // -----------------------------------------------------------------------
        // PRICE CACHE FLUSH
        // Called when cache_hours changes.
        // -----------------------------------------------------------------------
        public function flush_price_cache() {
            global $wpdb;
            $blog_id = get_current_blog_id();
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_pdd_price_' ) . '%_' . $blog_id,
                $wpdb->esc_like( '_transient_timeout_pdd_price_' ) . '%_' . $blog_id
            ) );
            pd_log( 'SYNC', 'INFO', 'Domestic price cache flushed' );
        }

        // -----------------------------------------------------------------------
        // CHECKOUT NOTICE — checkout page only
        // -----------------------------------------------------------------------
        private function add_checkout_notice( $message ) {
            if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
            if ( ! function_exists( 'wc_add_notice' ) ) return;

            $existing = wc_get_notices( 'error' );
            foreach ( $existing as $notice ) {
                $text = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : $notice;
                if ( strpos( $text, 'unable to load shipping options' ) !== false ) return;
            }
            wc_add_notice( $message, 'error' );
        }

        private function record_stale( $state ) {
            $key = pdd_key( 'pdd_stale_count' );
            update_option( $key, ( (int) get_option( $key, 0 ) ) + 1, false );
            update_option( pdd_key( 'pdd_stale_last' ), [ 'state' => $state, 'at' => time() ], false );
        }

        public function reschedule_sync() {
            $hours = (int) $this->get_option( 'sync_interval_hours' ) ?: 168;
            wp_clear_scheduled_hook( 'pdd_sync_event' );
            wp_schedule_event( time(), 'pdd_custom_interval', 'pdd_sync_event' );
            update_option( 'pdd_sync_interval_seconds', $hours * HOUR_IN_SECONDS, false );
        }
    }
}

add_action( 'woocommerce_shipping_init', 'pd_domestic_init_v30' );

add_filter( 'woocommerce_shipping_methods', function( $methods ) {
    $methods['pd_domestic'] = 'WC_Shipping_PD_Domestic';
    return $methods;
} );


// ---------------------------------------------------------------------------
// CUSTOM CRON INTERVAL
// Reads saved interval seconds from DB so it survives plugin reload.
// ---------------------------------------------------------------------------
add_filter( 'cron_schedules', function( $schedules ) {
    $seconds = (int) get_option( 'pdd_sync_interval_seconds', 168 * HOUR_IN_SECONDS );
    $schedules['pdd_custom_interval'] = [
        'interval' => max( HOUR_IN_SECONDS, $seconds ), // minimum 1 hour
        'display'  => 'Premier Dispatch Domestic Sync',
    ];
    return $schedules;
} );


// ---------------------------------------------------------------------------
// SYNC CRON HANDLER
// ---------------------------------------------------------------------------
add_action( 'pdd_sync_event', function() {
    if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Shipping_PD_Domestic' ) ) return;
    ( new WC_Shipping_PD_Domestic() )->sync_states();
} );


// ---------------------------------------------------------------------------
// ACTIVATION / DEACTIVATION
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, function() {
    if ( ! wp_next_scheduled( 'pdd_sync_event' ) ) {
        update_option( 'pdd_sync_interval_seconds', 168 * HOUR_IN_SECONDS, false );
        wp_schedule_event( time(), 'pdd_custom_interval', 'pdd_sync_event' );
    }
} );

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'pdd_sync_event' );
} );


// ---------------------------------------------------------------------------
// STALE COUNTER RESET
// ---------------------------------------------------------------------------
add_action( 'admin_init', function() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! isset( $_GET['pdd_reset_stale'] ) ) return;
    delete_option( pdd_key( 'pdd_stale_count' ) );
    delete_option( pdd_key( 'pdd_stale_last' ) );
    wp_safe_redirect( remove_query_arg( 'pdd_reset_stale' ) );
    exit;
} );


// ---------------------------------------------------------------------------
// CURRENCY FILTER — swap to raw NGN when store currency is NGN
// ---------------------------------------------------------------------------
add_filter( 'woocommerce_shipping_rate_cost', function( $cost, $rate ) {
    if ( get_woocommerce_currency() !== 'NGN' ) return $cost;
    if ( strpos( $rate->get_id(), 'pd_domestic' ) === false ) return $cost;

    $meta = $rate->get_meta_data();
    if ( ! isset( $meta['pd_raw_naira'] ) ) return $cost;
    if ( (float) $cost === (float) $meta['pd_raw_naira'] ) return $cost;

    return $meta['pd_raw_naira'];
}, 20, 2 );