// Module partagé pour la gestion des tags
export class TagManager {
    constructor() {
        this.tagInputIndex = 1;
        this.existingTags = [];
        this.existingTagsData = [];
        this.initializeExistingTags();
    }

    initializeExistingTags() {
        document.querySelectorAll('.tag-item').forEach(item => {
            const tagName = item.dataset.tagName;
            this.existingTags.push(tagName.toLowerCase());
            this.existingTagsData.push({
                name: tagName,
                nameLower: tagName.toLowerCase(),
                id: item.dataset.tagId,
                element: item
            });
        });
    }

    addTagInputRow() {
        const container = document.getElementById('new-tags-container');
        const newRow = document.createElement('div');
        newRow.className = 'new-tag-input mb-2';
        newRow.innerHTML = `
            <div class="input-group">
                <input type="text" class="form-control new-tag-name-input" name="new_tags[${this.tagInputIndex}][name]" placeholder="Nom du tag">
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
        this.tagInputIndex++;

        // Attach remove listener to new row
        newRow.querySelector('.remove-tag-input').addEventListener('click', () => {
            if (container.children.length > 1) {
                newRow.remove();
            }
        });

        // Add duplicate check listener to new input
        const newInput = newRow.querySelector('.new-tag-name-input');
        newInput.addEventListener('input', () => {
            this.checkForDuplicateTag(newInput);
        });
    }

    checkForDuplicateTag(input) {
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
        if (this.existingTags.includes(tagName)) {
            warning.style.display = 'block';
            input.classList.add('is-invalid');
            return;
        }

        // Check for similar tags (contains the typed string)
        const similarTags = this.existingTagsData.filter(tag =>
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
                suggestionTag.addEventListener('click', () => {
                    this.selectExistingTag(tag, input, suggestions);
                });

                suggestionsList.appendChild(suggestionTag);
            });
        }
    }

    selectExistingTag(tag, input, suggestions) {
        const checkbox = document.getElementById('tag_' + tag.id);
        const checkIcon = tag.element.querySelector('.tag-check');

        if (!checkbox.checked) {
            checkbox.checked = true;
            checkIcon.style.display = 'block';
            tag.element.style.opacity = '0.7';

            // Call callback for additional synchronization if needed
            this.onTagSelected?.(tag);

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
    }

    handleTagClick(tagItem) {
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

        // Call callback for additional synchronization if needed
        this.onTagToggled?.(tagId, checkbox.checked);
    }

    initializeEventListeners() {
        // Add tag input button
        const addTagInputBtn = document.getElementById('add-tag-input');
        if (addTagInputBtn) {
            addTagInputBtn.addEventListener('click', () => this.addTagInputRow());
        }

        // Remove tag input buttons
        document.addEventListener('click', (e) => {
            if (e.target.closest('.remove-tag-input')) {
                const container = document.getElementById('new-tags-container');
                const tagInput = e.target.closest('.new-tag-input');

                if (container.children.length > 1) {
                    tagInput.remove();
                }
            }
        });

        // Clickable existing tags
        document.addEventListener('click', (e) => {
            const tagItem = e.target.closest('.tag-item');
            if (tagItem) {
                this.handleTagClick(tagItem);
            }
        });

        // Add duplicate check to existing new tag inputs
        document.querySelectorAll('.new-tag-name-input').forEach(input => {
            input.addEventListener('input', () => {
                this.checkForDuplicateTag(input);
            });
        });
    }

    // Callback methods that can be overridden
    onTagSelected(_tag) {
        // Override this method for custom behavior when a tag is selected from suggestions
    }

    onTagToggled(_tagId, _isChecked) {
        // Override this method for custom behavior when a tag is toggled
    }
}