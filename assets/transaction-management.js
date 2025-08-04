function toggleNewRecurringField(select) {
    const newFieldGroup = document.getElementById('new-recurring-name-group');
    const newFieldInput = document.getElementById('new_recurring_name');

    if (select.value === 'new') {
        newFieldGroup.classList.remove('d-none');
        newFieldInput.required = true;
    } else {
        newFieldGroup.classList.add('d-none');
        newFieldInput.required = false;
        newFieldInput.value = '';
    }
}

// Make function globally available
window.toggleNewRecurringField = toggleNewRecurringField;

// Checkbox management
document.addEventListener('DOMContentLoaded', function () {
    const selectAllCheckbox = document.getElementById('select-all');
    const bulkActions = document.getElementById('bulk-actions');
    const selectedCountSpan = document.getElementById('selected-count');
    const modalSelectedCountSpan = document.getElementById('modal-selected-count');
    const clearSelectionBtn = document.getElementById('clear-selection');
    const assignForm = document.getElementById('assign-form');

    function updateBulkActions() {
        const checkedBoxes = document.querySelectorAll('input[name="transaction_ids[]"]:checked');
        const count = checkedBoxes.length;

        if (count > 0) {
            bulkActions.classList.remove('d-none');
            selectedCountSpan.textContent = count;
            modalSelectedCountSpan.textContent = count;
        } else {
            bulkActions.classList.add('d-none');
        }

        // Update select all checkbox state
        const allCheckboxes = document.querySelectorAll('input[name="transaction_ids[]"]');
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = allCheckboxes.length === checkedBoxes.length && allCheckboxes.length > 0;
            selectAllCheckbox.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < allCheckboxes.length;
        }
    }

    // Select all functionality
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            const checkboxes = document.querySelectorAll('input[name="transaction_ids[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkActions();
        });
    }

    // Individual checkbox management
    document.addEventListener('change', function (e) {
        if (e.target.matches('input[name="transaction_ids[]"]')) {
            updateBulkActions();
        }
    });

    // Clear selection
    if (clearSelectionBtn) {
        clearSelectionBtn.addEventListener('click', function () {
            document.querySelectorAll('input[name="transaction_ids[]"]').forEach(cb => cb.checked = false);
            updateBulkActions();
        });
    }

    // Before submitting, add selected transaction IDs to the form
    if (assignForm) {
        assignForm.addEventListener('submit', function (e) {
            // Remove any existing transaction_ids inputs
            assignForm.querySelectorAll('input[name="transaction_ids[]"]').forEach(input => input.remove());

            // Add selected transaction IDs
            const checkedBoxes = document.querySelectorAll('input[name="transaction_ids[]"]:checked');
            checkedBoxes.forEach(checkbox => {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'transaction_ids[]';
                hiddenInput.value = checkbox.value;
                assignForm.appendChild(hiddenInput);
            });
        });
    }
});
