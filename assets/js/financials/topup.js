(function() {
    window.initTopup = function(orderId) {
        if(typeof lucide !== 'undefined') lucide.createIcons();

        window.copyToClipboard = function(text, itemType) {
            navigator.clipboard.writeText(text).then(() => {
                alert(itemType + " Copied!");
            }).catch(err => {
                const textArea = document.createElement("textarea");
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand("copy");
                document.body.removeChild(textArea);
                alert(itemType + " Copied!");
            });
        };

        window.processPayment = async function() {
            const amount = document.getElementById('amount-paid').value;
            const fileInput = document.getElementById('proof-file');

            if (!amount || fileInput.files.length === 0) {
                alert("Please fill all fields and upload a receipt.");
                return;
            }

            if (parseFloat(amount) < 1000) {
                alert("Minimum top-up amount is ₦1,000.");
                return;
            }

            document.getElementById('processing-modal').classList.remove('hidden');

            const formData = new FormData();
            formData.append('proof', fileInput.files[0]);
            formData.append('amount', amount);
            formData.append('type', 'wallet_deposit');
            formData.append('order_id', orderId);

            try {
                const response = await fetch(window.location.pathname + '?action=process_payment', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                document.getElementById('processing-modal').classList.add('hidden');

                if (result.success) {
                    alert("Success: " + result.message);
                    window.location.href = 'index.php';
                } else {
                    alert("Notice: " + (result.message || "Could not verify."));
                    if (result.status === 'manual_review') {
                        window.location.href = 'index.php';
                    }
                }
            } catch (error) {
                document.getElementById('processing-modal').classList.add('hidden');
                alert("Connection Error. Please check your internet connection and try again.");
                console.error(error);
            }
        };
    };
})();
