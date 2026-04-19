<?php
/**
 * Plugin Name: DH Contact
 * Plugin URI:  https://www.deepakhasija.com
 * Description: Contact form handler for deepakhasija.com — stores entries, sends email notifications, provides admin settings.
 * Version:     1.0.0
 * Author:      Deepak Hasija
 * Author URI:  https://www.deepakhasija.com
 * License:     GPL-2.0+
 * Text Domain: dh-contact
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DH_CONTACT_VERSION', '1.0.0' );
define( 'DH_CONTACT_DB_VERSION', '1' );
define( 'DH_CONTACT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DH_CONTACT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ── ACTIVATION: create DB table ──────────────────────────────────────────
register_activation_hook( __FILE__, 'dhc_activate' );
function dhc_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . 'dh_contact_entries';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        submitted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status        VARCHAR(20) NOT NULL DEFAULT 'unread',
        name          VARCHAR(255) NOT NULL DEFAULT '',
        email         VARCHAR(255) NOT NULL DEFAULT '',
        company       VARCHAR(255) NOT NULL DEFAULT '',
        service       VARCHAR(100) NOT NULL DEFAULT '',
        budget        VARCHAR(100) NOT NULL DEFAULT '',
        timeline      VARCHAR(100) NOT NULL DEFAULT '',
        message       TEXT NOT NULL DEFAULT '',
        source        VARCHAR(100) NOT NULL DEFAULT '',
        ip_address    VARCHAR(45) NOT NULL DEFAULT '',
        user_agent    VARCHAR(500) NOT NULL DEFAULT '',
        PRIMARY KEY   (id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    add_option( 'dh_contact_db_version', DH_CONTACT_DB_VERSION );

    // Default settings
    if ( ! get_option( 'dh_contact_settings' ) ) {
        update_option( 'dh_contact_settings', [
            'notification_email' => get_option('admin_email'),
            'cc_email'           => '',
            'success_message'    => "Thank you — I'll be in touch within 24 hours.",
            'redirect_url'       => '',
            'honeypot'           => '1',
            'store_entries'      => '1',
        ] );
    }
}

// ── DEACTIVATION ─────────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, 'dhc_deactivate' );
function dhc_deactivate() {
    // intentionally light — keep data on deactivation
}

// ── ENQUEUE FRONTEND SCRIPT ──────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'dhc_enqueue_scripts' );
function dhc_enqueue_scripts() {
    // Only load on contact page
    if ( ! is_page( ['contact', 'contact-us'] ) && strpos( $_SERVER['REQUEST_URI'], 'contact' ) === false ) {
        // Load on any page that has the form — check for shortcode or load always
    }
    wp_enqueue_script(
        'dh-contact-form',
        DH_CONTACT_PLUGIN_URL . 'dh-contact-form.js',
        [],
        DH_CONTACT_VERSION,
        true
    );
    wp_localize_script( 'dh-contact-form', 'dhcAjax', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'dh_contact_submit' ),
    ] );
}

// ── AJAX HANDLER (logged in + logged out) ────────────────────────────────
add_action( 'wp_ajax_dh_contact_submit',        'dhc_handle_submission' );
add_action( 'wp_ajax_nopriv_dh_contact_submit', 'dhc_handle_submission' );

function dhc_handle_submission() {
    // Verify nonce
    if ( ! check_ajax_referer( 'dh_contact_submit', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Security check failed. Please refresh and try again.' ], 403 );
    }

    $settings = get_option( 'dh_contact_settings', [] );

    // Honeypot check
    if ( ! empty( $settings['honeypot'] ) && ! empty( $_POST['website'] ) ) {
        // Silent success to fool bots
        wp_send_json_success( [ 'message' => $settings['success_message'] ?? 'Thank you!' ] );
    }

    // Unslash first (WordPress adds magic quotes to $_POST) then sanitise
    $post      = wp_unslash( $_POST );
    $name      = sanitize_text_field( $post['name']     ?? '' );
    $email     = sanitize_email( $post['email']         ?? '' );
    $company   = sanitize_text_field( $post['company']  ?? '' );
    $service   = sanitize_text_field( $post['service']  ?? '' );
    $budget    = sanitize_text_field( $post['budget']   ?? '' );
    $timeline  = sanitize_text_field( $post['timeline'] ?? '' );
    $message   = sanitize_textarea_field( $post['message'] ?? '' );
    $source    = sanitize_text_field( $post['source']   ?? '' );

    // Validate required
    $errors = [];
    if ( empty( $name ) )    $errors[] = 'Name is required.';
    if ( ! is_email( $email ) ) $errors[] = 'A valid email address is required.';
    if ( empty( $service ) ) $errors[] = 'Please select a service.';
    if ( empty( $message ) ) $errors[] = 'Please describe your project.';

    if ( $errors ) {
        wp_send_json_error( [ 'message' => implode( ' ', $errors ) ], 422 );
    }

    $ip         = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
    $user_agent = sanitize_text_field( substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500 ) );

    // Store entry
    if ( ! empty( $settings['store_entries'] ) ) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'dh_contact_entries',
            compact( 'name', 'email', 'company', 'service', 'budget', 'timeline', 'message', 'source', 'ip_address', 'user_agent' ),
            [ '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' ]
        );
    }

    // Build email
    $to      = $settings['notification_email'] ?? get_option('admin_email');
    $subject = "New contact form submission from {$name}";

    $service_labels = [
        'wordpress' => 'WordPress Development',
        'seo'       => 'Technical SEO',
        'ai'        => 'AI Workflow Automation',
        'woo'       => 'WooCommerce',
        'email'     => 'Email, CRM & Analytics',
        'support'   => 'Ongoing Support & Care',
        'full'      => 'Full Site Build',
        'other'     => 'Other',
    ];
    $service_label = $service_labels[ $service ] ?? $service;

    $budget_labels = [
        '500'     => 'Under $500',
        '1000'    => '$500 – $1,000',
        '3000'    => '$1,000 – $3,000',
        '5000'    => '$3,000 – $5,000',
        '10000'   => '$5,000 – $10,000',
        '10000+'  => '$10,000+',
        'discuss' => 'Prefer to discuss',
    ];
    $budget_label = $budget_labels[ $budget ] ?? $budget;

    $timeline_labels = [
        'asap'     => 'ASAP — urgent',
        '2weeks'   => 'Within 2 weeks',
        'month'    => 'Within a month',
        'flexible' => 'Flexible',
    ];
    $timeline_label = $timeline_labels[ $timeline ] ?? $timeline;

    $source_labels = [
        'google'   => 'Google Search',
        'linkedin' => 'LinkedIn',
        'upwork'   => 'Upwork',
        'referral' => 'Referral',
        'other'    => 'Other',
    ];
    $source_label = $source_labels[ $source ] ?? $source;

    $body = "You have a new contact form submission on deepakhasija.com.\n\n";
    $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $body .= "NAME:       {$name}\n";
    $body .= "EMAIL:      {$email}\n";
    if ( $company ) $body .= "COMPANY:    {$company}\n";
    $body .= "SERVICE:    {$service_label}\n";
    if ( $budget )   $body .= "BUDGET:     {$budget_label}\n";
    if ( $timeline ) $body .= "TIMELINE:   {$timeline_label}\n";
    if ( $source )   $body .= "SOURCE:     {$source_label}\n";
    $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $body .= "MESSAGE:\n{$message}\n\n";
    $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $body .= "Submitted: " . current_time('mysql') . "\n";
    $body .= "IP: {$ip}\n";
    $body .= "View all entries: " . admin_url('admin.php?page=dh-contact-entries') . "\n";

    $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
    if ( ! empty( $settings['cc_email'] ) ) {
        $headers[] = 'Cc: ' . $settings['cc_email'];
    }
    // Reply-to the submitter
    $headers[] = "Reply-To: {$name} <{$email}>";

    wp_mail( $to, $subject, $body, $headers );

    // Auto-reply to submitter
    $auto_subject = "Thanks for reaching out — I'll be in touch shortly";
    $auto_body  = "Hi {$name},\n\n";
    $auto_body .= "Thanks for getting in touch. I've received your message and will get back to you within 24 hours — usually much sooner.\n\n";
    $auto_body .= "If your project is urgent, you're also welcome to book a call directly:\nhttps://www.deepakhasija.com/book-a-free-call/\n\n";
    $auto_body .= "Deepak Hasija\nWordPress & AI Contractor\nhttps://www.deepakhasija.com\n";
    $auto_headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'From: Deepak Hasija <' . $to . '>',
    ];
    wp_mail( $email, $auto_subject, $auto_body, $auto_headers );

    // Redirect or JSON response
    if ( ! empty( $settings['redirect_url'] ) ) {
        wp_send_json_success( [ 'redirect' => $settings['redirect_url'] ] );
    } else {
        $msg = ! empty( $settings['success_message'] )
            ? $settings['success_message']
            : "Thank you — I'll be in touch within 24 hours.";
        wp_send_json_success( [ 'message' => $msg ] );
    }
}

// ── ADMIN MENU ────────────────────────────────────────────────────────────
add_action( 'admin_menu', 'dhc_admin_menu' );
function dhc_admin_menu() {
    add_menu_page(
        'DH Contact',
        'DH Contact',
        'manage_options',
        'dh-contact-entries',
        'dhc_entries_page',
        'dashicons-email-alt',
        30
    );
    add_submenu_page(
        'dh-contact-entries',
        'All Entries',
        'All Entries',
        'manage_options',
        'dh-contact-entries',
        'dhc_entries_page'
    );
    add_submenu_page(
        'dh-contact-entries',
        'Settings',
        'Settings',
        'manage_options',
        'dh-contact-settings',
        'dhc_settings_page'
    );
}

// ── ADMIN: ENTRIES PAGE ───────────────────────────────────────────────────
function dhc_entries_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'dh_contact_entries';

    // Handle actions
    if ( isset( $_GET['action'] ) && isset( $_GET['entry_id'] ) ) {
        $id = intval( $_GET['entry_id'] );
        if ( $_GET['action'] === 'mark_read' ) {
            $wpdb->update( $table, ['status' => 'read'], ['id' => $id] );
            echo '<div class="notice notice-success is-dismissible"><p>Entry marked as read.</p></div>';
        }
        if ( $_GET['action'] === 'delete' && check_admin_referer('dhc_delete_' . $id) ) {
            $wpdb->delete( $table, ['id' => $id] );
            echo '<div class="notice notice-success is-dismissible"><p>Entry deleted.</p></div>';
        }
    }

    // Handle CSV export
    if ( isset( $_GET['export'] ) && $_GET['export'] === 'csv' ) {
        dhc_export_csv();
        return;
    }

    // Handle bulk delete
    if ( isset( $_POST['action'] ) && $_POST['action'] === 'bulk_delete' && ! empty( $_POST['entry_ids'] ) ) {
        check_admin_referer( 'dhc_bulk_action' );
        $ids = array_map( 'intval', $_POST['entry_ids'] );
        $placeholders = implode( ',', array_fill( 0, count($ids), '%d' ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
        echo '<div class="notice notice-success is-dismissible"><p>' . count($ids) . ' entries deleted.</p></div>';
    }

    $filter  = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
    $where   = $filter ? $wpdb->prepare( "WHERE status = %s", $filter ) : '';
    $entries = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY submitted_at DESC LIMIT 200" );
    $total   = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    $unread  = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'unread'" );

    $service_labels = [
        'wordpress'=>'WordPress','seo'=>'SEO','ai'=>'AI Automation','woo'=>'WooCommerce',
        'email'=>'Email/CRM','support'=>'Support','full'=>'Full Build','other'=>'Other',
    ];
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:16px;">
            DH Contact — Entries
            <a href="<?= admin_url('admin.php?page=dh-contact-entries&export=csv') ?>" class="button">Export CSV</a>
        </h1>

        <div style="display:flex;gap:24px;margin:16px 0 20px;flex-wrap:wrap;">
            <?php foreach([
                ['All','',           $total,  '#6B7280'],
                ['Unread','unread',  $unread, '#0D9488'],
                ['Read','read',      $total - $unread, '#374151'],
            ] as [$label,$val,$count,$col]): ?>
            <a href="<?= admin_url('admin.php?page=dh-contact-entries' . ($val ? '&status='.$val : '')) ?>"
               style="text-decoration:none;background:<?= ($filter===$val)?'#0D9488':'#fff' ?>;
               color:<?= ($filter===$val)?'#fff':'#374151' ?>;padding:8px 18px;border-radius:6px;
               border:1px solid <?= ($filter===$val)?'#0D9488':'#E2E8F0' ?>;font-weight:600;font-size:13px;">
                <?= $label ?> <strong>(<?= $count ?>)</strong>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ( empty($entries) ): ?>
        <div style="background:#fff;border:1px solid #E2E8F0;border-radius:8px;padding:48px;text-align:center;color:#6B7280;">
            <p style="font-size:16px;margin:0;">No entries yet. Submissions will appear here.</p>
        </div>
        <?php else: ?>
        <form method="post">
            <?php wp_nonce_field('dhc_bulk_action') ?>
            <input type="hidden" name="action" value="bulk_delete">
            <div style="margin-bottom:10px;">
                <button type="submit" class="button" onclick="return confirm('Delete selected entries?')">Delete Selected</button>
            </div>
            <table class="widefat striped" style="border-radius:8px;overflow:hidden;">
                <thead>
                    <tr style="background:#F8FAFC;">
                        <th style="width:30px;"><input type="checkbox" id="dhc-select-all"></th>
                        <th>Date</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Company</th>
                        <th>Service</th>
                        <th>Budget</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($entries as $e): ?>
                <tr style="<?= $e->status==='unread' ? 'background:#F0FDFA;font-weight:600;' : '' ?>">
                    <td><input type="checkbox" name="entry_ids[]" value="<?= $e->id ?>"></td>
                    <td style="white-space:nowrap;font-size:12px;color:#6B7280;"><?= esc_html(date('M j, Y g:ia', strtotime($e->submitted_at))) ?></td>
                    <td><?= esc_html($e->name) ?></td>
                    <td><a href="mailto:<?= esc_attr($e->email) ?>"><?= esc_html($e->email) ?></a></td>
                    <td><?= esc_html($e->company) ?></td>
                    <td><span style="background:#E0F2FE;color:#0369A1;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;">
                        <?= esc_html($service_labels[$e->service] ?? $e->service) ?>
                    </span></td>
                    <td style="font-size:12px;"><?= esc_html($e->budget) ?></td>
                    <td>
                        <?php if($e->status==='unread'): ?>
                        <span style="background:#0D9488;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;">NEW</span>
                        <?php else: ?>
                        <span style="background:#E2E8F0;color:#6B7280;padding:2px 8px;border-radius:4px;font-size:11px;">READ</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <a href="#" class="button button-small dhc-view-btn"
                           data-id="<?= $e->id ?>"
                           data-name="<?= esc_attr($e->name) ?>"
                           data-email="<?= esc_attr($e->email) ?>"
                           data-company="<?= esc_attr($e->company) ?>"
                           data-service="<?= esc_attr($service_labels[$e->service] ?? $e->service) ?>"
                           data-budget="<?= esc_attr($e->budget) ?>"
                           data-timeline="<?= esc_attr($e->timeline) ?>"
                           data-source="<?= esc_attr($e->source) ?>"
                           data-message="<?= esc_attr($e->message) ?>"
                           data-date="<?= esc_attr(date('M j, Y g:ia', strtotime($e->submitted_at))) ?>">
                           View
                        </a>
                        <?php if($e->status==='unread'): ?>
                        <a href="<?= admin_url('admin.php?page=dh-contact-entries&action=mark_read&entry_id='.$e->id) ?>"
                           class="button button-small">Mark Read</a>
                        <?php endif; ?>
                        <a href="<?= wp_nonce_url(admin_url('admin.php?page=dh-contact-entries&action=delete&entry_id='.$e->id), 'dhc_delete_'.$e->id) ?>"
                           class="button button-small" style="color:#dc2626;"
                           onclick="return confirm('Delete this entry?')">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <!-- View Modal -->
        <div id="dhc-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100000;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:12px;padding:32px;max-width:600px;width:90%;max-height:80vh;overflow-y:auto;position:relative;">
                <button id="dhc-modal-close" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:24px;cursor:pointer;color:#6B7280;">&times;</button>
                <h2 id="dhc-modal-name" style="margin:0 0 4px;font-size:20px;"></h2>
                <p id="dhc-modal-date" style="margin:0 0 20px;font-size:12px;color:#6B7280;"></p>
                <div id="dhc-modal-body" style="font-size:14px;line-height:1.8;"></div>
                <div style="margin-top:20px;">
                    <a id="dhc-modal-reply" href="#" class="button button-primary">Reply by Email</a>
                </div>
            </div>
        </div>
        <script>
        document.querySelectorAll('.dhc-view-btn').forEach(btn => {
            btn.addEventListener('click', e => {
                e.preventDefault();
                const d = btn.dataset;
                document.getElementById('dhc-modal-name').textContent = d.name;
                document.getElementById('dhc-modal-date').textContent = d.date;
                document.getElementById('dhc-modal-body').innerHTML =
                    `<table style="width:100%;border-collapse:collapse;">
                    <tr><td style="padding:6px 0;color:#6B7280;width:100px;">Email</td><td><a href="mailto:${d.email}">${d.email}</a></td></tr>
                    ${d.company ? `<tr><td style="padding:6px 0;color:#6B7280;">Company</td><td>${d.company}</td></tr>` : ''}
                    <tr><td style="padding:6px 0;color:#6B7280;">Service</td><td>${d.service}</td></tr>
                    ${d.budget ? `<tr><td style="padding:6px 0;color:#6B7280;">Budget</td><td>${d.budget}</td></tr>` : ''}
                    ${d.timeline ? `<tr><td style="padding:6px 0;color:#6B7280;">Timeline</td><td>${d.timeline}</td></tr>` : ''}
                    ${d.source ? `<tr><td style="padding:6px 0;color:#6B7280;">Source</td><td>${d.source}</td></tr>` : ''}
                    </table>
                    <hr style="margin:16px 0;border:none;border-top:1px solid #E2E8F0;">
                    <p style="white-space:pre-wrap;">${d.message}</p>`;
                document.getElementById('dhc-modal-reply').href = `mailto:${d.email}?subject=Re: Your enquiry`;
                document.getElementById('dhc-modal').style.display = 'flex';
            });
        });
        document.getElementById('dhc-modal-close').addEventListener('click', () => {
            document.getElementById('dhc-modal').style.display = 'none';
        });
        document.getElementById('dhc-select-all').addEventListener('change', function() {
            document.querySelectorAll('input[name="entry_ids[]"]').forEach(cb => cb.checked = this.checked);
        });
        </script>
        <?php endif; ?>
    </div>
    <?php
}

// ── CSV EXPORT ────────────────────────────────────────────────────────────
function dhc_export_csv() {
    if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
    global $wpdb;
    $entries = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}dh_contact_entries ORDER BY submitted_at DESC", ARRAY_A );

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="dh-contact-entries-' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out, ['ID','Date','Status','Name','Email','Company','Service','Budget','Timeline','Message','Source','IP']);
    foreach($entries as $row) {
        fputcsv($out, [
            $row['id'], $row['submitted_at'], $row['status'],
            $row['name'], $row['email'], $row['company'],
            $row['service'], $row['budget'], $row['timeline'],
            $row['message'], $row['source'], $row['ip_address'],
        ]);
    }
    fclose($out);
    exit;
}

// ── ADMIN: SETTINGS PAGE ──────────────────────────────────────────────────
function dhc_settings_page() {
    if ( isset( $_POST['dhc_save_settings'] ) ) {
        $post = wp_unslash( $_POST );
        check_admin_referer( 'dhc_settings' );
        $settings = [
            'notification_email' => sanitize_email( $post['notification_email'] ?? '' ),
            'cc_email'           => sanitize_email( $post['cc_email'] ?? '' ),
            'success_message'    => sanitize_text_field( $post['success_message'] ?? '' ),
            'redirect_url'       => esc_url_raw( $post['redirect_url'] ?? '' ),
            'honeypot'           => isset( $post['honeypot'] ) ? '1' : '0',
            'store_entries'      => isset( $post['store_entries'] ) ? '1' : '0',
        ];
        update_option( 'dh_contact_settings', $settings );
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }

    $s = get_option( 'dh_contact_settings', [] );
    ?>
    <div class="wrap">
        <h1>DH Contact — Settings</h1>
        <form method="post" style="max-width:640px;margin-top:20px;">
            <?php wp_nonce_field('dhc_settings') ?>

            <div style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:28px;margin-bottom:20px;">
                <h2 style="margin-top:0;font-size:16px;border-bottom:1px solid #E2E8F0;padding-bottom:12px;">Email Notifications</h2>

                <table class="form-table" style="margin:0;">
                    <tr>
                        <th scope="row"><label for="notification_email">Notification Email</label></th>
                        <td>
                            <input type="email" id="notification_email" name="notification_email"
                                   value="<?= esc_attr($s['notification_email'] ?? get_option('admin_email')) ?>"
                                   class="regular-text">
                            <p class="description">Where to send new submission alerts.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cc_email">CC Email</label></th>
                        <td>
                            <input type="email" id="cc_email" name="cc_email"
                                   value="<?= esc_attr($s['cc_email'] ?? '') ?>"
                                   class="regular-text">
                            <p class="description">Optional. Leave blank if not needed.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:28px;margin-bottom:20px;">
                <h2 style="margin-top:0;font-size:16px;border-bottom:1px solid #E2E8F0;padding-bottom:12px;">After Submission</h2>

                <table class="form-table" style="margin:0;">
                    <tr>
                        <th scope="row"><label for="success_message">Success Message</label></th>
                        <td>
                            <input type="text" id="success_message" name="success_message"
                                   value="<?= esc_attr($s['success_message'] ?? "Thank you — I'll be in touch within 24 hours.") ?>"
                                   class="large-text">
                            <p class="description">Shown inline after successful submission. Leave blank if using a redirect.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="redirect_url">Redirect URL</label></th>
                        <td>
                            <input type="url" id="redirect_url" name="redirect_url"
                                   value="<?= esc_attr($s['redirect_url'] ?? '') ?>"
                                   class="large-text"
                                   placeholder="https://www.deepakhasija.com/thank-you/">
                            <p class="description">Optional. If set, redirects to this URL after submission instead of showing the success message.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div style="background:#fff;border:1px solid #E2E8F0;border-radius:10px;padding:28px;margin-bottom:20px;">
                <h2 style="margin-top:0;font-size:16px;border-bottom:1px solid #E2E8F0;padding-bottom:12px;">Security & Storage</h2>

                <table class="form-table" style="margin:0;">
                    <tr>
                        <th scope="row">Honeypot Spam Protection</th>
                        <td>
                            <label>
                                <input type="checkbox" name="honeypot" value="1" <?= checked($s['honeypot'] ?? '1', '1') ?>>
                                Enable honeypot field (catches bots silently)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Store Entries</th>
                        <td>
                            <label>
                                <input type="checkbox" name="store_entries" value="1" <?= checked($s['store_entries'] ?? '1', '1') ?>>
                                Save submissions to the database
                            </label>
                            <p class="description">Disable if you only want email notifications and no database storage.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div style="background:#F0FDFA;border:1px solid rgba(13,148,136,0.3);border-radius:10px;padding:20px;margin-bottom:20px;">
                <h3 style="margin:0 0 8px;font-size:14px;color:#0D9488;">Integration note</h3>
                <p style="margin:0;font-size:13px;color:#065F46;line-height:1.6;">
                    The form on your contact page submits via AJAX to <code>admin-ajax.php</code> using the action <code>dh_contact_submit</code>.
                    Make sure the contact page loads the plugin's JavaScript by visiting any page — the script is enqueued site-wide.
                    The nonce is refreshed on every page load for security.
                </p>
            </div>

            <p><input type="submit" name="dhc_save_settings" value="Save Settings" class="button button-primary button-large"></p>
        </form>
    </div>
    <?php
}

// ── ADMIN BAR UNREAD COUNT ────────────────────────────────────────────────
add_action('admin_bar_menu', 'dhc_admin_bar_count', 100);
function dhc_admin_bar_count($wp_admin_bar) {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $unread = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dh_contact_entries WHERE status = 'unread'");
    if ($unread === 0) return;
    $wp_admin_bar->add_node([
        'id'    => 'dhc-unread',
        'title' => '📬 ' . $unread . ' new enquir' . ($unread === 1 ? 'y' : 'ies'),
        'href'  => admin_url('admin.php?page=dh-contact-entries&status=unread'),
        'meta'  => ['class' => 'dhc-unread-indicator'],
    ]);
}
