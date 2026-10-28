'use strict';

const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const drawnText = [];
const context2d = new Proxy({measureText: (text) => ({width: String(text).length * 7})}, {
    get(target, key) {
        if (key in target) return target[key];
        if (key === 'fillText') return (text) => drawnText.push(String(text));
        return () => undefined;
    },
    set(target, key, value) { target[key] = value; return true; }
});

const makeClassList = () => ({
    add: () => undefined, remove: () => undefined, toggle: () => undefined
});
const makeElement = (id = '') => ({
    id,
    dataset: {},
    classList: makeClassList(),
    children: [],
    disabled: false,
    checked: true,
    textContent: '',
    innerHTML: '',
    addEventListener: () => undefined,
    setAttribute: () => undefined,
    append(...children) { this.children.push(...children); },
    prepend(...children) { this.children.unshift(...children); }
});

const root = makeElement('match-root');
Object.assign(root.dataset, {status: 'live', side: 'home', homeColor: '#15966f', awayColor: '#2974b8'});
const canvas = makeElement('match-pitch');
canvas.width = 1050;
canvas.height = 680;
canvas.getContext = () => context2d;
const frame = (tick, score) => ({
    minute: 12, tick, score, restart: tick === 1 ? 'corner' : null,
    players: {home: [[1, 50 + tick, 30]], away: [[2, 55 - tick, 35]]},
    ball: [52, 32, 0, 1, 0]
});
const side = (id, name) => ({
    lineup: [{id, name, position: 'ST', rating: 7.2, minutes: 12, experience: 0}],
    substitutes: [], appearances: [], tactic: {formation: '4-3-3'}, substitutions: 0, fatigue: 4
});
const initial = {
    minute: 12, display_minute: '12', status: 'live', home_score: 2, away_score: 1,
    stats: {home: {possession: 7, shots: 2}, away: {possession: 5, shots: 1}},
    events: [], sides: {home: side(1, 'Domicile'), away: side(2, 'Extérieur')}, man_of_the_match: null,
    visual: {
        tick_ms: 100, frames: [frame(0, [2, 1]), frame(1, [2, 1])],
        players: {1: {id: 1, name: 'Domicile', number: 7}, 2: {id: 2, name: 'Extérieur', number: 9}},
        weather: {type: 'clear', label: 'Ciel dégagé', icon: '☀', temperature: 24, wind: 8}
    }
};
const elements = new Map([['match-root', root], ['match-pitch', canvas]]);
const byId = (id) => {
    if (!elements.has(id)) elements.set(id, makeElement(id));
    return elements.get(id);
};
byId('match-initial-data').textContent = JSON.stringify(initial);
const document = {
    getElementById: byId,
    querySelector: (selector) => selector === 'meta[name="csrf-token"]' ? {content: 'test'} : null,
    querySelectorAll: () => [],
    createElement: (tag) => makeElement(tag)
};
const sandbox = {
    document,
    window: {setTimeout: () => 0},
    performance: {now: () => 1000},
    requestAnimationFrame: () => 1,
    cancelAnimationFrame: () => undefined,
    fetch: async () => { throw new Error('Aucune requête réseau attendue dans ce test.'); },
    FormData: class {},
    console,
    JSON,
    Number,
    String,
    Boolean,
    Set,
    Math
};
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../public/assets/js/match.js'), 'utf8'), sandbox);

const assert = (condition, message) => { if (!condition) throw new Error(message); };
assert(byId('home-score').textContent === 2 && byId('away-score').textContent === 1, 'Le score de la frame compacte n’est pas restitué.');
assert(String(byId('match-possessor').textContent).includes('Domicile'), 'Le possesseur n’est pas identifié.');
assert(drawnText.includes('7') && drawnText.includes('9'), 'Les numéros des joueurs ne sont pas dessinés.');
assert(drawnText.includes('CORNER'), 'La reprise autoritaire n’est pas affichée.');
console.log('Client du match (frames compactes et Canvas) : OK');
