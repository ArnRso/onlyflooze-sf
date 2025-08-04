/**
 * CSV preview confirmation functionality
 */
document.addEventListener('DOMContentLoaded', function () {
    const confirmButton = document.querySelector('#confirm-import-btn');

    if (confirmButton) {
        confirmButton.addEventListener('click', function (e) {
            const transactionCount = this.dataset.transactionCount;
            const message = `Êtes-vous sûr de vouloir importer ${transactionCount} transaction(s) ?`;

            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    }
});
