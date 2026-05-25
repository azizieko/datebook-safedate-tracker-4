<?php
if (!defined('ABSPATH')) exit;

class DBSD_Shortcodes {
    public static function init() {
        add_shortcode('db_safedate_dashboard', array(__CLASS__, 'dashboard'));
        add_shortcode('db_safedate_create', array(__CLASS__, 'create_form'));
        add_shortcode('db_safedate_session', array(__CLASS__, 'session_panel'));
        add_shortcode('db_safedate_contacts', array(__CLASS__, 'contacts_panel'));
        add_shortcode('db_safedate_notifications', array(__CLASS__, 'notifications_panel'));
        // v0.3 shortcodes are registered by DBSD_V030: trusted_share, incident_report, map.
    }

    private static function enqueue() { wp_enqueue_style('dbsd-frontend'); wp_enqueue_script('dbsd-frontend'); }

    public static function create_form() {
        if (!is_user_logged_in()) return '<p>Please log in to create a SafeDate session.</p>';
        self::enqueue();
        ob_start(); ?>
        <div class="dbsd-card" data-dbsd-create>
            <h3>Create SafeDate Session</h3>
            <p class="dbsd-muted">Both parties must consent before tracking can start. Use user IDs from DateBook/WordPress profiles.</p>
            <label>Host user ID <input type="number" data-field="host_user_id" value="<?php echo esc_attr(get_current_user_id()); ?>"></label>
            <label>Traveler user ID <input type="number" data-field="traveler_user_id"></label>
            <label>Meeting address <textarea data-field="meeting_address" rows="2"></textarea></label>
            <div class="dbsd-grid-2">
                <label>Meeting latitude <input type="number" step="0.0000001" data-field="meeting_lat"></label>
                <label>Meeting longitude <input type="number" step="0.0000001" data-field="meeting_lng"></label>
            </div>
            <div class="dbsd-grid-2">
                <label>Planned start <input type="datetime-local" data-field="planned_start_at"></label>
                <label>Planned end <input type="datetime-local" data-field="planned_end_at"></label>
                <label>Expected arrival <input type="datetime-local" data-field="expected_arrival_at"></label>
                <label>Expected departure <input type="datetime-local" data-field="expected_departure_at"></label>
            </div>
            <button type="button" class="dbsd-btn" data-action="create-session">Create Session</button>
            <div class="dbsd-result"></div>
        </div>
        <?php return ob_get_clean();
    }

    public static function dashboard() {
        if (!is_user_logged_in()) return '<p>Please log in to view SafeDate sessions.</p>';
        global $wpdb;
        self::enqueue();
        $uid = get_current_user_id();
        $sessions = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dbsd_sessions WHERE host_user_id=%d OR traveler_user_id=%d ORDER BY id DESC LIMIT 20", $uid, $uid));
        ob_start(); ?>
        <div class="dbsd-card" data-dbsd-dashboard>
            <h3>My SafeDate Sessions</h3>
            <div class="dbsd-notifications" data-dbsd-notifications>Loading notifications...</div>
            <?php if (!$sessions): ?>
                <p>No SafeDate sessions yet.</p>
            <?php else: ?>
                <div class="dbsd-session-list">
                    <?php foreach ($sessions as $s): ?>
                        <div class="dbsd-session-row dbsd-alert-<?php echo esc_attr($s->alert_level); ?>">
                            <strong>#<?php echo esc_html($s->id); ?></strong>
                            <span>Status: <?php echo esc_html($s->status); ?></span>
                            <span>Alert: <?php echo esc_html($s->alert_level); ?></span>
                            <span>Host: <?php echo esc_html($s->host_user_id); ?></span>
                            <span>Traveler: <?php echo esc_html($s->traveler_user_id); ?></span>
                            <code>[db_safedate_session id="<?php echo esc_attr($s->id); ?>"]</code>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php return ob_get_clean();
    }

    public static function session_panel($atts) {
        if (!is_user_logged_in()) return '<p>Please log in.</p>';
        $atts = shortcode_atts(array('id' => 0), $atts);
        $session_id = absint($atts['id']);
        if (!$session_id) return '<p>Missing SafeDate session ID.</p>';
        self::enqueue();
        ob_start(); ?>
        <div class="dbsd-card" data-dbsd-session="<?php echo esc_attr($session_id); ?>">
            <h3>SafeDate Session #<?php echo esc_html($session_id); ?></h3>
            <p class="dbsd-muted">Consent-based safety session. Tracking is visible, can be stopped, and is stored in the audit trail.</p>
            <div class="dbsd-status" aria-live="polite">Loading session...</div>
            <div class="dbsd-actions">
                <button class="dbsd-btn" data-action="consent-accept">Accept Consent</button>
                <button class="dbsd-btn dbsd-secondary" data-action="consent-decline">Decline</button>
                <button class="dbsd-btn" data-action="journey-start">Start Journey Tracking</button>
                <button class="dbsd-btn dbsd-secondary" data-action="journey-stop">Stop Tracking</button>
                <button class="dbsd-btn" data-action="arrival-claim">I Have Arrived</button>
                <button class="dbsd-btn" data-action="arrival-confirm">Confirm Arrival</button>
                <button class="dbsd-btn dbsd-secondary" data-action="arrival-reject">Reject Arrival</button>
                <button class="dbsd-btn" data-action="departure-claim">B Has Left</button>
                <button class="dbsd-btn" data-action="departure-confirm">Confirm I Left</button>
                <button class="dbsd-btn dbsd-secondary" data-action="departure-reject">Reject Departure</button>
                <button class="dbsd-btn dbsd-danger" data-action="sos">SOS / Emergency Alert</button>
                <button class="dbsd-btn dbsd-secondary" data-action="export-session">Export Audit JSON</button>
            </div>
            <h4>v0.3 Safety Tools</h4>
            <p class="dbsd-muted">Optional: add <code>[db_safedate_trusted_share id="<?php echo esc_attr($session_id); ?>"]</code> and <code>[db_safedate_incident_report id="<?php echo esc_attr($session_id); ?>"]</code> to DateBook pages, or use the export button below.</p>
            <div class="dbsd-export"></div>
            <h4>Movement Log</h4>
            <div class="dbsd-map" data-dbsd-map>Loading movement log...</div>
            <h4>Recent Audit</h4>
            <pre class="dbsd-audit">Loading...</pre>
        </div>
        <?php return ob_get_clean();
    }

    public static function contacts_panel() {
        if (!is_user_logged_in()) return '<p>Please log in to manage emergency contacts.</p>';
        self::enqueue();
        ob_start(); ?>
        <div class="dbsd-card" data-dbsd-contact-form>
            <h3>SafeDate Emergency Contact</h3>
            <p class="dbsd-muted">Add a trusted contact for future alert escalation. MVP sends platform alerts; contact escalation can be enabled in a production release.</p>
            <label>Name <input type="text" data-field="contact_name"></label>
            <label>Email <input type="email" data-field="contact_email"></label>
            <label>Phone <input type="tel" data-field="contact_phone"></label>
            <button type="button" class="dbsd-btn" data-action="save-contact">Save Contact</button>
            <div class="dbsd-result"></div>
        </div>
        <?php return ob_get_clean();
    }

    public static function notifications_panel() {
        if (!is_user_logged_in()) return '<p>Please log in to view notifications.</p>';
        self::enqueue();
        return '<div class="dbsd-card"><h3>SafeDate Notifications</h3><div class="dbsd-notifications" data-dbsd-notifications>Loading notifications...</div></div>';
    }
}
