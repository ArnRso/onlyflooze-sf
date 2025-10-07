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

    // Gérer les clics sur les tags recommandés
    document.querySelectorAll('.recommended-tag').forEach(button => {
        button.addEventListener('click', function () {
            const tagId = this.dataset.tagId;

            // Trouver le tag dans les données existantes
            const existingTag = tagManager.existingTagsData.find(tag => tag.id === tagId);

            if (existingTag) {
                // Sélectionner le tag existant
                const checkbox = document.getElementById('tag_' + tagId);
                const checkIcon = existingTag.element.querySelector('.tag-check');

                if (!checkbox.checked) {
                    checkbox.checked = true;
                    checkIcon.style.display = 'block';
                    existingTag.element.style.opacity = '0.7';

                    // Synchroniser avec le formulaire Symfony
                    tagManager.syncWithSymfonyForm();

                    // Animation visuelle
                    existingTag.element.style.transform = 'scale(1.1)';
                    existingTag.element.scrollIntoView({behavior: 'smooth', block: 'nearest'});
                    setTimeout(() => {
                        existingTag.element.style.transform = 'scale(1)';
                    }, 200);

                    // Désactiver le bouton de recommandation
                    this.classList.remove('btn-outline-primary');
                    this.classList.add('btn-success');
                    this.querySelector('.fa-plus-circle').classList.remove('fa-plus-circle');
                    this.querySelector('.fas').classList.add('fa-check-circle');
                    this.disabled = true;
                }
            }
        });
    });

    // Gérer les clics sur les transactions récurrentes recommandées
    document.querySelectorAll('.recommended-recurring').forEach(button => {
        button.addEventListener('click', function () {
            const recurringId = this.dataset.recurringId;

            // Trouver le select de transaction récurrente
            const recurringSelect = document.querySelector('select[name="transaction[recurringTransaction]"]');

            if (recurringSelect) {
                // Sélectionner l'option correspondante
                const option = recurringSelect.querySelector(`option[value="${recurringId}"]`);

                if (option) {
                    recurringSelect.value = recurringId;

                    // Animation visuelle sur le select
                    recurringSelect.style.transform = 'scale(1.02)';
                    recurringSelect.style.transition = 'transform 0.2s';
                    setTimeout(() => {
                        recurringSelect.style.transform = 'scale(1)';
                    }, 200);

                    // Désactiver le bouton de recommandation
                    this.classList.remove('btn-outline-warning');
                    this.classList.add('btn-success');
                    this.querySelector('.fa-plus-circle').classList.remove('fa-plus-circle');
                    this.querySelector('.fas').classList.add('fa-check-circle');
                    this.disabled = true;
                }
            }
        });
    });
});
