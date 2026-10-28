document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    const sidebar = document.querySelector('#sidebar');
    document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', event => {
        event.stopPropagation();
        body.classList.toggle('sidebar-open');
    });
    document.addEventListener('click', event => {
        if (body.classList.contains('sidebar-open') && sidebar && !sidebar.contains(event.target) && !event.target.closest('[data-sidebar-toggle]')) {
            body.classList.remove('sidebar-open');
        }
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') body.classList.remove('sidebar-open');
    });

    document.querySelectorAll('[data-tabs]').forEach(tabs => {
        const buttons = [...tabs.querySelectorAll('[data-tab]')];
        const panels = [...document.querySelectorAll('[data-tab-panel]')];
        buttons.forEach(button => button.addEventListener('click', () => {
            const selected = button.dataset.tab;
            buttons.forEach(item => item.classList.toggle('active', item === button));
            panels.forEach(panel => panel.classList.toggle('is-hidden', panel.dataset.tabPanel !== selected));
            const url = new URL(window.location.href);
            url.searchParams.set('tab', selected);
            window.history.replaceState({}, '', url);
        }));
    });

    document.querySelectorAll('[data-table-filter]').forEach(input => {
        const table = document.getElementById(input.dataset.tableFilter);
        if (!table) return;
        input.addEventListener('input', () => {
            const query = input.value.trim().toLocaleLowerCase('fr');
            table.querySelectorAll('tbody tr').forEach(row => {
                row.hidden = query !== '' && !row.textContent.toLocaleLowerCase('fr').includes(query);
            });
        });
    });

    document.querySelectorAll('.table--sortable th[data-sort]').forEach((heading, column) => {
        heading.tabIndex = 0;
        heading.setAttribute('role', 'button');
        const sort = () => {
            const table = heading.closest('table');
            const tbody = table.tBodies[0];
            const direction = heading.dataset.direction === 'asc' ? 'desc' : 'asc';
            table.querySelectorAll('th[data-sort]').forEach(item => {
                delete item.dataset.direction;
                item.removeAttribute('aria-sort');
            });
            heading.dataset.direction = direction;
            heading.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');
            const rows = [...tbody.rows];
            rows.sort((left, right) => {
                const a = left.cells[column], b = right.cells[column];
                const rawA = a.dataset.value ?? a.textContent.trim();
                const rawB = b.dataset.value ?? b.textContent.trim();
                const numberA = Number(rawA.replace(/\s/g, '').replace(',', '.'));
                const numberB = Number(rawB.replace(/\s/g, '').replace(',', '.'));
                const comparison = rawA !== '' && rawB !== '' && Number.isFinite(numberA) && Number.isFinite(numberB)
                    ? numberA - numberB
                    : rawA.localeCompare(rawB, 'fr', {numeric: true, sensitivity: 'base'});
                return direction === 'asc' ? comparison : -comparison;
            });
            rows.forEach(row => tbody.appendChild(row));
        };
        heading.addEventListener('click', sort);
        heading.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                sort();
            }
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    }));
});
