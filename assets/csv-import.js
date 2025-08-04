// Utilitaires pour l'interface d'import CSV

export class CsvImportWizard {
    constructor() {
        this.init();
    }

    init() {
        // Initialiser les écouteurs d'événements
        this.initFileUpload();
        this.initConfigurationForm();
        this.initPreviewUpdates();
    }

    initFileUpload() {
        const fileInput = document.querySelector('#csv_file_upload_file');
        if (fileInput) {
            fileInput.addEventListener('change', (e) => {
                this.handleFileSelection(e.target.files[0]);
            });
        }
    }

    initConfigurationForm() {
        // Écouteurs pour les changements de configuration
        const configInputs = document.querySelectorAll('[data-csv-config]');
        configInputs.forEach(input => {
            input.addEventListener('change', () => {
                this.updatePreview();
            });
        });

        // Gestion du toggle entre profil existant et nouveau profil
        const useExistingRadio = document.querySelector('input[name="profile_choice"][value="existing"]');
        const createNewRadio = document.querySelector('input[name="profile_choice"][value="new"]');

        if (useExistingRadio && createNewRadio) {
            useExistingRadio.addEventListener('change', () => this.toggleProfileMode('existing'));
            createNewRadio.addEventListener('change', () => this.toggleProfileMode('new'));
        }

        // Gestion du type de montant
        const amountTypeSelect = document.querySelector('[name="amount_type"]');
        if (amountTypeSelect) {
            amountTypeSelect.addEventListener('change', () => this.toggleAmountFields());
        }

        // Suggestions clickables
        const suggestions = document.querySelectorAll('.suggestion-item');
        suggestions.forEach(suggestion => {
            suggestion.addEventListener('click', () => {
                const delimiter = suggestion.getAttribute('data-delimiter');
                const encoding = suggestion.getAttribute('data-encoding');

                document.querySelector('[name="delimiter"]').value = delimiter;
                document.querySelector('[name="encoding"]').value = encoding;

                // Marquer visuellement comme sélectionné
                suggestions.forEach(s => s.classList.remove('border-primary', 'bg-light'));
                suggestion.classList.add('border-primary', 'bg-light');

                this.updatePreview();
            });
        });

        // Déclenchement initial de la preview
        setTimeout(() => this.updatePreview(), 500);
    }

    initPreviewUpdates() {
        // Mise à jour automatique de la prévisualisation
        const mappingInputs = document.querySelectorAll('[data-column-mapping]');
        mappingInputs.forEach(input => {
            input.addEventListener('change', () => {
                this.updateMappingHighlight();
                this.updatePreview();
            });
        });
    }

    handleFileSelection(file) {
        if (!file) return;

        // Afficher le nom du fichier
        const fileName = document.querySelector('#selected-file-name');
        if (fileName) {
            fileName.textContent = file.name;
        }

        // Prévisualisation rapide du contenu
        this.previewFileContent(file);
    }

    previewFileContent(file) {
        const reader = new FileReader();
        reader.onload = (e) => {
            const content = e.target.result;
            const lines = content.split('\n').slice(0, 5); // Premières 5 lignes

            const preview = document.querySelector('#file-content-preview');
            if (preview && lines.length > 0) {
                preview.innerHTML = `
                    <div class="alert alert-info">
                        <h6>Aperçu du fichier :</h6>
                        <pre class="mb-0 small">${lines.join('\n')}</pre>
                    </div>
                `;
            }
        };
        reader.readAsText(file, 'UTF-8');
    }

    toggleProfileMode(mode) {
        const existingSection = document.querySelector('#existing-profile-section');
        const newProfileSection = document.querySelector('#new-profile-section');

        if (mode === 'existing') {
            existingSection?.classList.remove('d-none');
            newProfileSection?.classList.add('d-none');
        } else {
            existingSection?.classList.add('d-none');
            newProfileSection?.classList.remove('d-none');
        }
    }

