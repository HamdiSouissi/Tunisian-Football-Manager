document.addEventListener('DOMContentLoaded', () => {
    const division = document.querySelector('#save-division');
    const options = [...document.querySelectorAll('.club-option')];
    const filterClubs = () => {
        if (!division) return;
        let selectedVisible = false;
        options.forEach(option => {
            const visible = option.dataset.division === division.value;
            option.hidden = !visible;
            const input = option.querySelector('input');
            input.disabled = !visible;
            if (visible && input.checked) selectedVisible = true;
        });
        if (!selectedVisible) {
            const first = options.find(option => !option.hidden);
            if (first) first.querySelector('input').checked = true;
        }
    };
    division?.addEventListener('change', filterClubs);
    filterClubs();
    document.querySelector('[data-save-form]')?.addEventListener('submit', event => {
        const button = event.currentTarget.querySelector('[data-submit-save]');
        button.disabled = true;
        button.textContent = 'Génération du monde…';
    });
});
