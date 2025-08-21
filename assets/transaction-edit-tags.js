import {TagManager} from './js/tag-manager.js';

// Tag management pour l'édition de transaction
document.addEventListener('DOMContentLoaded', function () {
    // Classe spécialisée pour l'édition de transaction
    class TransactionEditTagManager extends TagManager {
        onTagSelected(tag) {
            this.syncWithSymfonyForm();
        }

        onTagToggled(tagId, isChecked) {
            this.syncWithSymfonyForm();
        }

        syncWithSymfonyForm() {
            const checkedBoxes = document.querySelectorAll('.existing-tag-checkbox:checked');
            const symfonySelect = document.querySelector('select[name="transaction[tags][]"]');

            if (symfonySelect) {
                // Désélectionner toutes les options
                Array.from(symfonySelect.options).forEach(option => {
                    option.selected = false;
                });

                // Sélectionner les options correspondantes aux checkboxes cochées
                checkedBoxes.forEach(checkbox => {
                    const tagId = checkbox.value;
                    const option = symfonySelect.querySelector(`option[value="${tagId}"]`);
                    if (option) {
                        option.selected = true;
                    }
                });
            }
        }
    }

    // Initialiser le gestionnaire de tags
    const tagManager = new TransactionEditTagManager();
    tagManager.initializeEventListeners();

    // Initialisation : synchroniser l'état initial
    tagManager.syncWithSymfonyForm();
});