    toggleAmountFields() {
        const amountType = document.querySelector('[name="amount_type"]')?.value;
        const singleSection = document.querySelector('#single-amount-section');
        const splitSection = document.querySelector('#split-amount-section');

        if (amountType === 'single') {
            singleSection?.classList.remove('d-none');
            splitSection?.classList.add('d-none');
        } else {
            singleSection?.classList.add('d-none');
            splitSection?.classList.remove('d-none');
        }

        // Mettre à jour la preview
        this.updatePreview();
    }

    updateMappingHighlight() {
        // Mettre en surbrillance les colonnes sélectionnées dans la prévisualisation
        const preview = document.querySelector('#csv-preview-table');
        if (!preview) return;

        // Enlever toutes les surbrillances existantes
        preview.querySelectorAll('td, th').forEach(cell => {
            cell.classList.remove('bg-primary', 'bg-info', 'bg-success', 'bg-warning', 'bg-danger', 'text-white');
        });

        // Récupérer les colonnes sélectionnées
        const dateCol = document.querySelector('[name="date_column"]')?.value;
        const labelCol = document.querySelector('[name="label_column"]')?.value;
        const amountCol = document.querySelector('[name="amount_column"]')?.value;
        const creditCol = document.querySelector('[name="credit_column"]')?.value;
        const debitCol = document.querySelector('[name="debit_column"]')?.value;

        // Appliquer les surbrillances
        preview.querySelectorAll('tr').forEach(row => {
            const cells = row.querySelectorAll('td, th');
            cells.forEach((cell, index) => {
                if (index == dateCol) {
                    cell.classList.add('bg-primary', 'text-white');
                    cell.title = 'Colonne Date';
                } else if (index == labelCol) {
                    cell.classList.add('bg-info', 'text-white');
                    cell.title = 'Colonne Libellé';
                } else if (index == amountCol) {
                    cell.classList.add('bg-success', 'text-white');
                    cell.title = 'Colonne Montant';
                } else if (index == creditCol) {
                    cell.classList.add('bg-success', 'text-white');
                    cell.title = 'Colonne Crédit';
                } else if (index == debitCol) {
                    cell.classList.add('bg-danger', 'text-white');
                    cell.title = 'Colonne Débit';
                }
            });
        });
    }

