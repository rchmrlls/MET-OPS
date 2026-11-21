<footer class="app-footer"><span><?= e(APP_NAME) ?> | <?= e(APP_VERSION) ?></span><!-- QuickBooks integration notice temporarily disabled: <span>Local records first | QuickBooks integration pending</span> --></footer>
</main></div>
<iframe id="invoicePrintFrame" title="Invoice print frame" aria-hidden="true" tabindex="-1" style="position:fixed; width:1px; height:1px; left:-10000px; top:0; border:0;"></iframe>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/invoice-print.js"></script>
<script>
    document.addEventListener('click', event => {
        const link = event.target.closest('a[data-invoice-print]');
        if (!link) return;

        event.preventDefault();
        const frame = document.getElementById('invoicePrintFrame');
        frame.onload = async () => {
            const printDocument = frame.contentDocument;
            const invoiceId = printDocument?.querySelector('meta[name="invoice-id"]')?.content;
            const printToken = printDocument?.querySelector('meta[name="invoice-print-token"]')?.content;
            const csrfToken = printDocument?.querySelector('meta[name="csrf-token"]')?.content;
            if (!invoiceId || !printToken || !csrfToken) {
                window.alert('The invoice could not be prepared for printing. Please reopen it and try again.');
                return;
            }
            const registered = await window.registerInvoicePrint(invoiceId, printToken, csrfToken);
            if (!registered) {
                window.alert('The print status could not be saved. You can still print, but the archive may continue to show Not Printed.');
            }
            try {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            } catch (error) {
                window.alert('The browser could not open the print dialog. Please try again or use the browser menu.');
            }
        };
        frame.src = link.href;
    });
</script>
</body></html>
