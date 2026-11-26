(function () {
    window.registerInvoicePrint = async function (invoiceId, printToken, csrfToken) {
        try {
            const body = new URLSearchParams({
                invoice_id: String(invoiceId),
                print_token: String(printToken),
                _csrf: String(csrfToken)
            });
            const response = await fetch('../backend/api/invoice_print_register.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body
            });
            const result = await response.json();
            if (!response.ok || result.ok !== true) return false;
            document.dispatchEvent(new CustomEvent('invoice-printed', {
                detail: { invoiceId: String(result.invoice_id) }
            }));
            return true;
        } catch (error) {
            return false;
        }
    };

    document.addEventListener('invoice-printed', function (event) {
        const invoiceId = event.detail?.invoiceId;
        if (!invoiceId) return;
        document.querySelectorAll('[data-invoice-print-status]').forEach(function (badge) {
            if (badge.dataset.invoicePrintStatus !== invoiceId) return;
            badge.textContent = 'PRINTED';
            badge.classList.remove('status-warning');
            badge.classList.add('status-success');
        });
    });
})();
