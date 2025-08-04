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
    const modalTagSelectedCountSpan = document.getElementById('modal-tag-selected-count');
    const clearSelectionBtn = document.getElementById('clear-selection');
    const assignForm = document.getElementById('assign-form');
    const assignTagForm = document.getElementById('assign-tag-form');

    function updateBulkActions() {
        const checkedBoxes = document.querySelectorAll('input[name="transaction_ids[]"]:checked');
        const count = checkedBoxes.length;

        if (count > 0) {
            bulkActions.classList.remove('d-none');
            selectedCountSpan.textContent = count;
            if (modalSelectedCountSpan) modalSelectedCountSpan.textContent = count;
            if (modalTagSelectedCountSpan) modalTagSelectedCountSpan.textContent = count;
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

    // Before submitting tag form, add selected transaction IDs
    if (assignTagForm) {
        assignTagForm.addEventListener('submit', function (e) {
            // Remove any existing transaction_ids inputs
            assignTagForm.querySelectorAll('input[name="transaction_ids[]"]').forEach(input => input.remove());

            // Add selected transaction IDs
            const checkedBoxes = document.querySelectorAll('input[name="transaction_ids[]"]:checked');
            checkedBoxes.forEach(checkbox => {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'transaction_ids[]';
                hiddenInput.value = checkbox.value;
                assignTagForm.appendChild(hiddenInput);
            });
        });
    }

    // Tag management
    let tagInputIndex = 1;

    // Get existing tags list for duplicate check
    const existingTags = [];
    const existingTagsData = [];
    document.querySelectorAll('.tag-item').forEach(item => {
        const tagName = item.dataset.tagName;
        existingTags.push(tagName.toLowerCase());
        existingTagsData.push({
            name: tagName,
            nameLower: tagName.toLowerCase(),
            id: item.dataset.tagId,
            element: item
        });
    });

    function addTagInputRow() {
        const container = document.getElementById('new-tags-container');
        const newRow = document.createElement('div');
        newRow.className = 'new-tag-input mb-2';
        newRow.innerHTML = `
            <div class="input-group">
                <input type="text" class="form-control new-tag-name-input" name="new_tags[${tagInputIndex}][name]" placeholder="Nom du tag">
                <button type="button" class="btn btn-outline-danger remove-tag-input">
                    <i class="fas fa-minus"></i>
                </button>
            </div>
            <small class="form-text text-muted duplicate-warning" style="display: none;">
                <i class="fas fa-exclamation-triangle text-warning"></i> Ce tag existe déjà
            </small>
            <div class="similar-tags-suggestions" style="display: none;">
                <small class="form-text text-info">
                    <i class="fas fa-lightbulb"></i> Tags similaires :
                </small>
                <div class="similar-tags-list mt-1"></div>
            </div>
        `;
        container.appendChild(newRow);
        tagInputIndex++;

        // Attach remove listener to new row
        newRow.querySelector('.remove-tag-input').addEventListener('click', function () {
            if (container.children.length > 1) {
                newRow.remove();
            }
        });

        // Add duplicate check listener to new input
        const newInput = newRow.querySelector('.new-tag-name-input');
        newInput.addEventListener('input', function () {
            checkForDuplicateTag(this);
        });
    }

    // Add tag input button
    const addTagInputBtn = document.getElementById('add-tag-input');
    if (addTagInputBtn) {
        addTagInputBtn.addEventListener('click', addTagInputRow);
    }

    // Remove tag input buttons (for existing ones)
    document.addEventListener('click', function (e) {
        if (e.target.closest('.remove-tag-input')) {
            const container = document.getElementById('new-tags-container');
            const tagInput = e.target.closest('.new-tag-input');

            if (container.children.length > 1) {
                tagInput.remove();
            }
        }
    });

    // Function to check for duplicate tags
    function checkForDuplicateTag(input) {
        const tagName = input.value.trim().toLowerCase();
        const tagContainer = input.closest('.new-tag-input');
        const warning = tagContainer.querySelector('.duplicate-warning');
        const suggestions = tagContainer.querySelector('.similar-tags-suggestions');
        const suggestionsList = tagContainer.querySelector('.similar-tags-list');

        // Reset states
        warning.style.display = 'none';
        suggestions.style.display = 'none';
        input.classList.remove('is-invalid', 'is-warning');
        suggestionsList.innerHTML = '';

        if (!tagName) {
            return;
        }

        // Check for exact duplicate
        if (existingTags.includes(tagName)) {
            warning.style.display = 'block';
            input.classList.add('is-invalid');
            return;
        }

        // Check for similar tags (contains the typed string)
        const similarTags = existingTagsData.filter(tag =>
            tag.nameLower.includes(tagName) && tag.nameLower !== tagName
        );

        if (similarTags.length > 0) {
            input.classList.add('is-warning');
            suggestions.style.display = 'block';

            similarTags.slice(0, 5).forEach(tag => { // Limit to 5 suggestions
                const suggestionTag = document.createElement('span');
                suggestionTag.className = 'badge me-1 mb-1 suggestion-tag';
                suggestionTag.style.cursor = 'pointer';
                suggestionTag.style.backgroundColor = getComputedStyle(tag.element.querySelector('.badge')).backgroundColor;
                suggestionTag.style.color = 'white';
                suggestionTag.textContent = tag.name;
                suggestionTag.title = 'Cliquer pour sélectionner ce tag existant';

                // Click handler to select the existing tag
                suggestionTag.addEventListener('click', function () {
                    const checkbox = document.getElementById('tag_' + tag.id);
                    const checkIcon = tag.element.querySelector('.tag-check');

                    if (!checkbox.checked) {
                        checkbox.checked = true;
                        checkIcon.style.display = 'block';
                        tag.element.style.opacity = '0.7';

                        // Clear the input and hide suggestions
                        input.value = '';
                        suggestions.style.display = 'none';
                        input.classList.remove('is-warning');

                        // Flash the selected tag
                        tag.element.style.transform = 'scale(1.1)';
                        setTimeout(() => {
                            tag.element.style.transform = 'scale(1)';
                        }, 200);
                    }
                });

                suggestionsList.appendChild(suggestionTag);
            });
        }
    }

    // Clickable existing tags
    document.addEventListener('click', function (e) {
        const tagItem = e.target.closest('.tag-item');
        if (tagItem) {
            const tagId = tagItem.dataset.tagId;
            const checkbox = document.getElementById('tag_' + tagId);
            const checkIcon = tagItem.querySelector('.tag-check');

            // Toggle checkbox
            checkbox.checked = !checkbox.checked;

            // Toggle visual indicator
            if (checkbox.checked) {
                checkIcon.style.display = 'block';
                tagItem.style.opacity = '0.7';
            } else {
                checkIcon.style.display = 'none';
                tagItem.style.opacity = '1';
            }
        }
    });

    // Add duplicate check to existing new tag inputs
    document.querySelectorAll('.new-tag-name-input').forEach(input => {
        input.addEventListener('input', function () {
            checkForDuplicateTag(this);
        });
    });
});
