<footer class="app-footer"><span><?= e(APP_NAME) ?> | <?= e(APP_VERSION) ?></span><!-- QuickBooks integration notice temporarily disabled: <span>Local records first | QuickBooks integration pending</span> --></footer>
</main></div>
<iframe id="invoicePrintFrame" title="Invoice print frame" aria-hidden="true" tabindex="-1" style="position:fixed; width:1px; height:1px; left:-10000px; top:0; border:0;"></iframe>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="assets/js/app.js"></script>
<script>
    document.addEventListener('click', event => {
        const link = event.target.closest('a[data-invoice-print]');
        if (!link) return;

        event.preventDefault();
        const frame = document.getElementById('invoicePrintFrame');
        frame.onload = () => {
            frame.contentWindow.focus();
            frame.contentWindow.print();
        };
        frame.src = link.href;
    });
</script>
</body></html>
