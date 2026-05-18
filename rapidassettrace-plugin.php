<?php
/**
 * Plugin Name: RapidAssetTrace - Dual System Plugin
 * Plugin URI:  https://rapidassettrace.com
 * Description: System 1: Private Key Recovery (WooCommerce-integrated with crypto payments). System 2: Case Management System (custom plugin with user dashboard & admin panel).
 * Version:     1.0.0
 * Author:      RapidAssetTrace Dev
 * Text Domain: rapidassettrace
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

// ──────────────────────────────────────────────
// CONSTANTS
// ──────────────────────────────────────────────
define( 'RAT_VERSION',   '1.0.0' );
define( 'RAT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RAT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RAT_TABLE_CASES',       $GLOBALS['wpdb']->prefix . 'rat_cases' );
define( 'RAT_TABLE_CASE_NOTES',  $GLOBALS['wpdb']->prefix . 'rat_case_notes' );
define( 'RAT_TABLE_KEY_ORDERS',  $GLOBALS['wpdb']->prefix . 'rat_key_orders' );

// ──────────────────────────────────────────────
// ACTIVATION / DEACTIVATION / UNINSTALL
// ──────────────────────────────────────────────
register_activation_hook( __FILE__,   'rat_activate' );
register_deactivation_hook( __FILE__, 'rat_deactivate' );
register_uninstall_hook( __FILE__,    'rat_uninstall' );

function rat_activate() {
    rat_create_tables();
    rat_add_roles_and_caps();
    rat_schedule_events();
    flush_rewrite_rules();
}

function rat_deactivate() {
    wp_clear_scheduled_hook( 'rat_daily_cleanup' );
    flush_rewrite_rules();
}

function rat_uninstall() {
    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS " . RAT_TABLE_CASE_NOTES );
    $wpdb->query( "DROP TABLE IF EXISTS " . RAT_TABLE_CASES );
    $wpdb->query( "DROP TABLE IF EXISTS " . RAT_TABLE_KEY_ORDERS );
    delete_option( 'rat_settings' );
}

// ──────────────────────────────────────────────
// DATABASE TABLES
// ──────────────────────────────────────────────
function rat_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // Cases table
    $sql_cases = "CREATE TABLE IF NOT EXISTS " . RAT_TABLE_CASES . " (
        id            BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
        case_number   VARCHAR(20)  NOT NULL UNIQUE,
        user_id       BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
        full_name     VARCHAR(200) NOT NULL,
        email         VARCHAR(200) NOT NULL,
        phone         VARCHAR(50)  DEFAULT '',
        case_type     VARCHAR(100) NOT NULL,
        asset_type    VARCHAR(100) NOT NULL DEFAULT '',
        description   LONGTEXT     NOT NULL,
        amount_lost   DECIMAL(18,8) DEFAULT 0,
        currency      VARCHAR(10)  DEFAULT 'USD',
        status        VARCHAR(50)  NOT NULL DEFAULT 'pending',
        priority      VARCHAR(20)  NOT NULL DEFAULT 'normal',
        assigned_to   BIGINT(20)   UNSIGNED DEFAULT 0,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        ip_address    VARCHAR(45)  DEFAULT '',
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY status (status),
        KEY created_at (created_at)
    ) $charset;";

    // Case notes / activity log
    $sql_notes = "CREATE TABLE IF NOT EXISTS " . RAT_TABLE_CASE_NOTES . " (
        id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        case_id    BIGINT(20) UNSIGNED NOT NULL,
        author_id  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        note_type  VARCHAR(50) NOT NULL DEFAULT 'note',
        content    LONGTEXT NOT NULL,
        is_private TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY case_id (case_id)
    ) $charset;";

    // Key recovery orders (linked to WooCommerce)
    $sql_key = "CREATE TABLE IF NOT EXISTS " . RAT_TABLE_KEY_ORDERS . " (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        wc_order_id     BIGINT(20) UNSIGNED NOT NULL UNIQUE,
        user_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        wallet_type     VARCHAR(100) NOT NULL,
        wallet_address  TEXT NOT NULL,
        seed_hint       TEXT DEFAULT '',
        recovery_data   LONGBLOB DEFAULT NULL,
        payment_method  VARCHAR(50) DEFAULT '',
        crypto_txid     VARCHAR(255) DEFAULT '',
        status          VARCHAR(50) NOT NULL DEFAULT 'pending',
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY wc_order_id (wc_order_id),
        KEY user_id (user_id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_cases );
    dbDelta( $sql_notes );
    dbDelta( $sql_key );
}

// ──────────────────────────────────────────────
// ROLES & CAPABILITIES
// ──────────────────────────────────────────────
function rat_add_roles_and_caps() {
    add_role( 'rat_agent', 'RAT Agent', [
        'read'           => true,
        'rat_view_cases' => true,
        'rat_edit_cases' => true,
    ]);

    $admin = get_role( 'administrator' );
    if ( $admin ) {
        foreach ( ['rat_view_cases','rat_edit_cases','rat_delete_cases','rat_manage_settings','rat_view_orders'] as $cap ) {
            $admin->add_cap( $cap );
        }
    }
}

// ──────────────────────────────────────────────
// CRON
// ──────────────────────────────────────────────
function rat_schedule_events() {
    if ( ! wp_next_scheduled( 'rat_daily_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'rat_daily_cleanup' );
    }
}
add_action( 'rat_daily_cleanup', 'rat_run_daily_cleanup' );
function rat_run_daily_cleanup() {
    // Placeholder: e.g. purge old transients, send reminder emails, etc.
    delete_expired_transients();
}

// ──────────────────────────────────────────────
// SETTINGS PAGE
// ──────────────────────────────────────────────
add_action( 'admin_menu', 'rat_admin_menu' );
function rat_admin_menu() {
    add_menu_page(
        'RapidAssetTrace',
        'RapidAssetTrace',
        'rat_view_cases',
        'rat-dashboard',
        'rat_admin_dashboard_page',
        'dashicons-shield',
        26
    );
    add_submenu_page( 'rat-dashboard', 'All Cases',      'All Cases',      'rat_view_cases',     'rat-cases',        'rat_admin_cases_page' );
    add_submenu_page( 'rat-dashboard', 'Key Orders',     'Key Orders',     'rat_view_orders',    'rat-key-orders',   'rat_admin_key_orders_page' );
    add_submenu_page( 'rat-dashboard', 'Settings',       'Settings',       'rat_manage_settings','rat-settings',     'rat_admin_settings_page' );
}

function rat_admin_dashboard_page() {
    global $wpdb;
    $total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . RAT_TABLE_CASES );
    $pending     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . RAT_TABLE_CASES . " WHERE status='pending'" );
    $processing  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . RAT_TABLE_CASES . " WHERE status='processing'" );
    $completed   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . RAT_TABLE_CASES . " WHERE status='completed'" );
    ?>
    <div class="wrap">
        <h1>RapidAssetTrace Dashboard</h1>
        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:20px;">
            <?php foreach([
                ['Total Cases','#0073aa',$total],
                ['Pending','#f39c12',$pending],
                ['Processing','#3498db',$processing],
                ['Completed','#27ae60',$completed],
            ] as [$label,$color,$val]): ?>
            <div style="background:<?php echo $color;?>;color:#fff;padding:20px 30px;border-radius:8px;min-width:140px;text-align:center;">
                <div style="font-size:36px;font-weight:700;"><?php echo $val; ?></div>
                <div style="font-size:14px;margin-top:5px;"><?php echo $label; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <h2 style="margin-top:30px;">Recent Cases</h2>
        <?php
        $recent = $wpdb->get_results( "SELECT * FROM " . RAT_TABLE_CASES . " ORDER BY created_at DESC LIMIT 10" );
        rat_render_cases_table( $recent );
        ?>
    </div>
    <?php
}

function rat_admin_cases_page() {
    global $wpdb;

    // Handle status update
    if ( isset($_POST['rat_update_case_nonce']) && wp_verify_nonce($_POST['rat_update_case_nonce'],'rat_update_case') && current_user_can('rat_edit_cases') ) {
        $case_id = absint($_POST['case_id']);
        $status  = sanitize_text_field($_POST['new_status']);
        $note    = sanitize_textarea_field($_POST['admin_note'] ?? '');
        $allowed = ['pending','processing','under_review','on_hold','completed','rejected'];
        if ( in_array($status, $allowed) ) {
            $wpdb->update( RAT_TABLE_CASES, ['status'=>$status,'updated_at'=>current_time('mysql')], ['id'=>$case_id] );
            if ( $note ) {
                $wpdb->insert( RAT_TABLE_CASE_NOTES, [
                    'case_id'    => $case_id,
                    'author_id'  => get_current_user_id(),
                    'note_type'  => 'status_change',
                    'content'    => "Status changed to: {$status}. Note: {$note}",
                    'is_private' => 1,
                    'created_at' => current_time('mysql'),
                ]);
            }
            // Notify user
            $case = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".RAT_TABLE_CASES." WHERE id=%d",$case_id));
            if($case) rat_send_status_email($case, $status);
            echo '<div class="notice notice-success"><p>Case updated successfully.</p></div>';
        }
    }

    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $where = $status_filter ? $wpdb->prepare("WHERE status=%s",$status_filter) : '';
    $cases = $wpdb->get_results("SELECT * FROM ".RAT_TABLE_CASES." $where ORDER BY created_at DESC");
    ?>
    <div class="wrap">
        <h1>All Cases</h1>
        <ul class="subsubsub">
            <?php foreach([''=>'All','pending'=>'Pending','processing'=>'Processing','completed'=>'Completed','rejected'=>'Rejected'] as $s=>$l): ?>
            <li><a href="?page=rat-cases&status=<?php echo $s;?>"><?php echo $l;?></a> | </li>
            <?php endforeach;?>
        </ul>
        <?php rat_render_cases_table($cases, true); ?>
    </div>
    <?php
}

function rat_render_cases_table($cases, $with_actions = false) {
    if ( empty($cases) ) { echo '<p>No cases found.</p>'; return; }
    echo '<table class="wp-list-table widefat fixed striped"><thead><tr>
        <th>Case #</th><th>Name</th><th>Email</th><th>Type</th><th>Status</th><th>Priority</th><th>Date</th>';
    if ($with_actions) echo '<th>Actions</th>';
    echo '</tr></thead><tbody>';
    foreach($cases as $c):
        $status_colors = ['pending'=>'#f39c12','processing'=>'#3498db','completed'=>'#27ae60','rejected'=>'#e74c3c','on_hold'=>'#95a5a6','under_review'=>'#9b59b6'];
        $color = $status_colors[$c->status] ?? '#999';
        echo "<tr>
            <td><strong>{$c->case_number}</strong></td>
            <td>".esc_html($c->full_name)."</td>
            <td>".esc_html($c->email)."</td>
            <td>".esc_html($c->case_type)."</td>
            <td><span style='background:{$color};color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;'>".esc_html(ucfirst(str_replace('_',' ',$c->status)))."</span></td>
            <td>".esc_html($c->priority)."</td>
            <td>".esc_html($c->created_at)."</td>";
        if($with_actions):
            $statuses = ['pending','processing','under_review','on_hold','completed','rejected'];
            echo "<td>
            <form method='post' style='display:inline;'>
            ".wp_nonce_field('rat_update_case','rat_update_case_nonce',true,false)."
            <input type='hidden' name='case_id' value='{$c->id}'>
            <select name='new_status'>";
            foreach($statuses as $s) echo "<option value='{$s}' ".selected($c->status,$s,false).">".ucfirst(str_replace('_',' ',$s))."</option>";
            echo "</select>
            <input type='text' name='admin_note' placeholder='Add note...' style='margin:0 5px;'>
            <button type='submit' class='button button-small button-primary'>Update</button>
            </form></td>";
        endif;
        echo "</tr>";
    endforeach;
    echo '</tbody></table>';
}

function rat_admin_key_orders_page() {
    global $wpdb;
    $orders = $wpdb->get_results("SELECT ko.*, p.post_status as wc_status FROM ".RAT_TABLE_KEY_ORDERS." ko LEFT JOIN {$wpdb->posts} p ON ko.wc_order_id=p.ID ORDER BY ko.created_at DESC");
    ?>
    <div class="wrap">
        <h1>Key Recovery Orders</h1>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>ID</th><th>WC Order</th><th>User ID</th><th>Wallet Type</th><th>Payment</th><th>TX ID</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php if(empty($orders)) echo '<tr><td colspan="8">No orders yet.</td></tr>';
            foreach($orders as $o): ?>
            <tr>
                <td><?php echo $o->id;?></td>
                <td><a href="<?php echo admin_url('post.php?post='.$o->wc_order_id.'&action=edit');?>">#<?php echo $o->wc_order_id;?></a></td>
                <td><?php echo $o->user_id;?></td>
                <td><?php echo esc_html($o->wallet_type);?></td>
                <td><?php echo esc_html($o->payment_method);?></td>
                <td style="font-family:monospace;font-size:11px;"><?php echo esc_html(substr($o->crypto_txid,0,20)).(strlen($o->crypto_txid)>20?'...':'');?></td>
                <td><?php echo esc_html($o->status);?></td>
                <td><?php echo esc_html($o->created_at);?></td>
            </tr>
            <?php endforeach;?>
            </tbody>
        </table>
    </div>
    <?php
}

function rat_admin_settings_page() {
    if ( isset($_POST['rat_settings_nonce']) && wp_verify_nonce($_POST['rat_settings_nonce'],'rat_save_settings') ) {
        $settings = [
            'admin_email'        => sanitize_email($_POST['admin_email']),
            'crypto_gateway'     => sanitize_text_field($_POST['crypto_gateway']),
            'nowpayments_key'    => sanitize_text_field($_POST['nowpayments_key']),
            'coinbase_key'       => sanitize_text_field($_POST['coinbase_key']),
            'recovery_product_id'=> absint($_POST['recovery_product_id']),
            'cases_per_page'     => absint($_POST['cases_per_page']),
            'email_notifications'=> isset($_POST['email_notifications']) ? 1 : 0,
        ];
        update_option('rat_settings', $settings);
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }
    $s = get_option('rat_settings', []);
    ?>
    <div class="wrap">
        <h1>RapidAssetTrace Settings</h1>
        <form method="post">
            <?php wp_nonce_field('rat_save_settings','rat_settings_nonce'); ?>
            <table class="form-table">
                <tr><th>Admin Email</th><td><input type="email" name="admin_email" value="<?php echo esc_attr($s['admin_email']??get_option('admin_email'));?>" class="regular-text"></td></tr>
                <tr><th>Crypto Gateway</th><td>
                    <select name="crypto_gateway">
                        <?php foreach(['nowpayments'=>'NOWPayments','coinbase'=>'Coinbase Commerce','coingate'=>'CoinGate'] as $k=>$v): ?>
                        <option value="<?php echo $k;?>" <?php selected($s['crypto_gateway']??'',$k);?>><?php echo $v;?></option>
                        <?php endforeach;?>
                    </select>
                </td></tr>
                <tr><th>NOWPayments API Key</th><td><input type="text" name="nowpayments_key" value="<?php echo esc_attr($s['nowpayments_key']??'');?>" class="regular-text"></td></tr>
                <tr><th>Coinbase Commerce Key</th><td><input type="text" name="coinbase_key" value="<?php echo esc_attr($s['coinbase_key']??'');?>" class="regular-text"></td></tr>
                <tr><th>Recovery Product ID</th><td><input type="number" name="recovery_product_id" value="<?php echo esc_attr($s['recovery_product_id']??'');?>" class="small-text"><p class="description">WooCommerce product ID for key recovery service.</p></td></tr>
                <tr><th>Cases Per Page</th><td><input type="number" name="cases_per_page" value="<?php echo esc_attr($s['cases_per_page']??10);?>" class="small-text"></td></tr>
                <tr><th>Email Notifications</th><td><label><input type="checkbox" name="email_notifications" value="1" <?php checked($s['email_notifications']??1,1);?>> Enable email notifications for case updates</label></td></tr>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>
    </div>
    <?php
}

// ──────────────────────────────────────────────
// SHORTCODES
// ──────────────────────────────────────────────
add_shortcode( 'rat_submit_case',    'rat_shortcode_submit_case' );
add_shortcode( 'rat_user_dashboard', 'rat_shortcode_user_dashboard' );
add_shortcode( 'rat_key_recovery',   'rat_shortcode_key_recovery' );

// ── Submit Case Form ──
function rat_shortcode_submit_case() {
    if ( ! is_user_logged_in() ) {
        return '<p>Please <a href="'.wp_login_url(get_permalink()).'">log in</a> to submit a case.</p>';
    }

    $msg = '';
    if ( isset($_POST['rat_case_nonce']) && wp_verify_nonce($_POST['rat_case_nonce'],'rat_submit_case') ) {
        $result = rat_process_case_submission($_POST);
        if ( is_wp_error($result) ) {
            $msg = '<div class="rat-notice rat-error">'.esc_html($result->get_error_message()).'</div>';
        } else {
            $msg = '<div class="rat-notice rat-success">Your case <strong>'.esc_html($result).'</strong> has been submitted. We will review it and respond shortly.</div>';
        }
    }

    ob_start();
    echo $msg;
    $case_types  = ['Cryptocurrency Recovery','Wallet Recovery','Exchange Account Recovery','NFT Recovery','Investment Fraud','Other'];
    $asset_types = ['Bitcoin (BTC)','Ethereum (ETH)','USDT','BNB','Solana (SOL)','Other Crypto','Stocks','Fiat/Bank','Other'];
    ?>
    <div class="rat-form-wrap">
        <form method="post" class="rat-form" id="rat-case-form">
            <?php wp_nonce_field('rat_submit_case','rat_case_nonce'); ?>
            <div class="rat-form-row">
                <div class="rat-form-group">
                    <label>Full Name <span class="rat-req">*</span></label>
                    <input type="text" name="full_name" required maxlength="200" value="<?php echo esc_attr(wp_get_current_user()->display_name);?>">
                </div>
                <div class="rat-form-group">
                    <label>Email Address <span class="rat-req">*</span></label>
                    <input type="email" name="email" required value="<?php echo esc_attr(wp_get_current_user()->user_email);?>">
                </div>
            </div>
            <div class="rat-form-row">
                <div class="rat-form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone" maxlength="50">
                </div>
                <div class="rat-form-group">
                    <label>Case Type <span class="rat-req">*</span></label>
                    <select name="case_type" required>
                        <option value="">Select case type...</option>
                        <?php foreach($case_types as $t) echo "<option value='$t'>$t</option>"; ?>
                    </select>
                </div>
            </div>
            <div class="rat-form-row">
                <div class="rat-form-group">
                    <label>Asset Type</label>
                    <select name="asset_type">
                        <option value="">Select asset...</option>
                        <?php foreach($asset_types as $t) echo "<option value='$t'>$t</option>"; ?>
                    </select>
                </div>
                <div class="rat-form-group">
                    <label>Estimated Amount Lost</label>
                    <input type="number" name="amount_lost" step="0.00000001" min="0" placeholder="0.00">
                </div>
            </div>
            <div class="rat-form-group">
                <label>Case Description <span class="rat-req">*</span></label>
                <textarea name="description" required rows="6" minlength="50" placeholder="Please describe your situation in detail. Include relevant dates, transaction IDs, wallet addresses (redacted), and any other relevant information..."></textarea>
            </div>
            <div class="rat-form-group rat-checkbox-group">
                <label><input type="checkbox" name="agree_terms" value="1" required> I agree to the <a href="/terms" target="_blank">Terms of Service</a> and understand my data will be handled securely.</label>
            </div>
            <button type="submit" class="rat-btn rat-btn-primary">Submit Case &rarr;</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

function rat_process_case_submission($data) {
    global $wpdb;

    // Validate
    $required = ['full_name','email','case_type','description'];
    foreach($required as $field) {
        if ( empty(trim($data[$field] ?? '')) ) {
            return new WP_Error('missing_field', "Field '{$field}' is required.");
        }
    }
    if ( ! is_email($data['email']) ) return new WP_Error('invalid_email','Invalid email address.');
    if ( empty($data['agree_terms']) ) return new WP_Error('terms','You must agree to the terms.');
    if ( strlen($data['description']) < 50 ) return new WP_Error('desc_short','Description must be at least 50 characters.');

    // Rate limiting: max 3 cases per user per day
    $user_id    = get_current_user_id();
    $today_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM ".RAT_TABLE_CASES." WHERE user_id=%d AND DATE(created_at)=CURDATE()", $user_id
    ));
    if ( $today_count >= 3 ) return new WP_Error('rate_limit','You have reached the daily case submission limit (3 per day).');

    $case_number = 'RAT-' . strtoupper(substr(md5(uniqid(rand(),true)),0,8));

    $inserted = $wpdb->insert(RAT_TABLE_CASES, [
        'case_number'  => $case_number,
        'user_id'      => $user_id,
        'full_name'    => sanitize_text_field($data['full_name']),
        'email'        => sanitize_email($data['email']),
        'phone'        => sanitize_text_field($data['phone'] ?? ''),
        'case_type'    => sanitize_text_field($data['case_type']),
        'asset_type'   => sanitize_text_field($data['asset_type'] ?? ''),
        'description'  => sanitize_textarea_field($data['description']),
        'amount_lost'  => floatval($data['amount_lost'] ?? 0),
        'currency'     => 'USD',
        'status'       => 'pending',
        'priority'     => 'normal',
        'ip_address'   => rat_get_ip(),
        'created_at'   => current_time('mysql'),
        'updated_at'   => current_time('mysql'),
    ]);

    if ( ! $inserted ) return new WP_Error('db_error','Failed to save case. Please try again.');

    // Notifications
    $settings = get_option('rat_settings',[]);
    if ( ! empty($settings['email_notifications']) ) {
        rat_send_case_submitted_email($data['email'], $case_number);
        rat_notify_admin_new_case($case_number, $data);
    }

    return $case_number;
}

// ── User Dashboard ──
function rat_shortcode_user_dashboard() {
    if ( ! is_user_logged_in() ) {
        return '<p>Please <a href="'.wp_login_url(get_permalink()).'">log in</a> to view your dashboard.</p>';
    }
    global $wpdb;
    $user_id = get_current_user_id();
    $cases   = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM ".RAT_TABLE_CASES." WHERE user_id=%d ORDER BY created_at DESC", $user_id
    ));

    $status_counts = [];
    foreach($cases as $c) $status_counts[$c->status] = ($status_counts[$c->status]??0)+1;

    ob_start();
    ?>
    <div class="rat-dashboard">
        <div class="rat-dash-header">
            <h2>My Dashboard</h2>
            <p>Welcome back, <strong><?php echo esc_html(wp_get_current_user()->display_name);?></strong></p>
        </div>
        <div class="rat-stats-row">
            <?php foreach([
                ['Total Cases', count($cases), '#0073aa'],
                ['Pending',    $status_counts['pending']??0, '#f39c12'],
                ['Processing', $status_counts['processing']??0, '#3498db'],
                ['Completed',  $status_counts['completed']??0, '#27ae60'],
            ] as [$l,$v,$c]):?>
            <div class="rat-stat-card" style="border-top:3px solid <?php echo $c;?>">
                <div class="rat-stat-num"><?php echo $v;?></div>
                <div class="rat-stat-label"><?php echo $l;?></div>
            </div>
            <?php endforeach;?>
        </div>

        <div class="rat-dash-section">
            <h3>My Cases</h3>
            <?php if(empty($cases)): ?>
            <p>You have not submitted any cases yet. <a href="/submit-case">Submit a case</a>.</p>
            <?php else: ?>
            <table class="rat-table">
                <thead><tr><th>Case #</th><th>Type</th><th>Asset</th><th>Amount</th><th>Status</th><th>Submitted</th></tr></thead>
                <tbody>
                <?php foreach($cases as $c):
                    $badges = ['pending'=>'badge-warn','processing'=>'badge-info','completed'=>'badge-success','rejected'=>'badge-danger','on_hold'=>'badge-muted','under_review'=>'badge-purple'];
                    $badge  = $badges[$c->status] ?? 'badge-muted';
                ?>
                <tr>
                    <td><strong><?php echo esc_html($c->case_number);?></strong></td>
                    <td><?php echo esc_html($c->case_type);?></td>
                    <td><?php echo esc_html($c->asset_type);?></td>
                    <td><?php echo $c->amount_lost>0 ? '$'.number_format($c->amount_lost,2) : '—';?></td>
                    <td><span class="rat-badge <?php echo $badge;?>"><?php echo esc_html(ucfirst(str_replace('_',' ',$c->status)));?></span></td>
                    <td><?php echo date('M j, Y', strtotime($c->created_at));?></td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
            <?php endif;?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ── Key Recovery Checkout Add-on ──
function rat_shortcode_key_recovery() {
    $settings = get_option('rat_settings',[]);
    $product_id = absint($settings['recovery_product_id'] ?? 0);
    if(!$product_id) return '<p>Key recovery service is not configured. Please contact support.</p>';
    $product = wc_get_product($product_id);
    if(!$product) return '<p>Service unavailable. Please try later.</p>';

    ob_start();
    ?>
    <div class="rat-recovery-wrap">
        <div class="rat-recovery-info">
            <h3><?php echo esc_html($product->get_name());?></h3>
            <p><?php echo wp_kses_post($product->get_short_description());?></p>
            <div class="rat-price"><?php echo $product->get_price_html();?></div>
        </div>

        <?php if(is_user_logged_in()): ?>
        <form method="post" class="rat-form" id="rat-recovery-form" action="<?php echo esc_url(wc_get_cart_url());?>">
            <?php wp_nonce_field('rat_key_recovery','rat_recovery_nonce'); ?>
            <input type="hidden" name="add-to-cart" value="<?php echo $product_id;?>">
            <div class="rat-form-group">
                <label>Wallet / Exchange Type <span class="rat-req">*</span></label>
                <select name="rat_wallet_type" required>
                    <?php foreach(['MetaMask','Trust Wallet','Ledger','Trezor','Coinbase Wallet','Exodus','Electrum','MyEtherWallet','Binance','Kraken','Other'] as $w) echo "<option>$w</option>"; ?>
                </select>
            </div>
            <div class="rat-form-group">
                <label>Wallet Address (optional — for verification)</label>
                <input type="text" name="rat_wallet_address" placeholder="0x... or bc1...">
                <p class="rat-hint">This is used for verification only and is stored encrypted.</p>
            </div>
            <div class="rat-form-group">
                <label>Seed Phrase Hint (optional)</label>
                <textarea name="rat_seed_hint" rows="3" placeholder="E.g. first 3 words, number of words, any patterns you remember..."></textarea>
                <p class="rat-hint">Never share your complete seed phrase. Hints only help us scope the recovery.</p>
            </div>
            <div class="rat-form-group rat-checkbox-group">
                <label><input type="checkbox" name="rat_agree" required> I understand this is a professional service and fees are non-refundable.</label>
            </div>
            <button type="submit" class="rat-btn rat-btn-primary">Proceed to Payment &rarr;</button>
        </form>
        <?php else: ?>
        <p>Please <a href="<?php echo wp_login_url(get_permalink());?>">log in</a> or <a href="<?php echo wp_registration_url();?>">create an account</a> to use this service.</p>
        <?php endif;?>
    </div>
    <?php
    return ob_get_clean();
}

// ──────────────────────────────────────────────
// WOOCOMMERCE INTEGRATION
// ──────────────────────────────────────────────

// Save custom fields to order meta
add_action('woocommerce_checkout_create_order', 'rat_save_key_recovery_to_order', 10, 2);
function rat_save_key_recovery_to_order($order, $data) {
    if(!isset($_POST['rat_recovery_nonce']) || !wp_verify_nonce($_POST['rat_recovery_nonce'],'rat_key_recovery')) return;
    $settings   = get_option('rat_settings',[]);
    $product_id = absint($settings['recovery_product_id']??0);
    $has_product = false;
    foreach($order->get_items() as $item) {
        if((int)$item->get_product_id() === $product_id) { $has_product = true; break; }
    }
    if(!$has_product) return;

    $order->update_meta_data('_rat_wallet_type',    sanitize_text_field($_POST['rat_wallet_type']??''));
    $order->update_meta_data('_rat_wallet_address', sanitize_text_field($_POST['rat_wallet_address']??''));
    $order->update_meta_data('_rat_seed_hint',      sanitize_textarea_field($_POST['rat_seed_hint']??''));
}

// When order is paid, create key order record
add_action('woocommerce_payment_complete', 'rat_on_payment_complete');
function rat_on_payment_complete($order_id) {
    global $wpdb;
    $order    = wc_get_order($order_id);
    $settings = get_option('rat_settings',[]);
    $pid      = absint($settings['recovery_product_id']??0);
    $has_product = false;
    foreach($order->get_items() as $item) {
        if((int)$item->get_product_id() === $pid) { $has_product = true; break; }
    }
    if(!$has_product) return;

    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM ".RAT_TABLE_KEY_ORDERS." WHERE wc_order_id=%d",$order_id));
    if($existing) return;

    $wpdb->insert(RAT_TABLE_KEY_ORDERS,[
        'wc_order_id'    => $order_id,
        'user_id'        => $order->get_user_id(),
        'wallet_type'    => $order->get_meta('_rat_wallet_type'),
        'wallet_address' => rat_encrypt($order->get_meta('_rat_wallet_address')),
        'seed_hint'      => rat_encrypt($order->get_meta('_rat_seed_hint')),
        'payment_method' => $order->get_payment_method(),
        'status'         => 'paid',
        'created_at'     => current_time('mysql'),
        'updated_at'     => current_time('mysql'),
    ]);
}

// NOWPayments webhook handler
add_action('init', 'rat_register_webhook_endpoint');
function rat_register_webhook_endpoint() {
    add_rewrite_rule('^rat-webhook/nowpayments/?$','index.php?rat_webhook=nowpayments','top');
}
add_filter('query_vars','rat_query_vars');
function rat_query_vars($vars) { $vars[]='rat_webhook'; return $vars; }
add_action('template_redirect','rat_handle_webhook');
function rat_handle_webhook() {
    $wh = get_query_var('rat_webhook');
    if(!$wh) return;
    if($wh === 'nowpayments') {
        $payload  = file_get_contents('php://input');
        $settings = get_option('rat_settings',[]);
        $sig      = $_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ?? '';
        $expected = hash_hmac('sha512', $payload, $settings['nowpayments_key']??'');
        if(!hash_equals($expected,$sig)) { http_response_code(401); exit; }
        $data = json_decode($payload,true);
        if(($data['payment_status']??'') === 'finished') {
            // Mark WC order as paid
            $order_id = absint($data['order_id']??0);
            $order    = wc_get_order($order_id);
            if($order && !$order->is_paid()) {
                $order->payment_complete($data['payment_id']??'');
            }
        }
        http_response_code(200);
        exit;
    }
}

// ──────────────────────────────────────────────
// HELPER FUNCTIONS
// ──────────────────────────────────────────────

function rat_get_ip() {
    foreach(['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
        if(!empty($_SERVER[$k])) return sanitize_text_field(explode(',',$_SERVER[$k])[0]);
    }
    return '';
}

function rat_encrypt($data) {
    if(empty($data)) return '';
    $key    = wp_salt('auth');
    $iv     = random_bytes(16);
    $enc    = openssl_encrypt($data,'AES-256-CBC',hash('sha256',$key,true),0,$iv);
    return base64_encode($iv.$enc);
}

function rat_decrypt($data) {
    if(empty($data)) return '';
    $key    = wp_salt('auth');
    $raw    = base64_decode($data);
    $iv     = substr($raw,0,16);
    $enc    = substr($raw,16);
    return openssl_decrypt($enc,'AES-256-CBC',hash('sha256',$key,true),0,$iv);
}

function rat_generate_case_number() {
    return 'RAT-' . strtoupper(substr(md5(uniqid(rand(),true)),0,8));
}

// ──────────────────────────────────────────────
// EMAIL NOTIFICATIONS
// ──────────────────────────────────────────────

function rat_send_case_submitted_email($to, $case_number) {
    $subject = "[RapidAssetTrace] Case Submitted: {$case_number}";
    $body    = "Hello,\n\nYour case has been successfully submitted.\n\nCase Number: {$case_number}\n\nOur team will review your case and contact you within 24–48 hours.\n\nThank you,\nRapidAssetTrace Team\nhttps://rapidassettrace.com";
    wp_mail($to, $subject, $body);
}

function rat_notify_admin_new_case($case_number, $data) {
    $settings = get_option('rat_settings',[]);
    $to       = $settings['admin_email'] ?? get_option('admin_email');
    $subject  = "[RapidAssetTrace] New Case: {$case_number}";
    $body     = "A new case has been submitted.\n\nCase Number: {$case_number}\nName: {$data['full_name']}\nEmail: {$data['email']}\nType: {$data['case_type']}\n\nLogin to the admin panel to review:\n".admin_url('admin.php?page=rat-cases');
    wp_mail($to, $subject, $body);
}

function rat_send_status_email($case, $new_status) {
    $settings = get_option('rat_settings',[]);
    if(empty($settings['email_notifications'])) return;
    $subject = "[RapidAssetTrace] Case {$case->case_number} Update";
    $body    = "Hello {$case->full_name},\n\nYour case {$case->case_number} has been updated.\n\nNew Status: ".ucfirst(str_replace('_',' ',$new_status))."\n\nLog in to your dashboard to view details:\n".home_url('/dashboard')."\n\nRapidAssetTrace Team";
    wp_mail($case->email, $subject, $body);
}

// ──────────────────────────────────────────────
// ENQUEUE STYLES (Frontend only)
// ──────────────────────────────────────────────

add_action('wp_enqueue_scripts','rat_enqueue_scripts');
function rat_enqueue_scripts() {
    wp_add_inline_style('wp-block-library', rat_inline_css());
}

function rat_inline_css() {
    return '
    .rat-form-wrap,.rat-dashboard,.rat-recovery-wrap{max-width:820px;margin:0 auto;font-family:inherit;}
    .rat-form{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;box-shadow:0 2px 12px rgba(0,0,0,.06);}
    .rat-form-row{display:flex;gap:20px;flex-wrap:wrap;}
    .rat-form-row .rat-form-group{flex:1;min-width:200px;}
    .rat-form-group{margin-bottom:20px;display:flex;flex-direction:column;}
    .rat-form-group label{font-weight:600;margin-bottom:6px;font-size:14px;color:#374151;}
    .rat-form-group input,.rat-form-group select,.rat-form-group textarea{
        border:1.5px solid #d1d5db;border-radius:7px;padding:10px 14px;font-size:15px;
        transition:border-color .2s;outline:none;font-family:inherit;background:#fafafa;}
    .rat-form-group input:focus,.rat-form-group select:focus,.rat-form-group textarea:focus{border-color:#0073aa;background:#fff;}
    .rat-req{color:#e74c3c;}
    .rat-hint{font-size:12px;color:#6b7280;margin-top:4px;}
    .rat-checkbox-group{flex-direction:row;align-items:center;gap:10px;}
    .rat-btn{padding:12px 28px;border:none;border-radius:8px;font-size:16px;font-weight:700;cursor:pointer;transition:all .2s;}
    .rat-btn-primary{background:#0073aa;color:#fff;}
    .rat-btn-primary:hover{background:#005a87;}
    .rat-notice{padding:12px 18px;border-radius:8px;margin-bottom:20px;font-weight:500;}
    .rat-success{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;}
    .rat-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
    .rat-stats-row{display:flex;gap:16px;flex-wrap:wrap;margin:20px 0;}
    .rat-stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;flex:1;min-width:120px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.05);}
    .rat-stat-num{font-size:32px;font-weight:800;color:#111;}
    .rat-stat-label{font-size:13px;color:#6b7280;margin-top:4px;}
    .rat-table{width:100%;border-collapse:collapse;font-size:14px;}
    .rat-table th{background:#f9fafb;border-bottom:2px solid #e5e7eb;padding:10px 12px;text-align:left;font-weight:700;}
    .rat-table td{padding:10px 12px;border-bottom:1px solid #f3f4f6;}
    .rat-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:600;}
    .badge-warn{background:#fef3c7;color:#92400e;}
    .badge-info{background:#dbeafe;color:#1e40af;}
    .badge-success{background:#d1fae5;color:#065f46;}
    .badge-danger{background:#fee2e2;color:#991b1b;}
    .badge-muted{background:#f3f4f6;color:#6b7280;}
    .badge-purple{background:#ede9fe;color:#5b21b6;}
    ';
}

// ──────────────────────────────────────────────
// REST API ENDPOINTS (optional, for headless/AJAX)
// ──────────────────────────────────────────────

add_action('rest_api_init','rat_register_rest_routes');
function rat_register_rest_routes() {
    register_rest_route('rat/v1','/cases',['methods'=>'GET','callback'=>'rat_rest_get_cases','permission_callback'=>function(){return current_user_can('rat_view_cases');}]);
    register_rest_route('rat/v1','/cases/(?P<id>\d+)',['methods'=>'PATCH','callback'=>'rat_rest_update_case','permission_callback'=>function(){return current_user_can('rat_edit_cases');}]);
    register_rest_route('rat/v1','/my-cases',['methods'=>'GET','callback'=>'rat_rest_my_cases','permission_callback'=>'is_user_logged_in']);
}

function rat_rest_get_cases($req) {
    global $wpdb;
    $cases = $wpdb->get_results("SELECT id,case_number,full_name,email,case_type,status,priority,created_at FROM ".RAT_TABLE_CASES." ORDER BY created_at DESC LIMIT 100");
    return rest_ensure_response($cases);
}

function rat_rest_update_case($req) {
    global $wpdb;
    $id     = (int)$req['id'];
    $status = sanitize_text_field($req->get_param('status'));
    $allowed = ['pending','processing','under_review','on_hold','completed','rejected'];
    if(!in_array($status,$allowed)) return new WP_Error('invalid_status','Invalid status.',['status'=>400]);
    $wpdb->update(RAT_TABLE_CASES,['status'=>$status,'updated_at'=>current_time('mysql')],['id'=>$id]);
    return rest_ensure_response(['success'=>true,'id'=>$id,'status'=>$status]);
}

function rat_rest_my_cases($req) {
    global $wpdb;
    $cases = $wpdb->get_results($wpdb->prepare(
        "SELECT id,case_number,case_type,asset_type,status,created_at FROM ".RAT_TABLE_CASES." WHERE user_id=%d ORDER BY created_at DESC",
        get_current_user_id()
    ));
    return rest_ensure_response($cases);
}
