<?php
/**
 * Module: Support Tickets
 * Phase 0 item 3 — a real, working ticket system to replace the fake one that
 * used to live entirely in support.php's JavaScript (it never sent anything
 * anywhere; "Your Conversations" was two hardcoded tickets that were the same
 * for every visitor). Tickets and their reply threads are now real rows a
 * user and an admin can both actually see and act on.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function rk_support_format_ticket($ticket, $with_messages = false) {
    global $wpdb;
    $table_messages = $wpdb->prefix . 'raffle_support_messages';

    $data = [
        'id' => (int) $ticket->id,
        'category' => $ticket->category,
        'subject' => $ticket->subject,
        'status' => $ticket->status,
        'created_at' => $ticket->created_at,
        'updated_at' => $ticket->updated_at,
    ];

    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_messages WHERE ticket_id = %d ORDER BY created_at ASC, id ASC",
        $ticket->id
    ));

    $data['message_count'] = count($messages);
    $last = end($messages);
    $data['last_message'] = $last ? $last->message : '';
    $data['last_sender_type'] = $last ? $last->sender_type : 'user';

    if ($with_messages) {
        $data['messages'] = array_map(function ($m) {
            return [
                'id' => (int) $m->id,
                'sender_type' => $m->sender_type,
                'message' => $m->message,
                'created_at' => $m->created_at,
            ];
        }, $messages);
    }

    return $data;
}

/**
 * GET /support/tickets — the current user's own ticket list, newest first.
 */
function rk_get_support_tickets($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);

    global $wpdb;
    $table_tickets = $wpdb->prefix . 'raffle_support_tickets';

    $tickets = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_tickets WHERE user_id = %d ORDER BY updated_at DESC",
        $user_id
    ));

    return array_map('rk_support_format_ticket', $tickets);
}

/**
 * GET /support/ticket — one ticket with its full thread.
 * Ownership-checked: a ticket that isn't this user's returns the same
 * 404 as one that doesn't exist, so a guessed ID can't confirm another
 * user's ticket exists.
 */
function rk_get_support_ticket($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);

    $ticket_id = (int) $request->get_param('id');
    if (!$ticket_id) return new WP_Error('missing_id', 'Ticket ID is required', ['status' => 400]);

    global $wpdb;
    $table_tickets = $wpdb->prefix . 'raffle_support_tickets';
    $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_tickets WHERE id = %d", $ticket_id));

    if (!$ticket || (int) $ticket->user_id !== $user_id) {
        return new WP_Error('not_found', 'Ticket not found', ['status' => 404]);
    }

    return rk_support_format_ticket($ticket, true);
}

/**
 * POST /support/ticket — open a new ticket with its first message.
 */
function rk_create_support_ticket($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);

    if (function_exists('rk_check_rate_limit')) {
        $limit_check = rk_check_rate_limit('support_ticket_create', 5, 300);
        if (is_wp_error($limit_check)) return $limit_check;
    }

    $category = sanitize_text_field($request->get_param('category') ?: 'General Inquiry');
    $message = sanitize_textarea_field($request->get_param('message'));

    if (empty($message)) {
        return new WP_Error('missing_message', 'Please describe your issue.', ['status' => 400]);
    }
    if (mb_strlen($message) > 2000) {
        return new WP_Error('message_too_long', 'Message is too long (max 2000 characters).', ['status' => 400]);
    }

    global $wpdb;
    $table_tickets = $wpdb->prefix . 'raffle_support_tickets';
    $table_messages = $wpdb->prefix . 'raffle_support_messages';

    // The ticket's subject is the category — the first message carries the detail.
    $wpdb->insert($table_tickets, [
        'user_id' => $user_id,
        'category' => $category,
        'subject' => $category,
        'status' => 'open',
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ]);
    $ticket_id = $wpdb->insert_id;

    $wpdb->insert($table_messages, [
        'ticket_id' => $ticket_id,
        'sender_type' => 'user',
        'sender_id' => $user_id,
        'message' => $message,
        'created_at' => current_time('mysql'),
    ]);

    $user = get_userdata($user_id);
    if (function_exists('rk_send_telegram_alert')) {
        rk_send_telegram_alert(
            "🎫 <b>New Support Ticket</b>\n" .
            "From: " . ($user ? esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')' : 'User #' . $user_id) . "\n" .
            "Category: " . esc_html($category) . "\n" .
            "Message: " . esc_html(mb_substr($message, 0, 300))
        );
    }

    return [
        'success' => true,
        'message' => 'Ticket submitted. Our team will reply soon.',
        'ticket' => rk_support_format_ticket($wpdb->get_row($wpdb->prepare("SELECT * FROM $table_tickets WHERE id = %d", $ticket_id)), true),
    ];
}

