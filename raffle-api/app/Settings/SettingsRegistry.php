<?php

namespace App\Settings;

/**
 * Every setting the admin can change, grouped the way the Settings page
 * shows them: tab → section → settings. Each one overrides a config
 * value, so adding a setting here is all it takes to make it editable.
 *
 * Deliberately NOT here: the database login, APP_KEY (it encrypts the
 * secrets stored below) and the WordPress cookie keys. Getting those
 * wrong from a web page would lock everyone out, including the admin
 * who'd need to fix it — they stay in the server's .env file.
 */
final class SettingsRegistry
{
    /**
     * @return array<string, array{icon: string, sections: array<string, array{description?: string, settings: list<Setting>}>}>
     */
    public static function tabs(): array
    {
        return [
            'General' => [
                'icon' => 'heroicon-o-home',
                'sections' => [
                    'Site' => [
                        'description' => 'Name and contact details customers see.',
                        'settings' => [
                            new Setting('app.name', 'Site name', 'text', 'Used in emails and page titles.', rules: ['required', 'max:60']),
                            new Setting('site.support_email', 'Support email', 'email', 'Shown to customers who need help.'),
                            new Setting('site.support_phone', 'Support phone / WhatsApp number', 'text', 'Include the country code, e.g. +2348012345678.', rules: ['nullable', 'regex:/^\+?[0-9 ()-]{7,20}$/']),
                            new Setting('raffles.timezone', 'Business time zone', 'timezone', 'A raffle\'s "last day" ends at midnight in this time zone. Also used for the dashboard\'s "today".'),
                        ],
                    ],
                    'Links' => [
                        'description' => 'Where the site\'s buttons send people. Leave empty to hide a link.',
                        'settings' => [
                            new Setting('site.links.telegram_support', 'Telegram support', 'url', 'The "Chat with support" button on the profile page.', placeholder: 'https://t.me/...'),
                            new Setting('site.links.community', 'Community group', 'url', 'Opened by the "Join our Community" reward task.', placeholder: 'https://t.me/... or https://chat.whatsapp.com/...'),
                            new Setting('site.links.whatsapp_channel', 'WhatsApp channel', 'url', 'Opened by the "Follow on WhatsApp" reward task.', placeholder: 'https://whatsapp.com/channel/...'),
                            new Setting('site.links.instagram', 'Instagram', 'url'),
                            new Setting('site.links.facebook', 'Facebook', 'url'),
                            new Setting('site.links.x', 'X (Twitter)', 'url'),
                            new Setting('site.links.tiktok', 'TikTok', 'url'),
                        ],
                    ],
                ],
            ],

            'On / off' => [
                'icon' => 'heroicon-o-power',
                'sections' => [
                    'Maintenance mode' => [
                        'description' => 'Take the customer site offline for a while. Customers see a friendly "back soon" page with a countdown. You and other staff can still use the site and this admin, and payments already made are still credited. Switch it on now, or schedule a start time; it switches itself off at the "back at" time.',
                        'settings' => [
                            new Setting('site.maintenance.enabled', 'Maintenance mode on now', 'bool'),
                            new Setting('site.maintenance.starts_at', 'Or start automatically at', 'datetime', 'For planned work. Leave empty if you switched it on above.'),
                            new Setting('site.maintenance.back_at', 'Back at (switches off by itself)', 'datetime', 'Shown to customers as a countdown. Leave empty to switch it off by hand.'),
                            new Setting('site.maintenance.warn_hours', 'Warn customers this many hours before a planned start', 'int', 'A banner on every page. 0 = no warning.', rules: ['required', 'integer', 'min:0', 'max:168']),
                            new Setting('site.maintenance.message', 'Message on the maintenance page', 'textarea', rules: ['required', 'max:300']),
                        ],
                    ],
                    'Pause parts of the site' => [
                        'description' => 'Switch something off instantly during a problem, maintenance or a payment-provider outage, and back on when ready. Customers see the message below instead. Admin screens keep working.',
                        'settings' => [
                            new Setting('site.switches.ticket_sales', 'Ticket sales', 'bool'),
                            new Setting('site.switches.deposits', 'Wallet top-ups (Paystack / Flutterwave)', 'bool'),
                            new Setting('site.switches.withdrawals', 'Withdrawal requests', 'bool', 'Requests already made stay in the queue for you to pay.'),
                            new Setting('site.switches.registrations', 'New sign-ups', 'bool', 'Existing customers can still log in.'),
                            new Setting('site.switches.live_chat', 'Live-draw chat', 'bool', 'Viewers can still watch and react.'),
                            new Setting('site.switches.daily_claim', 'Daily reward claim', 'bool'),
                            new Setting('site.switches.tasks', 'Reward tasks', 'bool'),
                            new Setting('site.switches.spin', 'Spin & Win', 'bool'),
                            new Setting('site.switches.season_pass', 'Season Pass (collecting rewards)', 'bool'),
                            new Setting('site.switches.predictions', 'Daily predictions', 'bool'),
                            new Setting('site.switches.team_up', 'Team Up', 'bool'),
                            new Setting('site.switches.unlock_links', '"Help me unlock" links', 'bool'),
                            new Setting('site.switches.red_envelopes', 'Red envelopes', 'bool'),
                            new Setting('site.switches.winner_stories', 'Winner stories (posting)', 'bool'),
                            new Setting('site.switches.point_redemption', 'Cash in points', 'bool'),
                            new Setting('site.paused_message', 'Message shown when something is paused', 'textarea', rules: ['required', 'max:200']),
                        ],
                    ],
                    'New features' => [
                        'description' => 'Each starts OFF. While off, customers don\'t see it at all (no greyed-out buttons) and nothing runs in the background. The staff screens stay in the menu marked "Off", so you can set things up before switching on. Their numbers and keys are on the other tabs.',
                        'settings' => [
                            new Setting('features.bank_name_check', 'Bank-name check (Paystack)', 'bool', 'Customers pick their bank from a list and Paystack fills in the account name. Stops most wrong-account payouts. Needs the Paystack secret key.'),
                            new Setting('features.auto_payouts', 'Automatic payouts (Paystack transfers)', 'bool', 'Adds "Send with Paystack" to Withdrawals: one tap sends the money and marks it paid when the bank confirms. Needs the bank-name check, and Transfers enabled on your Paystack account. Settings → Withdrawals has the limits.'),
                            new Setting('features.reminders', 'Reminders (push, email, WhatsApp)', 'bool', '"This raffle ends in 1 hour" and "You left tickets in checkout". Settings → Reminders.'),
                            new Setting('features.promo_codes', 'Promo codes', 'bool', 'A code box at sign-up and checkout. Create codes under Growth → Promo codes.'),
                            new Setting('features.affiliates', 'Affiliate links', 'bool', 'Influencers get their own link and earnings page. Add them under Growth → Affiliates.'),
                            new Setting('features.abuse_detection', 'Multi-account protection', 'bool', 'Holds referral and affiliate rewards when two accounts share a phone, device or bank account, until staff check them on Fraud watch.'),
                            new Setting('features.status_page', 'Public status page', 'bool', 'A /status page showing which parts of the site are working, with your message. Settings → Alerts & push → Status page.'),
                        ],
                    ],
                ],
            ],

            'Payments' => [
                'icon' => 'heroicon-o-credit-card',
                'sections' => [
                    'Top-ups' => [
                        'settings' => [
                            new Setting('payments.default_gateway', 'Main payment provider', 'select', 'Tried first for every top-up.', ['paystack' => 'Paystack', 'flutterwave' => 'Flutterwave']),
                            new Setting('payments.backup_gateway', 'Backup payment provider', 'select', 'Used automatically if the main one fails to start a payment.', ['' => 'None', 'paystack' => 'Paystack', 'flutterwave' => 'Flutterwave']),
                            new Setting('payments.minimum_deposit', 'Smallest top-up', 'money', rules: ['required', 'numeric', 'min:1']),
                            new Setting('payments.legacy_deposit_bonus_percent', 'Bonus on approved bank-transfer top-ups', 'percent', 'Extra % added to winnings when you approve a bank-transfer top-up. 0 = no bonus.', rules: ['required', 'numeric', 'min:0', 'max:100']),
                        ],
                    ],
                    'Paystack' => [
                        'description' => 'From your Paystack dashboard → Settings → API Keys & Webhooks. Webhook URL to paste there: {webhook:paystack}',
                        'settings' => [
                            new Setting('services.paystack.public_key', 'Public key', 'text', placeholder: 'pk_live_...', rules: ['nullable', 'starts_with:pk_']),
                            new Setting('services.paystack.secret_key', 'Secret key', 'secret', placeholder: 'sk_live_...', rules: ['nullable', 'starts_with:sk_']),
                        ],
                    ],
                    'Flutterwave' => [
                        'description' => 'From your Flutterwave dashboard → Settings → API Keys, and Webhooks. Webhook URL to paste there: {webhook:flutterwave}',
                        'settings' => [
                            new Setting('services.flutterwave.public_key', 'Public key', 'text', placeholder: 'FLWPUBK-...'),
                            new Setting('services.flutterwave.secret_key', 'Secret key', 'secret', placeholder: 'FLWSECK-...'),
                            new Setting('services.flutterwave.secret_hash', 'Webhook secret hash', 'secret', 'Any secret phrase. Type the same one in Flutterwave\'s webhook settings. It proves payment messages really come from Flutterwave.'),
                        ],
                    ],
                    'Gaming tax' => [
                        'description' => 'How the monthly gaming tax is worked out (Finance → Gaming tax). It is charged on a month\'s ticket sales minus the prizes won that month. A month keeps the rate and rule it was locked with, so changing these only affects months not yet locked.',
                        'settings' => [
                            new Setting('gaming_tax.rate', 'Tax rate', 'percent', 'In percent. Confirm the right rate with your accountant.', rules: ['required', 'numeric', 'min:0', 'max:100']),
                            new Setting('gaming_tax.shortfall', 'If prizes were worth more than sales in a month', 'select', 'Zero: that month owes no tax and the shortfall is forgotten. Carry forward: the shortfall is taken off the next month\'s taxable amount.', ['zero' => 'That month owes no tax (shortfall forgotten)', 'carry_forward' => 'Carry the shortfall into next month']),
                            new Setting('gaming_tax.due_day', 'Tax is due on this day of the next month', 'int', 'For the reminder on the Gaming tax page. Confirm the right day with your accountant.', rules: ['required', 'integer', 'min:1', 'max:28']),
                            new Setting('gaming_tax.business_name', 'Business name on the return', 'text', 'Printed at the top of the PDF return. Leave empty to print the site name.', rules: ['nullable', 'max:120']),
                            new Setting('gaming_tax.tax_id', 'Tax ID on the return', 'text', 'Your tax identification number, printed on the PDF return.', rules: ['nullable', 'max:60']),
                            new Setting('gaming_tax.remind', 'Remind staff when a return is due', 'bool', 'Emails staff who handle payouts (and messages the staff Telegram chat if it is set up) before a month\'s tax is due, on the day, and when it is overdue.'),
                            new Setting('gaming_tax.remind_days', 'Remind this many days before the due date', 'tags', 'Type a number of days and press Tab, e.g. 7, 3, 1. Staff are also reminded on the day, and every few days once it is overdue.'),
                        ],
                    ],
                ],
            ],

            'Withdrawals' => [
                'icon' => 'heroicon-o-arrow-up-tray',
                'sections' => [
                    'Withdrawal rules' => [
                        'settings' => [
                            new Setting('withdrawals.minimum_amount', 'Smallest withdrawal', 'money', rules: ['required', 'numeric', 'min:0']),
                            new Setting('withdrawals.verification_deposit_threshold', 'One-time verification applies below this lifetime top-up total', 'money', 'Customers who have topped up less than this in total pay the verification fee once, from their first withdrawal. Set 0 to switch the fee off.', rules: ['required', 'numeric', 'min:0']),
                            new Setting('withdrawals.verification_fee', 'One-time verification fee', 'money', rules: ['required', 'numeric', 'min:0']),
                        ],
                    ],
                    'Automatic payouts (Paystack)' => [
                        'description' => 'Switched on in On / off → New features. Before switching on, in your Paystack dashboard: (1) make sure Transfers are enabled for your business, (2) Settings → Preferences → untick "Confirm transfers before sending" (the OTP step), so the site can send without a code, (3) keep enough money in your Paystack balance. Paystack\'s transfer messages arrive on the same webhook URL as top-ups. Only accounts whose name Paystack confirmed (bank-name check) can be paid this way; older accounts are paid by hand as before.',
                        'settings' => [
                            new Setting('withdrawals.auto_payout_max', 'Biggest withdrawal sent automatically', 'money', 'Bigger ones show "pay by hand". 0 = no limit.', rules: ['required', 'numeric', 'min:0']),
                        ],
                    ],
                ],
            ],

            'Raffles & pricing' => [
                'icon' => 'heroicon-o-ticket',
                'sections' => [
                    'Raffle setup' => [
                        'settings' => [
                            new Setting('raffles.warn_prizes_exceed_sales', 'Warn me when a raffle\'s prizes are worth more than it can take in', 'bool', 'A heads-up on the raffle\'s prize levels table. It never stops you saving. Switch it off for planned loss-leader raffles.'),
                        ],
                    ],
                    'Raffle list' => [
                        'settings' => [
                            new Setting('raffles.list_closed_for_days', 'Keep finished raffles on the list for (days)', 'int', 'After this they only appear in the Hall of Fame.', rules: ['required', 'integer', 'min:0', 'max:365']),
                        ],
                    ],
                    'Order size' => [
                        'description' => 'Stop very large orders. The limit is checked when someone chooses tickets AND again when they pay, so it cannot be skipped.',
                        'settings' => [
                            new Setting('pricing.max_tickets_per_order', 'Most tickets in one order', 'int', 'Across every raffle. 0 means no limit. A single raffle can set its own limit on its edit page.', rules: ['required', 'integer', 'min:0', 'max:100000']),
                        ],
                    ],
                    'Bulk discounts' => [
                        'description' => 'The preview at the bottom shows exactly what customers will pay.',
                        'settings' => [
                            new Setting('pricing.cheap_ticket_max_price', 'A ticket counts as "cheap" at or below', 'money', rules: ['required', 'numeric', 'min:0']),
                            new Setting('pricing.cheap_bulk_min_quantity', 'Cheap tickets: discount from this many tickets', 'int', rules: ['required', 'integer', 'min:1']),
                            new Setting('pricing.cheap_bulk_percent_off', 'Cheap tickets: % off', 'percent', rules: ['required', 'numeric', 'min:0', 'max:90']),
                            new Setting('pricing.bundles', 'Other tickets: bundle sizes', 'bundles', 'Each bundle is a one-tap button on the raffle page. The discount applies to that exact number of tickets.'),
                            new Setting('pricing.above_quantity', 'Other tickets: big-order discount for more than', 'int', 'Tickets. Set 0 for no big-order discount.', rules: ['required', 'integer', 'min:0']),
                            new Setting('pricing.above_percent_off', 'Other tickets: big-order % off', 'percent', rules: ['required', 'numeric', 'min:0', 'max:90']),
                        ],
                    ],
                    'Golden Box' => [
                        'description' => 'A customer who leaves checkout without paying sees a gold offer on the raffle list: extra % off that same order, for a short time. Tapping it starts the discount. It can only be used once, on that raffle and number of tickets.',
                        'settings' => [
                            new Setting('pricing.golden_box_enabled', 'Offer the Golden Box', 'bool'),
                            new Setting('pricing.golden_box_percent_off', 'Extra % off', 'percent', 'Taken off after any bulk discount.', rules: ['required', 'numeric', 'min:0', 'max:90']),
                            new Setting('pricing.golden_box_minimum_order', 'Only for orders of at least', 'money', rules: ['required', 'numeric', 'min:0']),
                            new Setting('pricing.golden_box_offer_minutes', 'Show the offer for (minutes)', 'int', 'Counted from when the customer first sees it.', rules: ['required', 'integer', 'min:1', 'max:1440']),
                            new Setting('pricing.golden_box_claim_minutes', 'Discount lasts after tapping (minutes)', 'int', rules: ['required', 'integer', 'min:1', 'max:1440']),
                            new Setting('pricing.golden_box_cooldown_days', 'Days before a customer can get another one', 'int', '0 = every unpaid checkout can get one.', rules: ['required', 'integer', 'min:0', 'max:365']),
                        ],
                    ],
                ],
            ],

            'Rewards' => [
                'icon' => 'heroicon-o-gift',
                'sections' => [
                    'Daily claim' => [
                        'settings' => [
                            new Setting('rewards.daily_claim', 'Points for each day of the streak', 'daily_rewards', 'Day 7 is the streak\'s big reward; after it the streak starts again at day 1.'),
                        ],
                    ],
                    'Tasks' => [
                        'settings' => [
                            new Setting('rewards.tasks.push_notification', 'Turn on notifications (points)', 'int', rules: ['required', 'integer', 'min:0']),
                            new Setting('rewards.tasks.join_community', 'Join our community (points)', 'int', rules: ['required', 'integer', 'min:0']),
                            new Setting('rewards.tasks.whatsapp_follow', 'Follow on WhatsApp (points)', 'int', rules: ['required', 'integer', 'min:0']),
                            new Setting('rewards.tasks.whatsapp_share', 'Share on WhatsApp, daily (points)', 'int', rules: ['required', 'integer', 'min:0']),
                            new Setting('rewards.task_wait_seconds', 'Seconds between "Go" and "Claim"', 'int', 'Customers must open the link (community, WhatsApp channel, share) and wait this long before Claim works. The community and WhatsApp channel tasks only show once their links are set in General → Links.', rules: ['required', 'integer', 'min:0', 'max:600']),
                        ],
                    ],
                    'Spin & Win' => [
                        'settings' => [
                            new Setting('rewards.spin_cost', 'Points per spin', 'int', rules: ['required', 'integer', 'min:1']),
                            new Setting('rewards.spin_prizes', 'Prizes and their chances', 'spin_prizes', 'Chance = its weight ÷ all weights added up. Customers see these real odds.'),
                        ],
                    ],
                    'Points boost (promotion)' => [
                        'description' => 'Run a "double points weekend": daily-claim and task points are multiplied between the two times, and customers see a banner on the Rewards page. Spin & Win is not affected.',
                        'settings' => [
                            new Setting('rewards.boost.multiplier', 'Multiply points by', 'select', 'Choose "Off" to stop a boost early.', ['1' => 'Off', '1.5' => '×1.5', '2' => '×2 (double)', '3' => '×3 (triple)']),
                            new Setting('rewards.boost.label', 'Banner text', 'text', placeholder: 'Double points weekend!', rules: ['nullable', 'max:60']),
                            new Setting('rewards.boost.starts_at', 'Starts', 'datetime', 'Empty = starts as soon as you save.'),
                            new Setting('rewards.boost.ends_at', 'Ends', 'datetime', 'Required for a boost to run.'),
                        ],
                    ],
                    'Loyalty tiers' => [
                        'description' => 'Tiers reward playing regularly: a customer reaches a tier by playing in enough of the recent weeks AND buying enough tickets in that time. Customers see their tier and what the next one needs. A tier\'s perk is free bonus entries in raffles whose draw rules turn them on.',
                        'settings' => [
                            new Setting('loyalty.window_weeks', 'Weeks counted', 'int', 'How many recent weeks (Monday to Sunday, Lagos time) count towards a tier.', rules: ['required', 'integer', 'min:1', 'max:52']),
                            new Setting('loyalty.tiers', 'Tiers', 'loyalty_tiers', 'Each higher tier should need at least as much as the one below it.'),
                        ],
                    ],
                    'Cashing in points' => [
                        'settings' => [
                            new Setting('rewards.points_per_naira', 'Points per ₦1', 'int', rules: ['required', 'integer', 'min:1']),
                            new Setting('rewards.minimum_redeem_points', 'Fewest points that can be cashed in', 'int', rules: ['required', 'integer', 'min:1']),
                        ],
                    ],
                ],
            ],

            'Referrals' => [
                'icon' => 'heroicon-o-user-plus',
                'sections' => [
                    'Referral commission' => [
                        'settings' => [
                            new Setting('referrals.commission_rate', 'Commission on a friend\'s first top-up (%)', 'fraction_percent', 'Paid once per friend, into the referrer\'s winnings.', rules: ['required', 'numeric', 'min:0', 'max:100']),
                        ],
                    ],
                ],
            ],

            'Community' => [
                'icon' => 'heroicon-o-user-group',
                'sections' => [
                    'Season Pass' => [
                        'description' => 'A free 4-week track with 30 levels. XP comes from playing, daily check-ins, tasks and predictions; each level pays points, and some levels a free spin, a free bonus entry or a badge.',
                        'settings' => [
                            new Setting('engagement.season.xp_per_level', 'XP per level', 'int', null, rules: ['required', 'integer', 'min:10', 'max:10000']),
                            new Setting('engagement.season.xp.ticket', 'XP per ticket bought', 'int', null, rules: ['required', 'integer', 'min:0']),
                            new Setting('engagement.season.xp.ticket_daily_cap', 'Most ticket XP in one day', 'int', 'So buying more never races ahead.', rules: ['required', 'integer', 'min:0']),
                            new Setting('engagement.season.xp.daily_claim', 'XP per daily check-in', 'int', null, rules: ['required', 'integer', 'min:0']),
                            new Setting('engagement.season.xp.task', 'XP per task', 'int', null, rules: ['required', 'integer', 'min:0']),
                            new Setting('engagement.season.xp.prediction', 'XP per prediction answered', 'int', null, rules: ['required', 'integer', 'min:0']),
                            new Setting('engagement.season.xp.prediction_correct', 'XP per right prediction', 'int', null, rules: ['required', 'integer', 'min:0']),
                            new Setting('engagement.season.level_points', 'Points for level 1', 'int', null, rules: ['required', 'integer', 'min:0']),
                            new Setting('engagement.season.level_points_step', 'Extra points per level after that', 'int', null, rules: ['required', 'integer', 'min:0']),
                        ],
                    ],
                    'Daily predictions' => [
                        'settings' => [
                            new Setting('engagement.predictions.default_points', 'Points for a right answer (default)', 'int', 'Each question can set its own.', rules: ['required', 'integer', 'min:0']),
                        ],
                    ],
                    'Free spins' => [
                        'settings' => [
                            new Setting('engagement.free_spins.birthday', 'Birthday free spins', 'int', null, rules: ['required', 'integer', 'min:0', 'max:10']),
                            new Setting('engagement.free_spins.milestones.tickets_10', 'Free spins at the 10th ticket', 'int', null, rules: ['required', 'integer', 'min:0', 'max:10']),
                            new Setting('engagement.free_spins.milestones.tickets_50', 'Free spins at the 50th ticket', 'int', null, rules: ['required', 'integer', 'min:0', 'max:10']),
                            new Setting('engagement.free_spins.milestones.tickets_100', 'Free spins at the 100th ticket', 'int', null, rules: ['required', 'integer', 'min:0', 'max:10']),
                            new Setting('engagement.free_spins.milestones.anniversary', 'Free spins on each account anniversary', 'int', null, rules: ['required', 'integer', 'min:0', 'max:10']),
                        ],
                    ],
                    'Help me unlock' => [
                        'settings' => [
                            new Setting('engagement.unlock.taps_needed', 'Friends who must tap', 'int', null, rules: ['required', 'integer', 'min:1', 'max:20']),
                            new Setting('engagement.unlock.bonus_entries', 'Free bonus entries unlocked', 'int', null, rules: ['required', 'integer', 'min:1', 'max:10']),
                            new Setting('engagement.unlock.tapper_points', 'Points for each friend who taps', 'int', null, rules: ['required', 'integer', 'min:0']),
                            new Setting('engagement.unlock.daily_taps_per_user', 'Most taps one person can give a day', 'int', null, rules: ['required', 'integer', 'min:1', 'max:50']),
                        ],
                    ],
                    'Team Up' => [
                        'settings' => [
                            new Setting('engagement.teams.size', 'Team size (captain included)', 'int', null, rules: ['required', 'integer', 'min:2', 'max:10']),
                            new Setting('engagement.teams.hours', 'Hours to fill a team', 'int', null, rules: ['required', 'integer', 'min:1', 'max:168']),
                            new Setting('engagement.teams.bonus_entries', 'Free bonus entries each when full', 'int', null, rules: ['required', 'integer', 'min:1', 'max:10']),
                        ],
                    ],
                    'Red envelopes' => [
                        'settings' => [
                            new Setting('engagement.red_envelopes.min_points', 'Smallest envelope (points)', 'int', null, rules: ['required', 'integer', 'min:1']),
                            new Setting('engagement.red_envelopes.max_points', 'Biggest envelope a customer can send (points)', 'int', null, rules: ['required', 'integer', 'min:1']),
                            new Setting('engagement.red_envelopes.max_slots', 'Most people per customer envelope', 'int', null, rules: ['required', 'integer', 'min:1', 'max:50']),
                            new Setting('engagement.red_envelopes.minutes', 'Minutes before unopened points go back', 'int', null, rules: ['required', 'integer', 'min:1', 'max:120']),
                        ],
                    ],
                    'Winner stories' => [
                        'settings' => [
                            new Setting('engagement.stories.points', 'Thank-you points when a story is approved', 'int', null, rules: ['required', 'integer', 'min:0']),
                        ],
                    ],
                ],
            ],

            'Reminders' => [
                'icon' => 'heroicon-o-clock',
                'sections' => [
                    'What to send' => [
                        'description' => 'Switched on in On / off → New features. Checked every 5 minutes. The same reminder never goes twice, and every email has a one-tap "stop reminders" link. People on a responsible-play break never get them.',
                        'settings' => [
                            new Setting('reminders.raffle_ending.enabled', '"This raffle ends soon"', 'bool'),
                            new Setting('reminders.raffle_ending.minutes_before', 'Send it this many minutes before sales close', 'int', rules: ['required', 'integer', 'min:10', 'max:1440']),
                            new Setting('reminders.raffle_ending.audience', 'Send it to', 'select', null, [
                                'entrants' => 'People with tickets in that raffle',
                                'entrants_and_checkout' => 'Them, plus people who left it in checkout (recommended)',
                                'recent_players' => 'Anyone who played in the last 30 days',
                            ]),
                            new Setting('reminders.abandoned_checkout.enabled', '"You left tickets in checkout"', 'bool'),
                            new Setting('reminders.abandoned_checkout.after_minutes', 'Send it this many minutes after they left', 'int', rules: ['required', 'integer', 'min:5', 'max:720']),
                        ],
                    ],
                    'How and how often' => [
                        'settings' => [
                            new Setting('reminders.channels.push', 'By push notification', 'bool', 'Only reaches people who turned notifications on (Settings → Alerts & push → OneSignal).'),
                            new Setting('reminders.channels.email', 'By email', 'bool'),
                            new Setting('reminders.channels.whatsapp', 'By WhatsApp', 'bool', 'Needs the WhatsApp section below. Uses the phone number on the customer\'s profile.'),
                            new Setting('reminders.max_per_day', 'Most reminders one person gets in 24 hours', 'int', rules: ['required', 'integer', 'min:1', 'max:10']),
                            new Setting('reminders.quiet_from', 'Quiet hours start (hour, 0-23)', 'int', 'Business time zone. Nothing is sent in quiet hours.', rules: ['required', 'integer', 'min:0', 'max:23']),
                            new Setting('reminders.quiet_until', 'Quiet hours end (hour, 0-23)', 'int', 'Same number as the start = no quiet hours.', rules: ['required', 'integer', 'min:0', 'max:23']),
                        ],
                    ],
                    'WhatsApp (Meta WhatsApp Business)' => [
                        'description' => 'In Meta Business → WhatsApp Manager: add your number, then from the app\'s WhatsApp → API Setup copy the Phone number ID and a permanent access token (System user token). Create two message templates and wait for Meta to approve them. Each template\'s body uses {{1}} for the customer\'s name, {{2}} for the raffle and {{3}} for the link, e.g. "Hi {{1}}, {{2}} ends in 1 hour. Get your tickets: {{3}}". Meta charges per message.',
                        'settings' => [
                            new Setting('reminders.whatsapp.phone_number_id', 'Phone number ID', 'text', placeholder: '123456789012345'),
                            new Setting('reminders.whatsapp.access_token', 'Access token', 'secret'),
                            new Setting('reminders.whatsapp.language', 'Template language code', 'text', 'As set on the templates, e.g. en or en_US.', rules: ['required', 'max:10']),
                            new Setting('reminders.whatsapp.templates.raffle_ending', 'Template name: raffle ends soon', 'text', rules: ['required', 'max:100']),
                            new Setting('reminders.whatsapp.templates.abandoned_checkout', 'Template name: left in checkout', 'text', rules: ['required', 'max:100']),
                        ],
                    ],
                ],
            ],

            'Email' => [
                'icon' => 'heroicon-o-envelope',
                'sections' => [
                    'Sending' => [
                        'settings' => [
                            new Setting('mail.default', 'Send emails using', 'select', null, [
                                'brevo' => 'Brevo (API key, recommended)',
                                'smtp' => 'An email server (SMTP)',
                                'log' => 'Don\'t send, just write to the log (testing only)',
                            ]),
                            new Setting('mail.from.address', 'From address', 'email', 'Must be an address your provider allows you to send from.', rules: ['required', 'email']),
                            new Setting('mail.from.name', 'From name', 'text', rules: ['required', 'max:60']),
                        ],
                    ],
                    'Brevo' => [
                        'description' => 'Brevo dashboard → SMTP & API → API Keys → Generate a new API key.',
                        'settings' => [
                            new Setting('services.brevo.key', 'Brevo API key', 'secret', placeholder: 'xkeysib-...'),
                        ],
                    ],
                    'Email server (SMTP)' => [
                        'description' => 'Any provider: Brevo SMTP, Zoho, Gmail, your web host… Use the quick-fill buttons for common ones.',
                        'settings' => [
                            new Setting('mail.mailers.smtp.host', 'Server', 'text', placeholder: 'smtp-relay.brevo.com'),
                            new Setting('mail.mailers.smtp.port', 'Port', 'int', rules: ['nullable', 'integer', 'between:1,65535']),
                            new Setting('mail.mailers.smtp.scheme', 'Security', 'select', 'Port 465 uses SSL; ports 587 and 25 upgrade to a secure connection automatically.', ['smtp' => 'Automatic (port 587 / 25)', 'smtps' => 'SSL (port 465)']),
                            new Setting('mail.mailers.smtp.username', 'Username', 'text'),
                            new Setting('mail.mailers.smtp.password', 'Password', 'secret'),
                        ],
                    ],
                ],
            ],

            'Analytics' => [
                'icon' => 'heroicon-o-chart-bar',
                'sections' => [
                    'PostHog' => [
                        'description' => 'Shows what visitors do: pages, sign-ups, purchases, session recordings and heatmaps. In PostHog: Project settings → Project API key (starts with phc_). Leave the key empty to switch all tracking off. Money events (purchases, top-ups, withdrawals, wins) are sent from the server, so ad-blockers can\'t hide them.',
                        'settings' => [
                            new Setting('services.posthog.project_key', 'Project API key', 'text', 'Safe to store here: PostHog project keys are meant to be public.', placeholder: 'phc_...', rules: ['nullable', 'starts_with:phc_']),
                            new Setting('services.posthog.host', 'PostHog region', 'select', 'Pick the region you chose when you created your PostHog project.', [
                                'https://us.i.posthog.com' => 'US Cloud',
                                'https://eu.i.posthog.com' => 'EU Cloud',
                            ]),
                            new Setting('services.posthog.recordings', 'Record visits (session replay)', 'bool', 'Video-like playback of visits. Anything typed into a field is always hidden.'),
                        ],
                    ],
                ],
            ],

            'Consent & privacy' => [
                'icon' => 'heroicon-o-shield-check',
                'sections' => [
                    'Google Analytics' => [
                        'description' => 'Counts visitors and where they come from. In Google Analytics: Admin → Data streams → your website → Measurement ID (starts with G-). Leave empty to switch it off.',
                        'settings' => [
                            new Setting('services.google_analytics.measurement_id', 'Measurement ID', 'text', placeholder: 'G-XXXXXXXXXX', rules: ['nullable', 'regex:/^G-[A-Z0-9]{6,14}$/']),
                        ],
                    ],
                    'Cookie consent banner' => [
                        'description' => 'Nigeria\'s Data Protection Act (2023) expects clear notice and a real choice before non-essential tracking. With this on, PostHog and Google Analytics load in a visitor\'s browser only after they tap Accept; Decline keeps them off. Ask your lawyer to confirm what applies to you.',
                        'settings' => [
                            new Setting('services.analytics.require_consent', 'Ask visitors before tracking them', 'bool', 'Recommended. Switch off only if your lawyer says a banner is not needed for you.'),
                            new Setting('services.analytics.consent_message', 'Banner message', 'textarea', 'Keep it short and plain.', rules: ['required', 'max:400']),
                        ],
                    ],
                    'Privacy policy details' => [
                        'description' => 'Filled in automatically in the "Analytics and cookies" part of the Privacy Policy page.',
                        'settings' => [
                            new Setting('services.analytics.controller_name', 'Company / business name', 'text', 'Who is responsible for customers\' data.', rules: ['required', 'max:120']),
                            new Setting('services.analytics.privacy_email', 'Privacy contact email', 'email', 'Where customers send data requests. Falls back to the support email if empty.'),
                            new Setting('services.analytics.dpo_name', 'Data protection officer (optional)', 'text', 'Name shown on the policy, if you have appointed one.', rules: ['nullable', 'max:120']),
                            new Setting('services.analytics.retention', 'How long analytics data is kept', 'text', 'Shown as text, e.g. "12 months". Also set the same period in PostHog (Project settings → Data retention).', rules: ['required', 'max:60']),
                        ],
                    ],
                ],
            ],

            'AI' => [
                'icon' => 'heroicon-o-sparkles',
                'sections' => [
                    'Google Gemini' => [
                        'description' => 'Reads payment screenshots and bank statements (bank-transfer checks, Daily Audit). Get a key at aistudio.google.com → Get API key.',
                        'settings' => [
                            new Setting('services.gemini.api_key', 'API key', 'secret', placeholder: 'AIza...'),
                            new Setting('services.gemini.model', 'Model', 'select', 'Flash is fast and cheap; Pro reads messy screenshots better but costs more.', [
                                'gemini-2.5-flash' => 'Gemini 2.5 Flash (recommended)',
                                'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite (cheapest)',
                                'gemini-2.5-pro' => 'Gemini 2.5 Pro (most accurate)',
                                'gemini-2.5-flash-preview-09-2025' => 'Gemini 2.5 Flash preview (09-2025)',
                            ]),
                        ],
                    ],
                    'AI assistant' => [
                        'description' => 'The "Write with AI" buttons next to text boxes, the support agent that can answer tickets from the Knowledge base, and the Raffle advisor. Uses the Gemini key above. AI replies to customers are always labelled "Automated reply".',
                        'settings' => [
                            new Setting('ai.enabled', 'AI helpers on', 'bool', 'Turns off every AI button and the support agent.'),
                            new Setting('services.gemini.assistant_model', 'Model for writing and replying', 'select', 'Pick a Gemini 3 Flash model. Use "Test connection" on the Google Gemini section to check the key. If a model shows as unavailable, choose another.', [
                                'gemini-3-flash-preview' => 'Gemini 3 Flash (recommended)',
                                'gemini-3.1-flash-lite-preview' => 'Gemini 3.1 Flash-Lite (cheapest, fastest)',
                                'gemini-2.5-flash' => 'Gemini 2.5 Flash (older, steady)',
                            ]),
                            new Setting('ai.auto_reply', 'Support agent answers tickets by itself', 'bool', 'It only replies when the Knowledge base clearly has the answer. Otherwise the ticket waits for your team.'),
                            new Setting('ai.max_auto_replies', 'Automated replies per ticket before a person takes over', 'int', rules: ['required', 'integer', 'min:1', 'max:10']),
                            new Setting('ai.daily_limit', 'Most AI calls per day', 'int', 'A safety cap on cost. When it is reached the AI stops until tomorrow.', rules: ['required', 'integer', 'min:0', 'max:5000']),
                            new Setting('ai.instructions', 'House rules for the AI', 'textarea', 'Optional. Tone and things to avoid, e.g. "Be warm and brief. Never promise a win."', rules: ['nullable', 'max:1000']),
                            new Setting('ai.advisor_weekly', 'Raffle advisor writes a fresh report every Monday', 'bool', 'The advisor (Raffles → Raffle advisor) checks in by itself once a week, so new advice is waiting for you.'),
                        ],
                    ],
                ],
            ],

            'Alerts & push' => [
                'icon' => 'heroicon-o-bell-alert',
                'sections' => [
                    'Sentry (error tracking)' => [
                        'description' => 'Collects full details of any error on the site and groups repeats. In sentry.io: create a project (choose Laravel), then Project settings → Client Keys (DSN) → copy the DSN. Leave empty to switch it off. It takes effect the next time the site handles a request after you save.',
                        'settings' => [
                            new Setting('sentry.dsn', 'Sentry DSN', 'secret', 'Stored encrypted. Looks like https://…@…ingest.sentry.io/…', placeholder: 'https://abc123@o123456.ingest.sentry.io/1234567', rules: ['nullable', 'starts_with:https://']),
                        ],
                    ],
                    'Telegram (staff alerts)' => [
                        'description' => 'New withdrawals, bank transfers and site errors are sent to your staff Telegram. Create a bot with @BotFather to get the token; send your bot a message, then use @userinfobot to find each chat ID.',
                        'settings' => [
                            new Setting('services.telegram.bot_token', 'Bot token', 'secret', placeholder: '123456:ABC-...'),
                            new Setting('services.telegram.admin_chat_ids', 'Chat IDs to alert', 'tags', 'Press Enter after each one.'),
                            new Setting('monitoring.telegram_error_alerts', 'Alert on site errors', 'bool'),
                            new Setting('monitoring.repeat_after_minutes', 'Repeat the same error alert after (minutes)', 'int', rules: ['required', 'integer', 'min:1']),
                            new Setting('monitoring.max_per_hour', 'Most error alerts per hour', 'int', rules: ['required', 'integer', 'min:1']),
                        ],
                    ],
                    'OneSignal (customer push notifications)' => [
                        'description' => 'OneSignal dashboard → Settings → Keys & IDs.',
                        'settings' => [
                            new Setting('services.onesignal.app_id', 'App ID', 'text', 'In OneSignal: Settings → Keys & IDs → "OneSignal App ID". It looks like 1a2b3c4d-1111-2222-3333-444455556666 (not the API key).', rules: ['nullable', 'regex:/^\s*[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\s*$/']),
                            new Setting('services.onesignal.api_key', 'REST API key', 'secret'),
                        ],
                    ],
                    'Live updates (Pusher)' => [
                        'description' => 'Makes live draws, the ticket counters, live chat and "N watching" update instantly. Create a free "Channels" app at pusher.com, then copy its App keys here. Without it, those pages refresh every few seconds instead.',
                        'settings' => [
                            new Setting('broadcasting.default', 'Live updates', 'select', options: ['log' => 'Off (pages refresh every few seconds)', 'pusher' => 'On, through Pusher']),
                            new Setting('broadcasting.connections.pusher.app_id', 'app_id', 'text', placeholder: '1234567'),
                            new Setting('broadcasting.connections.pusher.key', 'key', 'text', 'The public key (safe to show in the browser).', placeholder: 'a1b2c3d4e5f6a7b8c9d0'),
                            new Setting('broadcasting.connections.pusher.secret', 'secret', 'secret'),
                            new Setting('broadcasting.connections.pusher.options.cluster', 'cluster', 'text', 'Where the app lives, e.g. eu or mt1. Shown next to the keys.', placeholder: 'eu', rules: ['nullable', 'regex:/^[a-z0-9-]{2,10}$/']),
                        ],
                    ],
                ],
            ],

            'Backups & status' => [
                'icon' => 'heroicon-o-circle-stack',
                'sections' => [
                    'Nightly database backup' => [
                        'description' => 'A full copy of the database every night, kept on the server (storage/app/backups, never reachable from the web). System → Health shows the last backup and whether it was proven to restore. A failure is sent to your staff Telegram.',
                        'settings' => [
                            new Setting('backups.enabled', 'Back up every night', 'bool'),
                            new Setting('backups.hour', 'At this hour (0-23, business time zone)', 'int', 'Pick a quiet hour.', rules: ['required', 'integer', 'min:0', 'max:23']),
                            new Setting('backups.keep_days', 'Keep backups for (days)', 'int', rules: ['required', 'integer', 'min:1', 'max:90']),
                            new Setting('backups.send_to_telegram', 'Also send each backup to the staff Telegram chat', 'bool', 'An off-site copy, so the data survives even if the server is lost. Only files up to 45 MB; bigger ones stay on the server and you get a message. Keep the Telegram group private: the file holds customer data.'),
                        ],
                    ],
                    'Practice restore (proves the backup works)' => [
                        'description' => 'About 40 minutes after each backup, the copy is loaded into a SEPARATE, EMPTY database and every table\'s rows are counted against the original. In cPanel → MySQL Databases: create a new database (e.g. yourname_restoretest), a new user, and give that user ALL PRIVILEGES on it. Everything in that database is wiped each night, so never use a real one. Leave the host empty to use the same server as the live database.',
                        'settings' => [
                            new Setting('backups.restore.database', 'Practice database name', 'text', placeholder: 'yourname_restoretest'),
                            new Setting('backups.restore.username', 'Its username', 'text'),
                            new Setting('backups.restore.password', 'Its password', 'secret'),
                            new Setting('backups.restore.host', 'Its host (usually empty)', 'text', placeholder: 'localhost'),
                        ],
                    ],
                    'Uptime alerts' => [
                        'description' => 'Two free outside checks, because a site can\'t report that it is down. (1) At uptimerobot.com, add an HTTP monitor for '.rtrim((string) config('app.url'), '/').'/up and it emails or texts you within minutes of the site going down. (2) At healthchecks.io (or Better Stack), create a check with a 5-minute period and paste its ping URL below; the site pings it every 5 minutes, and you are alerted when the pings stop (site down OR the cPanel cron job stopped). Sentry (Alerts & push) catches errors.',
                        'settings' => [
                            new Setting('monitoring.heartbeat_url', 'Heartbeat ping URL', 'url', placeholder: 'https://hc-ping.com/…'),
                        ],
                    ],
                    'Status message for customers' => [
                        'description' => 'Shown on the public /status page (switch it on in On / off → New features), which also lists live which parts of the site are working or paused. Set it back to "All good" when the problem is over.',
                        'settings' => [
                            new Setting('status.level', 'How things are', 'select', null, [
                                'ok' => 'All good (no message)',
                                'info' => 'Just so you know (blue)',
                                'degraded' => 'Some things are slow or not working (amber)',
                                'outage' => 'Major problem (red)',
                            ]),
                            new Setting('status.message', 'Message', 'textarea', 'e.g. "Top-ups by card are slow because of a Paystack problem. Your money is safe; payments will show within an hour."', rules: ['nullable', 'max:400']),
                            new Setting('status.banner', 'Also show it as a banner on every page', 'bool'),
                        ],
                    ],
                ],
            ],

            'Security' => [
                'icon' => 'heroicon-o-shield-check',
                'sections' => [
                    'Staff sign-in' => [
                        'description' => 'Protects the admin if a staff password is ever guessed or stolen. With this on, the right password is not enough: we email a 6-digit code that must be typed in too. Everyone signed in now is asked to sign in again. It needs working email (Settings → Email). If email ever breaks and nobody can get in, ask whoever looks after the server to run: php artisan staff:two-step off',
                        'settings' => [
                            new Setting('security.staff_two_step', 'Ask staff for an emailed code when they sign in', 'bool'),
                        ],
                    ],
                    'Bot protection (Cloudflare Turnstile)' => [
                        'description' => 'A quick "are you human?" check that stops robots creating fake accounts or guessing passwords. Get both keys free at dash.cloudflare.com → Turnstile → Add widget (add your site\'s domain). The check only switches on when BOTH keys are filled in.',
                        'settings' => [
                            new Setting('services.turnstile.site_key', 'Site key (public)', 'text', 'Shown in the page, so it\'s safe to be public.', placeholder: '0x4AAAAAAA...'),
                            new Setting('services.turnstile.secret_key', 'Secret key (private)', 'secret', 'Used by the server to confirm each check. Never shown to anyone.', placeholder: '0x4AAAAAAA...'),
                            new Setting('services.turnstile.forms.register', 'Check on sign-up', 'bool'),
                            new Setting('services.turnstile.forms.login', 'Check on log-in', 'bool', 'Stops password-guessing robots.'),
                            new Setting('services.turnstile.forms.forgot_password', 'Check on "forgot password"', 'bool', 'Stops robots flooding customers with reset codes.'),
                        ],
                    ],
                    'Live-draw chat' => [
                        'settings' => [
                            new Setting('moderation.remove_links', 'Remove links from messages', 'bool'),
                            new Setting('moderation.remove_phone_numbers', 'Remove phone numbers from messages', 'bool', 'Stops the "WhatsApp me to claim your prize" scam.'),
                            new Setting('moderation.blocked_words', 'Blocked words', 'tags', 'Starred out in chat. Press Enter after each one.'),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, Setting> keyed by config path */
    public static function all(): array
    {
        $all = [];

        foreach (self::tabs() as $tab) {
            foreach ($tab['sections'] as $section) {
                foreach ($section['settings'] as $setting) {
                    $all[$setting->key] = $setting;
                }
            }
        }

        foreach (self::hidden() as $setting) {
            $all[$setting->key] = $setting;
        }

        return $all;
    }

    /**
     * Settings stored like any other but edited on their own admin screen
     * rather than on the Settings page.
     *
     * @return list<Setting>
     */
    private static function hidden(): array
    {
        return [
            // System → Tracked Events.
            new Setting('services.analytics.disabled_events', 'Turned-off tracking events', 'tags'),
        ];
    }

    public static function find(string $key): ?Setting
    {
        return self::all()[$key] ?? null;
    }
}
