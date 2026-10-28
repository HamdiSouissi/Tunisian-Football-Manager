document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('#match-root');
    if (!root) return;

    const stepButton = document.querySelector('#match-step');
    const autoButton = document.querySelector('#match-auto');
    const feedback = document.querySelector('#match-feedback');
    const substitutionForm = document.querySelector('#substitution-form');
    const tacticForm = document.querySelector('#live-tactic-form');
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const statusLabels = {scheduled: 'Avant-match', live: 'En direct', halftime: 'Mi-temps', finished: 'Terminé'};
    let timer = null;
    let busy = false;

    const setFeedback = (message = '', isError = false) => {
        if (!feedback) return;
        feedback.textContent = message;
        feedback.classList.toggle('text-danger', isError);
        feedback.classList.toggle('text-success', message !== '' && !isError);
    };

    const post = async (url, body = {}) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token},
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({message: 'Réponse invalide du serveur.'}));
        if (!response.ok) throw new Error(data.message || 'Erreur du match');
        return data;
    };

    const addEvents = events => {
        const list = document.querySelector('#match-events');
        if (!list) return;
        events.forEach(event => {
            const row = document.createElement('div');
            row.className = `commentary__item commentary__item--${event.type}`;
            const minute = document.createElement('span');
            minute.textContent = `${event.minute}′`;
            const text = document.createElement('p');
            text.textContent = event.description;
            row.append(minute, text);
            list.appendChild(row);
        });
        list.scrollTop = list.scrollHeight;
    };

    const fillPlayers = (select, players, emptyLabel) => {
        if (!select) return;
        const previous = select.value;
        select.replaceChildren();
        players.forEach(player => {
            const option = document.createElement('option');
            option.value = String(player.id);
            option.textContent = `${player.name} · ${player.position}`;
            select.appendChild(option);
        });
        if (players.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = emptyLabel;
            select.appendChild(option);
        } else if (players.some(player => String(player.id) === previous)) {
            select.value = previous;
        }
    };

    const updateCoach = data => {
        const coach = data.sides?.[root.dataset.side];
        if (!coach) return;
        fillPlayers(document.querySelector('#out-player'), coach.lineup, 'Aucun titulaire');
        fillPlayers(document.querySelector('#in-player'), coach.substitutes, 'Aucun remplaçant disponible');
        const count = document.querySelector('#substitution-count');
        if (count) count.textContent = `${coach.substitutions}/5`;
        const substitutionButton = document.querySelector('#substitution-submit');
        if (substitutionButton) substitutionButton.disabled = data.status === 'finished' || coach.substitutions >= 5 || coach.substitutes.length === 0;
        const formation = document.querySelector('#live-formation');
        if (formation) formation.textContent = coach.tactic.formation;
        if (tacticForm) {
            Object.entries(coach.tactic).forEach(([name, value]) => {
                const field = tacticForm.elements.namedItem(name);
                if (field) field.value = value;
            });
        }
        [substitutionForm, tacticForm].forEach(form => {
            form?.querySelectorAll('select').forEach(select => { select.disabled = data.status === 'finished'; });
        });
    };

    const stop = () => {
        if (timer !== null) clearInterval(timer);
        timer = null;
        if (autoButton) autoButton.textContent = '▶ Lecture auto';
    };

    const update = data => {
        root.dataset.status = data.status;
        document.querySelector('#home-score').textContent = data.home_score;
        document.querySelector('#away-score').textContent = data.away_score;
        document.querySelector('#match-minute').textContent = data.status === 'finished' ? 'Terminé' : `${data.minute}′`;
        document.querySelector('#match-status-badge').textContent = statusLabels[data.status] || data.status;
        const home = data.stats.home, away = data.stats.away;
        const total = Math.max(1, home.possession + away.possession);
        document.querySelector('#home-possession').textContent = `${Math.round(home.possession * 100 / total)}%`;
        document.querySelector('#away-possession').textContent = `${Math.round(away.possession * 100 / total)}%`;
        ['shots', 'on_target', 'fouls', 'yellow', 'red'].forEach(key => {
            const suffix = key.replace('_', '-');
            const homeValue = document.querySelector(`#home-${suffix}`), awayValue = document.querySelector(`#away-${suffix}`);
            if (homeValue) homeValue.textContent = home[key];
            if (awayValue) awayValue.textContent = away[key];
        });
        addEvents(data.events || []);
        updateCoach(data);

        if (data.status === 'halftime') {
            stop();
            if (stepButton) stepButton.disabled = true;
            if (autoButton) autoButton.disabled = true;
            document.querySelector('#resume-form')?.classList.remove('is-hidden');
        } else if (data.status === 'finished') {
            stop();
            if (stepButton) stepButton.disabled = true;
            if (autoButton) autoButton.disabled = true;
            document.querySelector('#resume-form')?.classList.add('is-hidden');
            document.querySelector('#match-minute').textContent = 'Terminé';
            [substitutionForm, tacticForm].forEach(form => form?.querySelectorAll('button, select').forEach(field => { field.disabled = true; }));
        } else {
            if (stepButton) stepButton.disabled = false;
            if (autoButton) autoButton.disabled = false;
            document.querySelector('#resume-form')?.classList.add('is-hidden');
        }
    };

    const step = async () => {
        if (busy || root.dataset.status !== 'live') return;
        busy = true;
        try {
            update(await post(root.dataset.stepUrl));
            setFeedback();
        } catch (error) {
            setFeedback(error.message, true);
            stop();
        } finally {
            busy = false;
        }
    };

    const submitAction = async (form, url, successMessage) => {
        if (!form) return;
        stop();
        if (busy) {
            setFeedback('Attendez la fin de la minute avant cet ajustement.', true);
            return;
        }
        busy = true;
        form.setAttribute('aria-busy', 'true');
        try {
            const data = await post(url, Object.fromEntries(new FormData(form)));
            update(data);
            setFeedback(successMessage);
        } catch (error) {
            setFeedback(error.message, true);
        } finally {
            busy = false;
            form.removeAttribute('aria-busy');
        }
    };

    stepButton?.addEventListener('click', step);
    autoButton?.addEventListener('click', () => {
        if (timer !== null) {
            stop();
        } else if (root.dataset.status === 'live') {
            autoButton.textContent = '⏸ Pause';
            setFeedback();
            step();
            timer = setInterval(step, 900);
        }
    });
    document.querySelector('#resume-form')?.addEventListener('submit', event => {
        event.preventDefault();
        submitAction(event.currentTarget, root.dataset.resumeUrl, 'Seconde période reprise.');
    });
    substitutionForm?.addEventListener('submit', event => {
        event.preventDefault();
        submitAction(event.currentTarget, event.currentTarget.dataset.url, 'Remplacement appliqué. Lecture en pause.');
    });
    tacticForm?.addEventListener('submit', event => {
        event.preventDefault();
        submitAction(event.currentTarget, event.currentTarget.dataset.url, 'Organisation et consignes appliquées. Lecture en pause.');
    });
});