/**
 * POST /support/ticket/reply — the user adds a message to their own ticket.
 * Re-opens a ticket the user replies to after it was marked resolved.
 */
function rk_reply_support_ticket($request) {
    $user_id = get_current_user_id();
    if (!$user_id) return new WP_Error('no_auth', 'Not logged in', ['status' => 401]);

    if (function_exists('rk_check_rate_limit')) {
        $limit_check = rk_check_rate_limit('support_ticket_reply', 10, 300);
        if (is_wp_error($limit_check)) return $limit_check;
    }

    $ticket_id = (int) $request->get_param('id');
    $message = sanitize_textarea_field($request->get_param('message'));

    if (!$ticket_id) return new WP_Error('missing_id', 'Ticket ID is required', ['status' => 400]);
    if (empty($message)) return new WP_Error('missing_message', 'Message cannot be empty.', ['status' => 400]);
    if (mb_strlen($message) > 2000) return new WP_Error('message_too_long', 'Message is too long (max 2000 characters).', ['status' => 400]);

    global $wpdb;
    $table_tickets = $wpdb->prefix . 'raffle_support_tickets';
    $table_messages = $wpdb->prefix . 'raffle_support_messages';

    $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_tickets WHERE id = %d", $ticket_id));
    if (!$ticket || (int) $ticket->user_id !== $user_id) {
        return new WP_Error('not_found', 'Ticket not found', ['status' => 404]);
    }

    $wpdb->insert($table_messages, [
        'ticket_id' => $ticket_id,
        'sender_type' => 'user',
        'sender_id' => $user_id,
        'message' => $message,
        'created_at' => current_time('mysql'),
    ]);
    $wpdb->update($table_tickets, [
        'status' => 'open',
        'updated_at' => current_time('mysql'),
    ], ['id' => $ticket_id]);

    $user = get_userdata($user_id);
    if (function_exists('rk_send_telegram_alert')) {
        rk_send_telegram_alert(
            "🎫 <b>Support Ticket Reply</b> (#$ticket_id)\n" .
            "From: " . ($user ? esc_html($user->display_name) : 'User #' . $user_id) . "\n" .
            "Message: " . esc_html(mb_substr($message, 0, 300))
        );
    }

    return [
        'success' => true,
        'message' => 'Reply sent.',
        'ticket' => rk_support_format_ticket($wpdb->get_row($wpdb->prepare("SELECT * FROM $table_tickets WHERE id = %d", $ticket_id)), true),
    ];
}

/**
 * Admin console page for the support queue: read every ticket's thread,
 * reply (emails the user), and change status. This is the other half of
 * closing the support gap — a ticket a real person actually answers, not
 * just a row nobody looks at.
 */
