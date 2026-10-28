(() => {
    'use strict';

    const root = document.getElementById('match-root');
    const autoButton = document.getElementById('match-auto');
    if (!root || !autoButton) return;

    const $ = (id) => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const canvas = $('match-pitch');
    const context = canvas.getContext('2d');
    const pitchWidth = 105;
    const pitchHeight = 68;
    const scale = canvas.width / pitchWidth;
    const homeColor = root.dataset.homeColor;
    const awayColor = root.dataset.awayColor;
    const namesToggle = $('match-names');
    const initialData = JSON.parse($('match-initial-data').textContent || 'null');
    let automatic = false;
    let busy = false;
    let speed = 1;
    let playOnce = false;

    const feedback = (message, error = false) => {
        const element = $('match-feedback');
        element.textContent = message;
        element.classList.toggle('error', error);
        root.classList.toggle('has-error', error);
    };

    const post = async (url, body = null) => {
        const response = await fetch(url, {
            method: 'POST',
            body,
            headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || data.message || `Le serveur a refusé cette action (HTTP ${response.status}).`);
        return data;
    };

    /**
     * Lecteur Canvas : il interpole deux snapshots autoritaires du moteur.
     * Aucun déplacement ou événement de jeu n'est inventé dans le navigateur.
     */
    class MatchAnimationEngine {
        constructor(onFrame, onComplete) {
            this.frames = [];
            this.players = {};
            this.fromFrame = null;
            this.index = 0;
            this.speed = 1;
            this.tickMs = 100;
            this.startedAt = 0;
            this.pausedAt = 0;
            this.playing = false;
            this.completed = true;
            this.raf = 0;
            this.onFrame = onFrame;
            this.onComplete = onComplete;
            this.lastDrawn = null;
        }

        load(visual, autoplay = false, showFinal = false, prepare = false) {
            this.stop();
            this.pausedAt = 0;
            this.players = visual.players || {};
            this.tickMs = Math.max(20, Number(visual.tick_ms) || 100);
            this.frames = Array.isArray(visual.frames) ? visual.frames : [];
            if (!this.frames.length) {
                this.drawEmpty();
                return;
            }
            this.fromFrame = this.lastDrawn || this.frames[0];
            this.index = showFinal ? Math.max(0, this.frames.length - 1) : 0;
            this.startedAt = performance.now();
            this.completed = !autoplay && (!prepare || this.frames.length <= 1);
            this.pausedAt = prepare && !this.completed ? performance.now() : 0;
            this.playing = autoplay && this.frames.length > 1;
            if (showFinal) {
                this.fromFrame = this.frames[this.index];
                this.draw(this.frames[this.index], this.frames[this.index], 1);
                this.completed = true;
            } else if (autoplay) {
                this.raf = requestAnimationFrame((time) => this.tick(time));
            } else {
                this.draw(this.fromFrame, this.frames[this.index], 1);
            }
        }

        hasUnfinished() {
            return this.frames.length > 1 && !this.completed;
        }

        play() {
            if (!this.frames.length || this.completed) return false;
            const now = performance.now();
            if (this.pausedAt) this.startedAt += now - this.pausedAt;
            this.pausedAt = 0;
            this.playing = true;
            cancelAnimationFrame(this.raf);
            this.raf = requestAnimationFrame((time) => this.tick(time));
            return true;
        }

        pause() {
            if (this.playing) this.pausedAt = performance.now();
            this.playing = false;
            cancelAnimationFrame(this.raf);
            root.classList.add('is-paused');
        }

        stop() {
            this.playing = false;
            cancelAnimationFrame(this.raf);
        }

        setSpeed(nextSpeed) {
            const now = performance.now();
            const oldDuration = this.tickMs / this.speed;
            const progress = this.playing ? Math.min(1, (now - this.startedAt) / oldDuration) : 0;
            this.speed = nextSpeed;
            if (this.playing) this.startedAt = now - progress * (this.tickMs / this.speed);
        }

        tick(now) {
            if (!this.playing) return;
            root.classList.remove('is-paused');
            const duration = this.tickMs / this.speed;
            const progress = Math.min(1, Math.max(0, (now - this.startedAt) / duration));
            const target = this.frames[this.index];
            this.draw(this.fromFrame || target, target, progress);
            if (progress < 1) {
                this.raf = requestAnimationFrame((time) => this.tick(time));
                return;
            }
            this.fromFrame = target;
            this.lastDrawn = target;
            if (this.index < this.frames.length - 1) {
                this.index += 1;
                this.startedAt = now;
                this.raf = requestAnimationFrame((time) => this.tick(time));
                return;
            }
            this.playing = false;
            this.completed = true;
            this.onComplete();
        }

        drawEmpty() {
            this.drawPitch();
            this.lastDrawn = null;
        }

        draw(from, to, rawProgress) {
            const progress = rawProgress * rawProgress * (3 - 2 * rawProgress);
            this.drawPitch();
            const fromPlayers = this.playerMap(from);
            const toPlayers = this.playerMap(to);
            const ids = new Set([...Object.keys(fromPlayers), ...Object.keys(toPlayers)]);
            ids.forEach((id) => {
                const start = fromPlayers[id] || toPlayers[id];
                const end = toPlayers[id] || fromPlayers[id];
                if (!start || !end) return;
                const player = {
                    ...end,
                    x: this.lerp(start.x, end.x, progress),
                    y: this.lerp(start.y, end.y, progress),
                    departed: Boolean(end.departed),
                    opacity: end.departed ? 1 - progress : (fromPlayers[id] ? 1 : progress)
                };
                this.drawPlayer(player);
            });
            const fromBall = this.ball(from);
            const toBall = this.ball(to);
            const ball = {
                x: this.lerp(fromBall.x, toBall.x, progress),
                y: this.lerp(fromBall.y, toBall.y, progress)
            };
            this.drawBall(ball);
            this.drawRestart(to);
            this.lastDrawn = to;
            this.onFrame(to);
        }

        playerMap(frame) {
            const map = {};
            const ball = this.ball(frame);
            ['home', 'away'].forEach((side) => {
                (frame?.players?.[side] || []).forEach((raw) => {
                    const player = Array.isArray(raw)
                        ? {id: raw[0], x: raw[1], y: raw[2], departed: raw[3] === 1, side}
                        : {...raw, side: raw.side || side, departed: Boolean(raw.departed)};
                    player.possession = !ball.inTransit && Number(ball.owner) === Number(player.id);
                    map[`${side}-${player.id}`] = player;
                });
            });
            return map;
        }

        ball(frame) {
            const value = frame?.ball;
            if (Array.isArray(value)) return {x: value[0], y: value[1], z: value[2], owner: value[3], inTransit: value[4] === 1};
            return {x: value?.x ?? 52.5, y: value?.y ?? 34, z: value?.z ?? 0, owner: value?.owner ?? value?.owner_id ?? 0, inTransit: Boolean(value?.in_transit)};
        }

        lerp(start, end, progress) {
            return Number(start) + (Number(end) - Number(start)) * progress;
        }

        drawPitch() {
            const ctx = context;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = '#17633f';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            for (let stripe = 0; stripe < 14; stripe += 1) {
                ctx.fillStyle = stripe % 2 ? '#1b6b45' : '#1d7149';
                ctx.fillRect(stripe * pitchWidth / 14 * scale, 0, pitchWidth / 14 * scale + 1, canvas.height);
            }
            ctx.strokeStyle = 'rgba(244,255,248,.86)';
            ctx.lineWidth = 1.6;
            ctx.beginPath();
            ctx.rect(0, 0, canvas.width, canvas.height);
            ctx.moveTo(52.5 * scale, 0);
            ctx.lineTo(52.5 * scale, canvas.height);
            ctx.stroke();
            this.circle(52.5, 34, 9.15, false);
            this.circle(52.5, 34, .25, true);
            [0, 105].forEach((x, index) => {
                const direction = index === 0 ? 1 : -1;
                const penaltyX = x + direction * 16.5;
                const goalX = x + direction * 5.5;
                ctx.beginPath();
                ctx.rect(Math.min(x, penaltyX) * scale, (34 - 20.16) * scale, 16.5 * scale, 40.32 * scale);
                ctx.rect(Math.min(x, goalX) * scale, (34 - 9.16) * scale, 5.5 * scale, 18.32 * scale);
                ctx.stroke();
                this.circle(x + direction * 11, 34, .25, true);
                ctx.beginPath();
                const centerX = (x + direction * 11) * scale;
                ctx.arc(centerX, 34 * scale, 9.15 * scale, index === 0 ? -0.93 : Math.PI - 0.93, index === 0 ? 0.93 : Math.PI + 0.93);
                ctx.stroke();
                ctx.strokeRect((index === 0 ? -2 : 105) * scale, (34 - 3.66) * scale, 2 * scale, 7.32 * scale);
            });
            [[0, 0], [105, 0], [0, 68], [105, 68]].forEach(([x, y], index) => {
                ctx.beginPath();
                ctx.arc(x * scale, y * scale, scale, index * Math.PI / 2, (index + 1) * Math.PI / 2);
                ctx.stroke();
            });
        }

        circle(x, y, radius, fill) {
            context.beginPath();
            context.arc(x * scale, y * scale, radius * scale, 0, Math.PI * 2);
            fill ? context.fill() : context.stroke();
        }

        drawPlayer(player) {
            const ctx = context;
            const metadata = this.players[player.id] || {};
            const x = player.x * scale;
            const y = player.y * scale;
            const radius = 1.28 * scale;
            const color = player.side === 'home' ? homeColor : awayColor;
            ctx.save();
            ctx.globalAlpha = player.opacity;
            if (player.possession) {
                ctx.beginPath();
                ctx.arc(x, y, radius + 5, 0, Math.PI * 2);
                ctx.strokeStyle = '#ffe381';
                ctx.lineWidth = 3;
                ctx.stroke();
            }
            ctx.shadowColor = 'rgba(0,0,0,.35)';
            ctx.shadowBlur = 7;
            ctx.shadowOffsetY = 3;
            ctx.beginPath();
            ctx.arc(x, y, radius, 0, Math.PI * 2);
            ctx.fillStyle = color;
            ctx.fill();
            ctx.shadowColor = 'transparent';
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 1.6;
            ctx.stroke();
            ctx.fillStyle = this.readableColor(color);
            ctx.font = 'bold 12px Inter, Arial, sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(String(metadata.number || player.number || ''), x, y + .5);
            if (namesToggle.checked && !player.departed) {
                const name = String(metadata.name || player.name || 'Joueur').split(' ').pop();
                ctx.font = '600 11px Inter, Arial, sans-serif';
                const width = ctx.measureText(name).width + 10;
                ctx.fillStyle = 'rgba(6,24,18,.78)';
                ctx.fillRect(x - width / 2, y + radius + 5, width, 17);
                ctx.fillStyle = '#ffffff';
                ctx.fillText(name, x, y + radius + 14);
            }
            ctx.restore();
        }

        readableColor(hex) {
            const value = String(hex).replace('#', '');
            if (value.length !== 6) return '#ffffff';
            const channels = [0, 2, 4].map((offset) => parseInt(value.slice(offset, offset + 2), 16));
            return channels[0] * .299 + channels[1] * .587 + channels[2] * .114 > 155 ? '#10251d' : '#ffffff';
        }

        drawRestart(frame) {
            const reason = frame?.restart;
            if (!reason) return;
            const labels = {kickoff: 'BUT !', corner: 'CORNER', throw_in: 'TOUCHE', goal_kick: 'SORTIE DE BUT', free_kick: 'COUP FRANC'};
            const label = labels[reason];
            if (!label) return;
            const ctx = context;
            ctx.save();
            ctx.font = '800 20px Inter, Arial, sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            const width = ctx.measureText(label).width + 30;
            ctx.fillStyle = reason === 'kickoff' ? 'rgba(255,215,100,.92)' : 'rgba(5,25,18,.82)';
            ctx.fillRect(52.5 * scale - width / 2, 5 * scale, width, 30);
            ctx.fillStyle = reason === 'kickoff' ? '#173326' : '#ffffff';
            ctx.fillText(label, 52.5 * scale, 5 * scale + 15);
            ctx.restore();
        }

        drawBall(ball) {
            const ctx = context;
            ctx.save();
            ctx.shadowColor = 'rgba(0,0,0,.5)';
            ctx.shadowBlur = 5;
            ctx.beginPath();
            ctx.arc(ball.x * scale, ball.y * scale, .43 * scale, 0, Math.PI * 2);
            ctx.fillStyle = '#fffdf2';
            ctx.fill();
            ctx.strokeStyle = '#23382d';
            ctx.lineWidth = 1;
            ctx.stroke();
            ctx.restore();
        }
    }

    const animation = new MatchAnimationEngine((frame) => {
        if (!frame) return;
        $('home-score').textContent = frame.home_score ?? frame.score?.[0] ?? 0;
        $('away-score').textContent = frame.away_score ?? frame.score?.[1] ?? 0;
        const owner = animation.ball(frame).owner;
        const player = owner ? animation.players[owner] : null;
        $('match-possessor').textContent = player ? `Possession : ${player.name}` : 'Ballon en jeu';
    }, () => {
        if (!automatic) playOnce = false;
        if (automatic && root.dataset.status === 'live') {
            window.setTimeout(() => { if (automatic && !busy) step(); }, Math.max(0, 100 / speed));
        } else {
            automatic = false;
            updatePlaybackButtons();
        }
    });

    const updatePlaybackButtons = () => {
        const live = root.dataset.status === 'live';
        autoButton.disabled = !live;
        $('match-step').disabled = !live;
        $('match-pause').disabled = !live;
        autoButton.textContent = automatic ? 'Lecture en cours' : '▶ Lecture';
        autoButton.classList.toggle('btn--primary', !automatic);
        autoButton.classList.toggle('btn--ghost', automatic);
    };

    const setStat = (id, value) => { const element = $(id); if (element) element.textContent = value; };
    const updateStats = (stats, sides) => {
        ['home', 'away'].forEach((side) => {
            const values = stats[side] || {};
            const possession = Number(stats.home?.possession || 0) + Number(stats.away?.possession || 0);
            setStat(`${side}-possession`, `${possession ? Math.round(Number(values.possession || 0) * 100 / possession) : 0}%`);
            ['shots', 'on_target', 'crosses', 'corners', 'interceptions', 'fouls', 'yellow', 'red'].forEach((key) => {
                setStat(`${side}-${key}`, Number(values[key] || 0));
            });
            setStat(`${side}-passes`, `${Number(values.passes_completed || 0)}/${Number(values.passes || 0)}`);
            setStat(`${side}-fatigue`, `${Number(sides[side]?.fatigue || 0)}%`);
            setStat(`${side}-substitutions`, `${Number(sides[side]?.substitutions || 0)}/5`);
        });
    };

    const addEvents = (events) => {
        events.forEach((event) => {
            const item = document.createElement('div');
            item.className = `commentary__item commentary__item--${event.type} commentary__item--new`;
            const minute = document.createElement('span');
            minute.textContent = `${event.minute}′`;
            const text = document.createElement('p');
            text.textContent = event.description;
            item.append(minute, text);
            $('match-events').prepend(item);
        });
    };

    const updateCoach = (sideState) => {
        if (!sideState) return;
        if ($('live-formation')) $('live-formation').textContent = sideState.tactic.formation;
        if ($('substitution-count')) $('substitution-count').textContent = `${sideState.substitutions}/5`;
        const updateOptions = (select, players, disabledIds = []) => {
            if (!select) return;
            select.innerHTML = '';
            players.forEach((player) => {
                const option = document.createElement('option');
                option.value = player.id;
                option.textContent = `${player.name} · ${player.position}`;
                option.disabled = disabledIds.includes(Number(player.id));
                select.append(option);
            });
        };
        updateOptions($('out-player'), sideState.lineup, sideState.lineup.filter((player) => player.sent_off).map((player) => player.id));
        updateOptions($('in-player'), sideState.substitutes);
        if ($('substitution-submit')) $('substitution-submit').disabled = sideState.substitutions >= 5 || sideState.substitutes.length === 0;
        const ratings = $('match-ratings');
        if (ratings) {
            ratings.innerHTML = '';
            const appearances = sideState.appearances?.length ? sideState.appearances : sideState.lineup;
            appearances.forEach((player) => {
                const row = document.createElement('div');
                const name = document.createElement('span');
                const rating = document.createElement('strong');
                name.textContent = player.name + (player.minutes ? ` ${player.minutes}′` : '');
                rating.textContent = Number(player.rating || 6).toFixed(1).replace('.', ',');
                rating.dataset.ratingPlayer = player.id;
                row.append(name);
                if (Number(player.experience) > 0) {
                    const experience = document.createElement('em');
                    experience.textContent = `+${player.experience} EXP`;
                    row.append(experience);
                }
                row.append(rating);
                ratings.append(row);
            });
        }
    };

    const update = (data, options = {}) => {
        root.dataset.status = data.status;
        $('match-status-badge').textContent = data.status === 'live' ? 'En direct' : (data.status === 'halftime' ? 'Mi-temps' : 'Terminé');
        $('match-minute').textContent = data.status === 'finished' ? 'Terminé' : `${data.display_minute || data.minute}′`;
        $('home-score').textContent = data.home_score;
        $('away-score').textContent = data.away_score;
        $('resume-form').classList.toggle('is-hidden', data.status !== 'halftime');
        $('skip-match-form')?.classList.toggle('is-hidden', data.status === 'finished');
        updateStats(data.stats, data.sides);
        updateCoach(data.sides[root.dataset.side]);
        if (data.replace_events) $('match-events').innerHTML = '';
        if (options.events !== false) addEvents(data.events || []);
        const weather = data.visual?.weather;
        if (weather) $('match-weather').textContent = `${weather.icon || '☀'} ${weather.label} · ${weather.temperature}°C · vent ${weather.wind} km/h`;
        if (data.visual) animation.load(data.visual, Boolean(options.autoplay), Boolean(options.showFinal), Boolean(options.prepare));
        if (data.status !== 'live') automatic = false;
        updatePlaybackButtons();
        if (data.man_of_the_match && !document.querySelector('.motm-panel')) {
            const panel = document.createElement('section');
            panel.className = 'panel motm-panel motm-panel--new';
            const body = document.createElement('div');
            body.className = 'panel__body';
            body.innerHTML = '<span class="eyebrow">Homme du match</span>';
            const name = document.createElement('strong');
            name.textContent = data.man_of_the_match.name;
            const rating = document.createElement('span');
            rating.className = 'rating rating--high';
            rating.textContent = Number(data.man_of_the_match.rating).toFixed(1);
            body.append(name, rating);
            panel.append(body);
            document.querySelector('.match-grid').before(panel);
        }
    };

    const step = async (manual = false) => {
        if (busy || root.dataset.status !== 'live') return;
        if (manual) automatic = false;
        busy = true;
        $('match-loader').classList.remove('is-hidden');
        updatePlaybackButtons();
        feedback(manual ? 'Calcul de la minute…' : `Simulation ×${speed}`);
        try {
            const data = await post(root.dataset.stepUrl);
            update(data, {autoplay: playOnce, prepare: !playOnce});
            feedback(data.status === 'halftime' ? 'Mi-temps' : (data.status === 'finished' ? 'Coup de sifflet final' : ''));
        } catch (error) {
            automatic = false;
            feedback(error.message, true);
        } finally {
            busy = false;
            $('match-loader').classList.add('is-hidden');
            updatePlaybackButtons();
        }
    };

    autoButton.addEventListener('click', () => {
        if (root.dataset.status !== 'live') return;
        automatic = true;
        playOnce = true;
        feedback(`Lecture ×${speed}`);
        updatePlaybackButtons();
        if (!animation.play()) step();
    });
    $('match-pause').addEventListener('click', () => {
        automatic = false;
        playOnce = false;
        animation.pause();
        feedback('Pause : le temps et les animations sont figés. Le banc reste disponible.');
        updatePlaybackButtons();
    });
    $('match-step').addEventListener('click', () => { animation.pause(); playOnce = true; step(true); });
    document.querySelectorAll('[data-speed]').forEach((button) => {
        button.addEventListener('click', () => {
            speed = Number(button.dataset.speed);
            animation.setSpeed(speed);
            document.querySelectorAll('[data-speed]').forEach((item) => {
                item.classList.toggle('active', item === button);
                item.setAttribute('aria-pressed', item === button ? 'true' : 'false');
            });
            feedback(`Vitesse ×${speed}`);
        });
    });
    $('resume-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) return;
        busy = true;
        try {
            const data = await post(root.dataset.resumeUrl);
            update(data, {showFinal: true});
            feedback('Seconde période prête. Lancez la lecture.');
        } catch (error) { feedback(error.message, true); }
        finally { busy = false; }
    });

    $('skip-match-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) return;
        automatic = false;
        playOnce = false;
        animation.stop();
        busy = true;
        feedback('Calcul du résultat final…');
        try {
            const data = await post(root.dataset.simulateUrl);
            update(data, {showFinal: true});
            feedback(`Match terminé : ${data.home_score}–${data.away_score}`);
        } catch (error) { feedback(error.message, true); }
        finally { busy = false; updatePlaybackButtons(); }
    });

    $('substitution-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) return;
        automatic = false;
        playOnce = false;
        animation.pause();
        const button = $('substitution-submit');
        button.disabled = true;
        busy = true;
        try {
            const data = await post(event.currentTarget.dataset.url, new FormData(event.currentTarget));
            update(data, {prepare: true});
            feedback('Remplacement préparé : le joueur entre par la touche à la reprise.');
        } catch (error) {
            feedback(error.message, true);
            button.disabled = false;
        } finally { busy = false; updatePlaybackButtons(); }
    });
    $('live-tactic-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) return;
        automatic = false;
        playOnce = false;
        animation.pause();
        const button = $('tactic-submit');
        button.disabled = true;
        busy = true;
        try {
            const data = await post(event.currentTarget.dataset.url, new FormData(event.currentTarget));
            update(data, {prepare: true});
            feedback('Consignes appliquées : le bloc se déplace progressivement vers ses nouvelles cibles.');
        } catch (error) { feedback(error.message, true); }
        finally { button.disabled = false; busy = false; updatePlaybackButtons(); }
    });

    namesToggle.addEventListener('change', () => {
        if (animation.lastDrawn) animation.draw(animation.lastDrawn, animation.lastDrawn, 1);
    });

    if (initialData) update(initialData, {events: false, showFinal: true});
    else animation.drawEmpty();
})();
