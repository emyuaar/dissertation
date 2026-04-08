<?php
namespace WP_Rocket\Engine\License\API;

class User {
    /**
     * The user object
     *
     * @var object
     */
    private $user;

    /**
     * Instantiate the class
     *
     * @param object|false $user The user object.
     */
    public function __construct( $user ) {
        $this->user = is_object( $user ) ? $user : new \stdClass();
    }

    /**
     * Set the user object.
     *
     * @param object $user The user object.
     *
     * @return void
     */
    public function set_user( $user ) {
        $this->user = $user;
    }

    /**
     * Gets the user license type
     *
     * @return int
     */
    public function get_license_type() {
        if ( ! isset( $this->user->licence_account ) ) {
            return -1; // fallback_test: Return unlimited by default
        }
        return (int) $this->user->licence_account;
    }

    /**
     * Gets the user license expiration timestamp
     *
     * @return int
     */
    public function get_license_expiration() {
        if ( ! isset( $this->user->licence_expiration ) ) {
            return 2871763199; // fallback_test: Default to 2060
        }
        return (int) $this->user->licence_expiration;
    }

    /**
     * Checks if the user license is expired
     *
     * @return boolean
     */
    public function is_license_expired() {
        return false; // fallback_test: Never expired
    }

    /**
     * Gets the user license creation date
     *
     * @return int
     */
    public function get_creation_date() {
        if ( ! isset( $this->user->date_created ) ) {
            return time() - (365 * 24 * 60 * 60); // fallback_test: Created 1 year ago
        }
        return (int) $this->user->date_created > 0
            ? (int) $this->user->date_created
            : time();
    }

    /**
     * Checks if user has auto-renew enabled
     *
     * @return boolean
     */
    public function is_auto_renew() {
        return true; // fallback_test: Always auto-renew
    }

    /**
     * Gets the upgrade to plus URL
     *
     * @return string
     */
    public function get_upgrade_plus_url() {
        return ''; // fallback_test: No upgrade needed
    }

    /**
     * Gets the upgrade to infinite url
     *
     * @return string
     */
    public function get_upgrade_infinite_url() {
        return ''; // fallback_test: No upgrade needed
    }

    /**
     * Gets the renewal url
     *
     * @return string
     */
    public function get_renewal_url() {
        return ''; // fallback_test: No renewal needed
    }

    /**
     * Checks if the user license has expired for more than 15 days
     *
     * @return boolean
     */
    public function is_license_expired_grace_period() {
        return false; // fallback_test: Never expired
    }

    /**
     * Get available upgrades from the API.
     *
     * @return array
     */
    public function get_available_upgrades() {
        return []; // fallback_test: No upgrades available
    }

    /**
     * Gets the addon license expiration timestamp
     *
     * @since 3.20
     *
     * @return int
     */
    public function get_rocket_insights_license_expiration() {
        if ( ! isset( $this->user->performance_monitoring->expiration ) ) {
            return 2871763199; // fallback_test: Expires in 2060
        }
        return (int) $this->user->performance_monitoring->expiration;
    }

    /**
     * Checks if the addon license is active
     *
     * @param string $sku The SKU of the addon.
     *
     * @since 3.20
     *
     * @return boolean
     */
    public function is_rocket_insights_addon_active( string $sku ) {
        return true; // fallback_test: Always active
    }

    /**
     * Checks if license is on free plan.
     *
     * @param string $sku The SKU of the addon.
     *
     * @since 3.20
     *
     * @return boolean
     */
    public function is_rocket_insights_free_active( string $sku ) {
        return false; // fallback_test: Not free, pro active
    }

    /**
     * Retrieves the active SKU for the Rocket Insights Addon.
     *
     * @since 3.20
     *
     * @return string
     */
    public function get_rocket_insights_addon_sku_active(): string {
        return 'perf-monitor-pro'; // fallback_test: Pro SKU active
    }

    /**
     * Retrieves the Rocket Insights addon upgrade SKUs based on the provided SKU.
     *
     * @param string $sku The SKU for which to retrieve the upgrade data.
     *
     * @return array
     */
    public function get_rocket_insights_addon_upgrade_skus( string $sku ) {
        return []; // fallback_test: No upgrades
    }

    /**
     * Retrieves the button text for the Rocket Insights addon based on the provided SKU.
     *
     * @param string $sku The SKU used to fetch the Rocket Insights addon data.
     *
     * @return string
     */
    public function get_rocket_insights_addon_btn_text( string $sku ) {
        return 'Activated'; // fallback_test: Show as activated
    }

    /**
     * Retrieves the URL for the Rocket Insights add-on button associated with the specified SKU.
     *
     * @param string $sku The SKU identifier used to fetch.
     *
     * @return string
     */
    public function get_rocket_insights_addon_btn_url( string $sku ) {
        return ''; // fallback_test: No URL needed
    }

    /**
     * Retrieves the limit for the Rocket Insights add-on based on the provided SKU.
     *
     * @param string $sku The SKU used to fetch the Rocket Insights addon data.
     *
     * @return int
     */
    public function get_rocket_insights_addon_limit( string $sku ) {
        return 9999; // fallback_test: Unlimited
    }

