<?php
/**
 * Module: Support Bridge (OVERHAUL_CHECKLIST.md Phase 3 item 36)
 *
 * Two REAL, independently-working support ticket systems exist today:
 * legacy's own (wp_raffle_support_tickets/_messages, Phase 0 item 3 —
 * the actual fix for support.php's completely fake "Submit Ticket"
 * button) and this app's separately-migrated Laravel one
 * (support_tickets/support_ticket_messages, item 20). Both are real;
 * they're just two different filing cabinets. This file gives legacy
 * PHP a way to read/write the SAME tables the Laravel side uses
 * directly (shared database, no HTTP call needed — same architecture
 * as every other bridge in this migration), so there is exactly one
 * real ticket queue once the flag below is on.
 *
 * Gated behind rk_support_unified_enabled() (a WP option, default off).
 * Unlike wallet-bridge.php, this is a full redirect, not a dual-write:
 * once the flag is on, legacy's own tables simply stop being written to
 * — there is no financial risk here that calls for keeping two records
 * in sync, so there is nothing to gain from writing both. Turning the
 * flag off goes back to the legacy tables exactly as they were.
 *
 * IMPORTANT — before turning this on, every ticket legacy already has
 * needs to exist on the new tables too, or a user (and the admin queue)
 * would see their ticket history vanish. See
 * raffle-api/app/Console/Commands/ImportLegacySupportTickets.php
 * (`php artisan legacy:import-support-tickets`) — run that FIRST.
 *
 * Status mapping: the new schema's `status` enum is open/pending/
 * resolved/closed — there is no 'answered' the way legacy has. This
 * bridge uses 'pending' for what legacy called 'answered' ("responded
 * to, not yet closed") — see rk_support_bridge_set_status_after_admin_reply().
 */

if (!defined('ABSPATH')) {
    exit;
}

function rk_support_unified_enabled() {
    return get_option('rk_support_unified_enabled', '0') === '1';
}

/** @return array The same shape rk_support_format_ticket() has always returned. */
function rk_support_bridge_format_ticket($ticket_id, $with_messages = false) {
    global $wpdb;

    $ticket = $wpdb->get_row($wpdb->prepare('SELECT * FROM support_tickets WHERE id = %d', $ticket_id), ARRAY_A);
    if (!$ticket) {
        return null;
    }

    $messages = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM support_ticket_messages WHERE support_ticket_id = %d ORDER BY created_at ASC, id ASC',
        $ticket_id
    ), ARRAY_A);

    $data = [
        'id' => (int) $ticket['id'],
        'category' => $ticket['subject'], // legacy sets subject = category at creation; see rk_support_bridge_create_ticket()
        'subject' => $ticket['subject'],
        'status' => $ticket['status'],
        'created_at' => $ticket['created_at'],
        'updated_at' => $ticket['updated_at'],
    ];

    $data['message_count'] = count($messages);
    $last = end($messages);
    $data['last_message'] = $last ? $last['message'] : '';
    $data['last_sender_type'] = $last ? ($last['is_from_admin'] ? 'admin' : 'user') : 'user';

    if ($with_messages) {
        $data['messages'] = array_map(fn ($m) => [
            'id' => (int) $m['id'],
            'sender_type' => $m['is_from_admin'] ? 'admin' : 'user',
            'message' => $m['message'],
            'created_at' => $m['created_at'],
        ], $messages);
    }

    return $data;
}

/** @return array<int, array> This user's own tickets, newest-updated first. */
function rk_support_bridge_list_tickets($user_id) {
    global $wpdb;

    $ids = $wpdb->get_col($wpdb->prepare(
        'SELECT id FROM support_tickets WHERE user_id = %d ORDER BY updated_at DESC',
        $user_id
    ));

    return array_map(fn ($id) => rk_support_bridge_format_ticket((int) $id), $ids);
}

/**
 * Ownership-checked: returns null (caller returns the same 404 an
 * unowned/nonexistent ticket already gets) if this ticket isn't the
 * given user's — same guarantee rk_get_support_ticket() always gave.
 */
function rk_support_bridge_get_ticket_for_user($ticket_id, $user_id) {
    global $wpdb;

    $owner = $wpdb->get_var($wpdb->prepare('SELECT user_id FROM support_tickets WHERE id = %d', $ticket_id));
    if ($owner === null || (int) $owner !== (int) $user_id) {
        return null;
    }

    return rk_support_bridge_format_ticket($ticket_id, true);
}

/** @return array The newly-created ticket, with its first message. */
function rk_support_bridge_create_ticket($user_id, $category, $message) {
    global $wpdb;
    $now = current_time('mysql');

    // Legacy always sets subject = category at creation — the first
    // message carries the actual detail. Mirrored here so nothing is lost.
    $wpdb->insert('support_tickets', [
        'user_id' => $user_id,
        'subject' => $category,
        'status' => 'open',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $ticket_id = $wpdb->insert_id;

    $wpdb->insert('support_ticket_messages', [
        'support_ticket_id' => $ticket_id,
        'author_id' => $user_id,
        'is_from_admin' => 0,
        'message' => $message,
        'created_at' => $now,
    ]);

    return rk_support_bridge_format_ticket($ticket_id, true);
}

/**
 * @param  bool  $is_admin  Whether this reply is from an admin (skips
 *                          the ownership check — an admin can reply to any ticket).
 * @return array|null The updated ticket, or null if a non-admin reply's
 *                     ownership check fails (caller returns 404).
 */
function rk_support_bridge_reply($ticket_id, $author_id, $message, $is_admin = false) {
    global $wpdb;
    $now = current_time('mysql');

    if (!$is_admin) {
        $owner = $wpdb->get_var($wpdb->prepare('SELECT user_id FROM support_tickets WHERE id = %d', $ticket_id));
        if ($owner === null || (int) $owner !== (int) $author_id) {
            return null;
        }
    }

    $wpdb->insert('support_ticket_messages', [
        'support_ticket_id' => $ticket_id,
        'author_id' => $author_id,
        'is_from_admin' => $is_admin ? 1 : 0,
        'message' => $message,
        'created_at' => $now,
    ]);

    // A user reply re-opens the ticket (same as legacy); an admin reply
    // marks it 'pending' — legacy's 'answered' equivalent, see this
    // file's own docblock.
    $wpdb->update('support_tickets', [
        'status' => $is_admin ? 'pending' : 'open',
        'updated_at' => $now,
    ], ['id' => $ticket_id]);

    return rk_support_bridge_format_ticket($ticket_id, true);
}

function rk_support_bridge_set_status($ticket_id, $status) {
    global $wpdb;

    $wpdb->update('support_tickets', [
        'status' => $status,
        'updated_at' => current_time('mysql'),
    ], ['id' => $ticket_id]);
}

/**
 * The admin queue — mirrors rk_render_support_page()'s own filtered
 * list query, translating the legacy 'answered' filter value onto the
 * new table's 'pending' status.
 *
 * @return array<int, array> Each with its full message thread already loaded.
 */
function rk_support_bridge_admin_list($status_filter) {
    global $wpdb;

    $status = $status_filter === 'answered' ? 'pending' : $status_filter;

    if ($status && $status !== 'all') {
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM support_tickets WHERE status = %s ORDER BY updated_at DESC LIMIT 100',
            $status
        ));
    } else {
        $ids = $wpdb->get_col('SELECT id FROM support_tickets ORDER BY updated_at DESC LIMIT 100');
    }

    return array_map(fn ($id) => rk_support_bridge_format_ticket((int) $id, true), $ids);
}
