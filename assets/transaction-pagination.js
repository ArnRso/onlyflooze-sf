/**
 * Transaction pagination functionality
 */
document.addEventListener('DOMContentLoaded', function () {
    const perPageSelect = document.getElementById('per-page');

    if (perPageSelect) {
        perPageSelect.addEventListener('change', function () {
            changePerPage(this.value);
        });
    }
});

function changePerPage(limit) {
    const url = new URL(window.location);
    url.searchParams.set('limit', limit);
    url.searchParams.delete('page'); // Reset à la page 1
    window.location.href = url.toString();
}