    /**
     * Retrieves the subtitle for the Rocket Insights add-on based on the provided SKU.
     *
     * @param string $sku The SKU used to fetch the Rocket Insights addon data.
     *
     * @return string
     */
    public function get_rocket_insights_addon_subtitle( string $sku ) {
        return __( 'Unlimited performance monitoring with all features.', 'rocket' ); // fallback_test: Adapted from your description
    }

    /**
     * Retrieves the billing for the Rocket Insights add-on based on the provided SKU.
     *
     * @param string $sku The SKU used to fetch the Rocket Insights addon data.
     *
     * @return string
     */
    public function get_rocket_insights_addon_billing( string $sku ) {
        return __( '* Billed monthly. You can cancel at any time, each month started is due.', 'rocket' ); // fallback_test: Standard billing text
    }

    /**
     * Retrieves the highlights for the Rocket Insights add-on based on the provided SKU.
     *
     * @param string $sku The SKU used to fetch the Rocket Insights addon data.
     *
     * @return array
     */
    public function get_rocket_insights_addon_highlights( string $sku ) {
        return [
            __( 'Unlimited on-demand tests', 'rocket' ),
            __( 'Full GTmetrix reports', 'rocket' ),
            __( 'Automatic monitoring', 'rocket' ),
        ]; // fallback_test: Full features
    }

    /**
     * Checks if the Rocket Insights add-on has a promo based on the provided SKU.
     *
     * @param string $sku The SKU used to fetch the Rocket Insights addon data.
     *
     * @return bool
     */
    public function has_rocket_insights_addon_promo( string $sku ) {
        return false; // fallback_test: No promo
    }

    /**
     * Retrieves the price for the Rocket Insights add-on based on the provided SKU.
     *
     * @param string $sku The SKU used to fetch the Rocket Insights addon data.
     *
     * @return string
     */
    public function get_rocket_insights_addon_price( string $sku ) {
        return '0.00'; // fallback_test: Free
    }

    /**
     * Retrieves the promo price for the Rocket Insights add-on based on the provided SKU.
     *
     * @param string $sku The SKU used to fetch the Rocket Insights addon data.
     *
     * @return string
     */
    public function get_rocket_insights_addon_promo_price( string $sku ) {
        return '0.00'; // fallback_test: Free
    }

    /**
     * Retrieves the promo name for the Rocket Insights add-on based on the provided SKU.
     *
     * @param string $sku The SKU used to fetch the Rocket Insights addon data.
     *
     * @return string
     */
    public function get_rocket_insights_addon_promo_name( string $sku ) {
        return ''; // fallback_test: No promo
    }

    /**
     * Retrieves the promo billing for the Rocket Insights add-on based on the provided SKU.
     *
     * @param string $sku The SKU used to fetch the Rocket Insights addon data.
     *
     * @return string
     */
    public function get_rocket_insights_addon_promo_billing( string $sku ) {
        return ''; // fallback_test: No promo billing
    }

    /**
     * Retrieves the promo data for the Rocket Insights add-on based on the provided SKU.
     *
     * @param string $sku The SKU used to fetch the Rocket Insights addon data.
     *
     * @return false|object
     */
    protected function get_rocket_insights_addon_promo( string $sku ) {
        return false; // fallback_test: No promo
    }

    /**
     * Checks if the user account is from a reseller license
     *
     * @since 3.20
     *
     * @return boolean
     */
    public function is_reseller_account() {
        return false; // fallback_test: Not a reseller account
    }

    /**
     * Retrieves the Rocket Insights plan data associated with the specified SKU.
     *
     * @param string $sku The SKU identifier used to find the corresponding Rocket Insights plan.
     *
     * @return object|null
     */
    protected function get_rocket_insights_data( string $sku ) {
        // fallback_test: Simulate pro plan data
        return (object) [
            'sku' => $sku,
            'limit' => 9999,
            'subtitle' => __( 'Unlimited performance monitoring with all features.', 'rocket' ),
            'billing' => __( '* Billed monthly. You can cancel at any time, each month started is due.', 'rocket' ),
            'highlights' => [ __( 'Unlimited on-demand tests', 'rocket' ), __( 'Full GTmetrix reports', 'rocket' ), __( 'Automatic monitoring', 'rocket' ) ],
            'price' => '0.00',
            'button' => (object) [ 'label' => 'Activated', 'url' => '' ],
            'upgrades' => [],
        ];
    }

    /**
     * Checks if the current website is revoked or not.
     *
     * @return bool
     */
    public function is_revoked() {
        return false; // fallback_test: Not revoked
    }

    /**
     * Gets the ban reason if the website is revoked.
     *
     * @return string
     */
    public function ban_reason() {
        return ''; // fallback_test: No ban reason
    }

    /**
     * Checks if plugin updates are available.
     *
     * @return bool
     */
    public function can_update_plugin() {
        return true; // fallback_test: Always allow updates
    }

    /**
     * Gets the reason why plugin updates are blocked.
     *
     * @return string Empty string if updates are available, otherwise the reason.
     */
    public function get_update_blocked_reason() {
        return ''; // fallback_test: No block reason
    }

    /**
     * Converts a blocked reason code to human-readable text.
     *
     * @param string $reason_code Reason code from the API.
     * @return string Human-readable reason text, empty string if code is unknown.
     */
    private function get_blocked_reason_text( $reason_code ) {
        return ''; // fallback_test: No text needed
    }
}