function rk_render_support_page() {
    global $wpdb;
    $table_tickets = $wpdb->prefix . 'raffle_support_tickets';
    $table_messages = $wpdb->prefix . 'raffle_support_messages';

    if (isset($_POST['rk_support_ticket_id']) && check_admin_referer('rk_support_action')) {
        $ticket_id = intval($_POST['rk_support_ticket_id']);
        $admin_id = get_current_user_id();

        if (!empty($_POST['rk_support_reply'])) {
            $reply = sanitize_textarea_field($_POST['rk_support_reply']);
            $wpdb->insert($table_messages, [
                'ticket_id' => $ticket_id,
                'sender_type' => 'admin',
                'sender_id' => $admin_id,
                'message' => $reply,
                'created_at' => current_time('mysql'),
            ]);
            $wpdb->update($table_tickets, ['status' => 'answered', 'updated_at' => current_time('mysql')], ['id' => $ticket_id]);

            $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_tickets WHERE id = %d", $ticket_id));
            if ($ticket) {
                $user = get_userdata($ticket->user_id);
                if ($user && function_exists('rk_send_email')) {
                    $body = "<p>Hi " . esc_html($user->display_name) . ",</p><p>We replied to your support ticket:</p><blockquote>" . nl2br(esc_html($reply)) . "</blockquote>";
                    $message = function_exists('rk_get_email_html') ? rk_get_email_html("Support Reply", $body, "View Ticket →", (defined('RK_FRONTEND_URL') ? RK_FRONTEND_URL : '') . "/support.php") : $body;
                    rk_send_email($user->user_email, "We replied to your support ticket", $message);
                }
            }
            echo '<div class="notice notice-success is-dismissible"><p>Reply sent.</p></div>';
        }

        if (isset($_POST['rk_support_status']) && $_POST['rk_support_status'] !== '') {
            $status = sanitize_text_field($_POST['rk_support_status']);
            if (in_array($status, ['open', 'answered', 'resolved', 'closed'])) {
                $wpdb->update($table_tickets, ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $ticket_id]);
            }
        }
    }

    $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : 'open';
    $where = "1=1";
    if ($filter_status && $filter_status !== 'all') {
        $where = $wpdb->prepare("status = %s", $filter_status);
    }
    $tickets = $wpdb->get_results("SELECT * FROM $table_tickets WHERE $where ORDER BY updated_at DESC LIMIT 100");
    ?>
    <div class="wrap">
        <h1>\xf0\x9f\x8e\xab Support Tickets</h1>
        <p>Real conversations from support.php — replies email the user and post back to their ticket thread.</p>

        <div style="margin: 15px 0;">
            <a href="?page=raffle-support&filter_status=open" class="button <?php echo $filter_status === 'open' ? 'button-primary' : ''; ?>">Open</a>
            <a href="?page=raffle-support&filter_status=answered" class="button <?php echo $filter_status === 'answered' ? 'button-primary' : ''; ?>">Answered</a>
            <a href="?page=raffle-support&filter_status=resolved" class="button <?php echo $filter_status === 'resolved' ? 'button-primary' : ''; ?>">Resolved</a>
            <a href="?page=raffle-support&filter_status=all" class="button <?php echo $filter_status === 'all' ? 'button-primary' : ''; ?>">All</a>
        </div>

        <?php if (empty($tickets)): ?>
            <div style="background:white; padding:20px; text-align:center; color:#888;">No tickets in this view.</div>
        <?php else: foreach ($tickets as $t):
            $user = get_userdata($t->user_id);
            $messages = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_messages WHERE ticket_id = %d ORDER BY created_at ASC, id ASC", $t->id));
        ?>
            <div style="background:white; padding:18px; margin-bottom:15px; border-left:4px solid <?php echo $t->status === 'open' ? '#f59e0b' : ($t->status === 'answered' ? '#2563eb' : '#16a34a'); ?>; box-shadow:0 1px 2px rgba(0,0,0,0.1);">
                <div style="display:flex; justify-content:space-between;">
                    <div>
                        <strong>#<?php echo (int) $t->id; ?> — <?php echo esc_html($t->subject); ?></strong><br>
                        <span style="font-size:12px; color:#666;"><?php echo $user ? esc_html($user->display_name . ' (' . $user->user_email . ')') : 'Unknown user'; ?></span>
                    </div>
                    <span style="font-size:11px; font-weight:bold; text-transform:uppercase; color:#555;"><?php echo esc_html($t->status); ?></span>
                </div>

                <div style="margin:12px 0; max-height:220px; overflow-y:auto;">
                    <?php foreach ($messages as $m): $is_admin = $m->sender_type === 'admin'; ?>
                        <div style="background:<?php echo $is_admin ? '#eff6ff' : '#f9fafb'; ?>; padding:8px 12px; border-radius:6px; margin-bottom:6px;">
                            <div style="font-size:10px; font-weight:bold; color:<?php echo $is_admin ? '#2563eb' : '#555'; ?>;"><?php echo $is_admin ? 'Support (Admin)' : 'User'; ?> — <?php echo esc_html($m->created_at); ?></div>
                            <div style="font-size:13px; margin-top:2px;"><?php echo nl2br(esc_html($m->message)); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <form method="POST" style="display:flex; gap:8px; align-items:flex-start;">
                    <?php wp_nonce_field('rk_support_action'); ?>
                    <input type="hidden" name="rk_support_ticket_id" value="<?php echo (int) $t->id; ?>">
                    <textarea name="rk_support_reply" rows="2" placeholder="Type a reply..." style="flex:1; padding:8px;"></textarea>
                    <div style="display:flex; flex-direction:column; gap:6px;">
                        <button type="submit" class="button button-primary">Send Reply</button>
                        <select name="rk_support_status" onchange="this.form.submit()" style="height:30px;">
                            <option value="">Set status…</option>
                            <option value="open" <?php selected($t->status, 'open'); ?>>Open</option>
                            <option value="answered" <?php selected($t->status, 'answered'); ?>>Answered</option>
                            <option value="resolved" <?php selected($t->status, 'resolved'); ?>>Resolved</option>
                            <option value="closed" <?php selected($t->status, 'closed'); ?>>Closed</option>
                        </select>
                    </div>
                </form>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <?php
}