    async updatePreview() {
        // Récupérer les paramètres actuels
        const settings = this.getCurrentSettings();

        if (!settings) return;

        try {
            const response = await fetch('/csv-import/api/preview-with-settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(settings)
            });

            if (response.ok) {
                const preview = await response.json();
                this.displayPreview(preview);
            } else {
                const error = await response.json();
                this.displayError(error.error || 'Erreur lors de la prévisualisation');
            }
        } catch (error) {
            console.error('Erreur de prévisualisation:', error);
            this.displayError('Erreur de communication avec le serveur');
        }
    }

    getCurrentSettings() {
        const delimiter = document.querySelector('[name="delimiter"]')?.value;
        const encoding = document.querySelector('[name="encoding"]')?.value;
        const dateFormat = document.querySelector('[name="date_format"]')?.value;
        const amountType = document.querySelector('[name="amount_type"]')?.value;
        const hasHeader = document.querySelector('[name="has_header"]')?.checked;

        const dateColumn = document.querySelector('[name="date_column"]')?.value;
        const labelColumn = document.querySelector('[name="label_column"]')?.value;

        if (!delimiter || !dateColumn || !labelColumn) {
            return null;
        }

        const columnMapping = {
            date: parseInt(dateColumn),
            label: parseInt(labelColumn)
        };

        if (amountType === 'single') {
            const amountColumn = document.querySelector('[name="amount_column"]')?.value;
            if (amountColumn) columnMapping.amount = parseInt(amountColumn);
        } else {
            const creditColumn = document.querySelector('[name="credit_column"]')?.value;
            const debitColumn = document.querySelector('[name="debit_column"]')?.value;
            if (creditColumn) columnMapping.credit = parseInt(creditColumn);
            if (debitColumn) columnMapping.debit = parseInt(debitColumn);
        }

        return {
            delimiter,
            encoding,
            dateFormat,
            amountType,
            hasHeader,
            columnMapping
        };
    }

    displayPreview(preview) {
        const container = document.querySelector('#preview-container');
        if (!container) return;

        if (!preview.sample_data || preview.sample_data.length === 0) {
            container.innerHTML = `
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    Aucune donnée à prévisualiser. Vérifiez votre configuration.
                </div>
            `;
            return;
        }

        // Affichage du CSV brut d'abord
        let html = '<div class="mb-3">';
        html += '<h6>Aperçu du fichier CSV :</h6>';
        html += '<div class="table-responsive"><table class="table table-sm table-bordered" id="csv-preview-table">';

        // En-tête avec numéros de colonnes
        html += '<thead class="table-dark"><tr>';
        const firstRow = preview.sample_data[0];
        if (firstRow.raw_data) {
            firstRow.raw_data.forEach((cell, index) => {
                html += `<th class="text-center">Col ${index}</th>`;
            });
        }
        html += '</tr></thead>';

        // Données brutes
        html += '<tbody>';
        preview.sample_data.forEach(row => {
            html += '<tr>';
            if (row.raw_data) {
                row.raw_data.forEach(cell => {
                    html += `<td class="small" style="max-width: 150px; overflow: hidden; text-overflow: ellipsis;">${this.escapeHtml(cell || '')}</td>`;
                });
            }
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        html += '</div>';

        // Affichage des données parsées
        html += '<div class="mb-3">';
        html += '<h6>Données interprétées :</h6>';
        html += '<div class="table-responsive"><table class="table table-sm table-striped">';
        html += '<thead class="table-secondary"><tr>';
        html += '<th>Date</th><th>Libellé</th><th>Montant</th><th>Statut</th>';
        html += '</tr></thead><tbody>';

        preview.sample_data.forEach(row => {
            const statusClass = row.status === 'error' ? 'table-danger' : (row.status === 'warning' ? 'table-warning' : '');
            html += `<tr class="${statusClass}">`;

            // Date
            if (row.parsed_data && row.parsed_data.date) {
                const date = new Date(row.parsed_data.date);
                html += `<td>${date.toLocaleDateString('fr-FR')}</td>`;
            } else {
                html += '<td class="text-muted">-</td>';
            }

            // Libellé
            if (row.parsed_data && row.parsed_data.label) {
                html += `<td>${this.escapeHtml(row.parsed_data.label)}</td>`;
            } else {
                html += '<td class="text-muted">-</td>';
            }

            // Montant
            if (row.parsed_data && typeof row.parsed_data.amount !== 'undefined') {
                const amount = parseFloat(row.parsed_data.amount);
                const badgeClass = amount >= 0 ? 'success' : 'danger';
                html += `<td><span class="badge bg-${badgeClass}">${amount.toFixed(2)} €</span></td>`;
            } else {
                html += '<td class="text-muted">-</td>';
            }

            // Statut
            if (row.status === 'ok') {
                html += '<td><span class="badge bg-success">OK</span></td>';
            } else if (row.status === 'warning') {
                html += '<td><span class="badge bg-warning">Attention</span></td>';
            } else {
                html += '<td><span class="badge bg-danger">Erreur</span></td>';
            }

            html += '</tr>';
        });
        html += '</tbody></table></div>';
        html += '</div>';

        // Informations de résumé
        html += `
            <div class="alert alert-info">
                <strong>Résumé :</strong>
                ${preview.total_rows || 0} lignes trouvées,
                ${preview.valid_rows || 0} valides
                ${preview.errors && preview.errors.length > 0 ? `, ${preview.errors.length} erreurs` : ''}
            </div>
        `;

        container.innerHTML = html;

        // Mettre à jour les surbrillances sur le CSV brut
        setTimeout(() => this.updateMappingHighlight(), 100);
    }

    displayError(message) {
        const container = document.querySelector('#preview-container');
        if (container) {
            container.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> ${this.escapeHtml(message)}
                </div>
            `;
        }
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Auto-initialisation quand le DOM est prêt
document.addEventListener('DOMContentLoaded', () => {
    new CsvImportWizard();
});
