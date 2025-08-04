import 'bootstrap';
import 'bootstrap/dist/css/bootstrap.min.css';
import '@fortawesome/fontawesome-free/css/fontawesome.min.css';
import '@fortawesome/fontawesome-free/css/solid.min.css';
import './styles/app.css';

// Gestionnaire global pour les flash messages avec HTMX
document.addEventListener('updateFlashMessages', function (e) {
    const container = document.getElementById('flash-messages-container');
    if (container && e.detail && e.detail.url) {
        fetch(e.detail.url)
            .then(response => response.text())
            .then(html => {
                container.innerHTML = html;
            })
            .catch(error => console.error('Erreur lors du chargement des flash messages:', error));
    }
});
