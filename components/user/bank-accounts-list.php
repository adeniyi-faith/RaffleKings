    <!-- 1. Saved Accounts List (SSR Rendered) -->
    <section class="p-5 space-y-4" id="accounts-list">

        <?php if (empty($bank_accounts)): ?>
            <!-- Empty State -->
            <div id="empty-state" class="flex flex-col items-center justify-center py-10 text-center">
                <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mb-4 transition-colors">
                    <i data-lucide="credit-card" class="w-8 h-8 text-gray-400 dark:text-gray-500"></i>
                </div>
                <h3 class="text-gray-900 dark:text-white font-bold">No Accounts Linked</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-[200px]">Link a bank account to withdraw your winnings.</p>
            </div>
        <?php else: ?>
            <!-- Loop through native meta data -->
            <?php foreach ($bank_accounts as $acc):
                $initial = strtoupper(substr($acc['bank_name'], 0, 2));
                $is_primary = !empty($acc['is_primary']);
                $borderClass = $is_primary ? 'border-green-200 dark:border-green-900/50 ring-1 ring-green-100 dark:ring-green-900/30' : 'border-gray-200 dark:border-gray-700';
            ?>
                <div class="account-item bg-white dark:bg-dark-card <?= $borderClass ?> p-4 rounded-xl flex items-center justify-between shadow-sm relative overflow-hidden group transition-colors duration-200">

                    <?php if ($is_primary): ?>
                        <div class="absolute top-0 left-0 bg-green-500 text-white text-[9px] font-bold px-2 py-0.5 rounded-br-lg">PRIMARY</div>
                    <?php endif; ?>

                    <div class="flex items-center gap-4 <?= $is_primary ? 'mt-2' : '' ?>">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-xs border bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 border-gray-100 dark:border-gray-700 transition-colors">
                            <?= esc_html($initial) ?>
                        </div>
                        <div>
                            <h4 class="font-bold text-gray-900 dark:text-white text-sm truncate max-w-[150px]"><?= esc_html($acc['bank_name']) ?></h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-mono"><?= esc_html($acc['account_number']) ?></p>
                            <p class="text-[10px] text-gray-400 dark:text-gray-500 font-medium mt-0.5 truncate max-w-[150px]"><?= esc_html($acc['account_name']) ?></p>
                        </div>
                    </div>
                    <button onclick="deleteAccount('<?= esc_js($acc['id']) ?>')" class="p-2 text-gray-300 hover:text-red-500 dark:text-gray-600 dark:hover:text-red-400 transition-colors">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                    </button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </section>
