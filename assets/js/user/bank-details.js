    // Sheet Elements
    const overlay = document.getElementById('bank-overlay');
    const sheet = document.getElementById('bank-sheet');

    document.addEventListener('DOMContentLoaded', () => {
        if(typeof lucide !== 'undefined') lucide.createIcons();
    });

    async function saveAccount() {
        const saveBtn = document.getElementById('rk-save-bank-btn');

        // Fetch fresh elements inside the function execution to prevent stale/duplicate grabs
        const bankName = document.getElementById('rk-new-bank-name').value.trim();
        const accNum = document.getElementById('rk-new-acc-num').value.trim();
        const accName = document.getElementById('rk-new-acc-name').value.trim();

        if(!bankName || !accNum || !accName) {
            alert("Please fill in bank name, account number, and account name.");
            return;
        }
        if(!/^\d{10}$/.test(accNum)) {
            alert("Nigerian account numbers must be exactly 10 digits.");
            return;
        }
        if(bankName.length < 2 || !/^[A-Za-z0-9 .&'-]{2,80}$/.test(bankName)) {
            alert("Enter a valid Nigerian bank name.");
            return;
        }
        if(!/^[A-Za-z .'-]{3,80}$/.test(accName)) {
            alert("Enter the account name as it appears at the bank.");
            return;
        }

        saveBtn.innerHTML = 'Saving...';
        saveBtn.disabled = true;

        try {
            // Tunnel request locally (auth cookie automatically attached)
            const res = await fetch(window.location.pathname + '?action=save_account', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    bank_name: bankName,
                    bank_code: '000', // Placeholder
                    account_number: accNum,
                    account_name: accName
                })
            });

            const data = await res.json();
            if (res.ok && data.success) {
                // Instantly reload to trigger our zero-latency SSR
                window.location.reload();
            } else {
                alert(data.message || "Failed to save.");
            }
        } catch (e) {
            alert("Network error.");
        } finally {
            saveBtn.innerHTML = 'Save Account';
            saveBtn.disabled = false;
        }
    }

    async function deleteAccount(id) {
        if(!confirm("Are you sure you want to remove this account?")) return;

        try {
            // Tunnel DELETE request locally
            const res = await fetch(window.location.pathname + '?action=delete_account&id=' + encodeURIComponent(id), {
                method: 'DELETE'
            });
            if (res.ok) {
                // Instantly reload to reflect state via SSR
                window.location.reload();
            } else {
                alert("Failed to delete.");
            }
        } catch (e) {
            alert("Network error.");
        }
    }

    function openAddBankSheet() {
        overlay.classList.remove('hidden');
        setTimeout(() => {
            overlay.classList.remove('opacity-0');
            sheet.classList.remove('translate-y-full');
        }, 10);
    }

    function closeAddBankSheet() {
        overlay.classList.add('opacity-0');
        sheet.classList.add('translate-y-full');
        setTimeout(() => {
            overlay.classList.add('hidden');
            // Clear the unique inputs safely
            document.getElementById('rk-new-bank-name').value = '';
            document.getElementById('rk-new-acc-num').value = '';
            document.getElementById('rk-new-acc-name').value = '';
        }, 300);
    }
