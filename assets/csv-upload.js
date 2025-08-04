/**
 * CSV upload form functionality
 */
document.addEventListener('DOMContentLoaded', function () {
    const fileInput = document.querySelector('#csv_file_upload_file');
    const continueBtn = document.querySelector('#continue-btn');

    if (fileInput && continueBtn) {
        fileInput.addEventListener('change', function () {
            continueBtn.disabled = !this.files.length;
        });
    }
});