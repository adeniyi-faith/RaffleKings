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
                            new Setting('site.switches.point_redemption', 'Cash in points', 'bool'),
                            new Setting('site.paused_message', 'Message shown when something is paused', 'textarea', rules: ['required', 'max:200']),
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
                ],
            ],

            'Raffles & pricing' => [
                'icon' => 'heroicon-o-ticket',
                'sections' => [
                    'Raffle list' => [
                        'settings' => [
                            new Setting('raffles.list_closed_for_days', 'Keep finished raffles on the list for (days)', 'int', 'After this they only appear in the Hall of Fame.', rules: ['required', 'integer', 'min:0', 'max:365']),
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
                ],
            ],

            'Alerts & push' => [
                'icon' => 'heroicon-o-bell-alert',
                'sections' => [
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
                            new Setting('services.onesignal.app_id', 'App ID', 'text'),
                            new Setting('services.onesignal.api_key', 'REST API key', 'secret'),
                        ],
                    ],
                ],
            ],

            'Security' => [
                'icon' => 'heroicon-o-shield-check',
                'sections' => [
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

        return $all;
    }

    public static function find(string $key): ?Setting
    {
        return self::all()[$key] ?? null;
    }
}
