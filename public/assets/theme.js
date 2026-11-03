// Theme. Le fichier est charge dans le head, sans defer : l attribut doit etre pose avant le
// premier paint, sinon la page s affiche en clair puis bascule.

// Pas de style inline : style-src 'self' autorise la feuille, pas une regle dans la page. La
// feuille regarde l attribut data-theme de html, donc le script ne fait que le poser.

// Le choix est stocke dans localStorage : il est par navigateur, pas par compte, et il ne
// passe pas par une route. Rien n est ecrit cote serveur, donc il n y a pas de jeton CSRF.

const KEY = 'qlip.theme';
const html = document.documentElement;

function stored() {
    try {
        return localStorage.getItem(KEY) || '';
    } catch (error) {
        // Le stockage est refuse en mode prive, ou par un navigateur qui le bloque : sans
        // stockage le theme suit la preference du systeme, ce qui reste utilisable.
        return '';
    }
}

function apply(choice) {
    if (choice === 'dark' || choice === 'light') {
        html.dataset.theme = choice;
    } else {
        // Pas d attribut : la feuille retombe sur la preference du systeme.
        html.removeAttribute('data-theme');
    }
}

function effective() {
    return html.dataset.theme || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
}

function show(button) {
    const choice = stored();

    button.textContent = 'Theme : ' + (choice || 'auto');
    button.setAttribute('aria-pressed', effective() === 'dark' ? 'true' : 'false');
}

function toggle(button) {
    const next = effective() === 'dark' ? 'light' : 'dark';

    try {
        localStorage.setItem(KEY, next);
    } catch (error) {
        // Sans stockage le switch ne bascule la page que pour la requete en cours.
    }

    apply(next);
    show(button);
}

// Pose le theme tout de suite, avant le contenu. Le bouton n existe pas encore : le script
// est dans le head, il est attendu a la fin du parse.
apply(stored());

function bind() {
    const button = document.querySelector('[data-theme-toggle]');

    if (!button) {
        return;
    }

    button.addEventListener('click', () => toggle(button));
    show(button);
}

document.addEventListener('DOMContentLoaded', bind);
