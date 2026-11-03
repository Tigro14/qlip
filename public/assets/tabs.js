// Onglets de badges sur le tableau de bord. Le template rend la liste complete : tous les
// panneaux sont la, et les onglets sont des liens vers une ancre. Sans JavaScript la page
// reste utilisable telle quelle, cliquer fait defiler jusqu au panneau. Le script ajoute les
// roles, masque le panneau non choisi, et ecrit l ancre sans bouger la page, pour que le choix
// tienne au rechargement.

// Les roles ne sont pas poses dans le template : ils ne sont vrais que si un seul panneau est
// montre, ce que seul le script fait. Un role=tab avec tous les panneaux ouverts serait une
// page annoncee comme des onglets alors qu elle n en est pas.

function panelOf(tab) {
    const href = tab.getAttribute('href') || '';

    return href.startsWith('#') ? document.getElementById(href.slice(1)) : null;
}

function select(container, tab) {
    const panel = panelOf(tab);

    if (!panel) {
        return;
    }

    for (const other of container.querySelectorAll('.tab')) {
        other.setAttribute('aria-selected', other === tab ? 'true' : 'false');
        other.setAttribute('tabindex', other === tab ? '0' : '-1');
    }

    for (const other of container.querySelectorAll('.panel')) {
        other.hidden = other !== panel;
    }
}

function bind(container) {
    const tabs = [...container.querySelectorAll('.tab')];
    const tablist = container.querySelector('.tablist');

    if (tabs.length === 0 || tablist === null) {
        return;
    }

    tablist.setAttribute('role', 'tablist');

    for (const tab of tabs) {
        const panel = panelOf(tab);

        tab.setAttribute('role', 'tab');

        if (panel !== null) {
            tab.setAttribute('aria-controls', panel.id);
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', tab.id);
        }

        tab.addEventListener('click', (event) => {
            // Le defilement est evite : l ancre est garde pour le rechargement, mais la page
            // ne saute pas jusqu au panneau.
            event.preventDefault();

            select(container, tab);

            if (panel !== null) {
                history.replaceState(null, '', '#' + panel.id);
            }
        });
    }

    // Navigation au clavier dans le groupe d onglets : les fleches se deplacent entre les
    // onglets, pas entre les panneaux.
    tablist.addEventListener('keydown', (event) => {
        const current = document.activeElement;
        const index = tabs.indexOf(current);

        if (index === -1) {
            return;
        }

        let next = index;

        if (event.key === 'ArrowRight') {
            next = (index + 1) % tabs.length;
        } else if (event.key === 'ArrowLeft') {
            next = (index - 1 + tabs.length) % tabs.length;
        } else if (event.key === 'Home') {
            next = 0;
        } else if (event.key === 'End') {
            next = tabs.length - 1;
        } else {
            return;
        }

        event.preventDefault();

        select(container, tabs[next]);
        tabs[next].focus();
    });

    // Le choix vient de l ancre quand elle designe un panneau, sinon le premier onglet.
    const fromHash = tabs.find((tab) => panelOf(tab) !== null && panelOf(tab).id === location.hash.slice(1));

    select(container, fromHash || tabs[0]);
}

document.querySelectorAll('[data-tabs]').forEach(bind);
