// Lanes - a list of rows that carries its history in a gutter: one dot per
// row, in its lane, and a line from each row to each of its parents.
//
//   <div data-lanes aria-label="History">
//     <ol>
//       <li data-lanes-node="c3" data-lanes-parents="c2 b1">Merge the topic</li>
//       <li data-lanes-node="b1" data-lanes-parents="c1">On the topic</li>
//       <li data-lanes-node="c2" data-lanes-parents="c1">On main</li>
//       <li data-lanes-node="c1">First</li>
//     </ol>
//   </div>
//
// The rows are the page's own (a list, a table's rows, cards): they are read in
// the order of the page, each before its parents, and stay where they are -
// the element gets a gutter on its start side, an SVG in it. The lanes come
// from lanes() (./lanes.js); the rows' heights are measured, so a row of two
// lines is fine. Pointing at a row lights its links. No dependency.

import { lanes, lanePath } from './lanes.js';

const DEFAULTS = {
    laneWidth: 14,      // px between two lanes
    dot: 4,             // the radius of a row's dot
    padding: 6,         // px of gutter before the first lane and after the last
    colors: 8,          // the colours cycled through (--lanes-color-0 … -7)
    lineage: 'hover',   // hover | off
};

const SVG = 'http://www.w3.org/2000/svg';
const instances = new WeakMap();

function dataOptions(root) {
    const d = root.dataset;
    const o = {};
    ['laneWidth', 'dot', 'padding', 'colors'].forEach((name) => {
        const value = d['lanes' + name[0].toUpperCase() + name.slice(1)];
        if (value !== undefined && value !== '' && Number.isFinite(Number(value))) o[name] = Number(value);
    });
    if (d.lanesLineage) o.lineage = d.lanesLineage;
    return o;
}

export class Lanes {
    /**
     * @param {HTMLElement} root the [data-lanes] element
     * @param {object} options see DEFAULTS; the options win over the element's data-lanes-* attributes
     */
    constructor(root, options = {}) {
        if (instances.has(root)) return instances.get(root);
        instances.set(root, this);
        this.root = root;
        this.options = Object.assign({}, DEFAULTS, dataOptions(root), options);
        this.result = { rows: [], links: [], width: 0 };
        this.rows = [];          // the row elements, in order
        this.paths = [];         // {element, link}
        this.dots = [];          // the dot of each row
        this._listeners = [];
        this._given = { class: root.getAttribute('class'), style: root.getAttribute('style') };

        root.classList.add('lanes');
        this.svg = document.createElementNS(SVG, 'svg');
        this.svg.setAttribute('class', 'lanes-svg');
        this.svg.setAttribute('aria-hidden', 'true');
        this.svg.setAttribute('focusable', 'false');
        root.insertBefore(this.svg, root.firstChild);

        if (typeof ResizeObserver !== 'undefined') {
            // on the next frame: drawing sets the gutter, which must not resize within the observer's own call
            this._observer = new ResizeObserver(() => {
                cancelAnimationFrame(this._frame);
                this._frame = requestAnimationFrame(() => this.draw());
            });
            this._observer.observe(root);
        }
        this.refresh();
    }

    // -- static ----------------------------------------------------------

    /** Starts every [data-lanes] under `root` that is not started yet. */
    static ready(root = document, options = {}) {
        if (typeof document === 'undefined') return [];
        const scope = root && root.querySelectorAll ? root : document;
        const found = [];
        if (scope.matches && scope.matches('[data-lanes]')) found.push(scope);
        scope.querySelectorAll('[data-lanes]').forEach((el) => found.push(el));
        return found.map((el) => instances.get(el) || new Lanes(el, options));
    }

    static get(element) {
        return instances.get(element) || null;
    }

    // -- reading and drawing ---------------------------------------------

    /** Reads the rows again (a page of history added, rows removed) and draws. */
    refresh() {
        this._unbind();
        this.rows = [...this.root.querySelectorAll('[data-lanes-node]')];
        const nodes = this.rows.map((el) => ({
            id: el.dataset.lanesNode,
            parents: (el.dataset.lanesParents || '').split(/\s+/).filter(Boolean),
        }));
        this.result = lanes(nodes, { colors: this.options.colors });
        this.rows.forEach((el, i) => {
            const row = this.result.rows[i];
            if (!row) return;
            el.classList.add('lanes-row');
            el.dataset.lanesLane = String(row.lane);
        });
        this._bind();
        this.draw();
        this.root.dispatchEvent(new CustomEvent('lanes:render', { detail: { lanes: this, result: this.result } }));
        return this;
    }

    /** Measures the rows and draws the lanes again (it runs by itself when the element is resized). */
    draw() {
        const o = this.options;
        const gutter = this.result.width * o.laneWidth + 2 * o.padding;
        this.root.style.setProperty('--lanes-gutter', gutter + 'px');

        const box = this.root.getBoundingClientRect();
        const centres = [];
        let bottom = 0;
        this.rows.forEach((el) => {
            const rect = el.getBoundingClientRect();
            centres.push(rect.top - box.top + rect.height / 2);
            bottom = Math.max(bottom, rect.bottom - box.top);
        });
        const height = Math.max(bottom, this.root.scrollHeight);
        this.svg.setAttribute('width', String(gutter));
        this.svg.setAttribute('height', String(Math.ceil(height)));
        this.svg.setAttribute('viewBox', '0 0 ' + gutter + ' ' + Math.ceil(height));

        const x = (lane) => o.padding + lane * o.laneWidth + o.laneWidth / 2;
        const y = (row) => centres[Math.max(0, Math.min(centres.length - 1, row))] || 0;
        const rtl = getComputedStyle(this.root).direction === 'rtl';
        const fx = rtl ? (lane) => gutter - x(lane) : x;

        this.svg.textContent = '';
        this.paths = [];
        this.dots = [];
        const links = document.createElementNS(SVG, 'g');
        links.setAttribute('class', 'lanes-links');
        this.result.links.forEach((link) => {
            const p = document.createElementNS(SVG, 'path');
            p.setAttribute('class', 'lanes-link lanes-color-' + link.color + (link.open ? ' is-open' : ''));
            p.setAttribute('d', lanePath(link, { x: fx, y, bottom }));
            links.appendChild(p);
            this.paths.push({ element: p, link });
        });
        this.svg.appendChild(links);

        const dots = document.createElementNS(SVG, 'g');
        dots.setAttribute('class', 'lanes-dots');
        this.result.rows.forEach((row) => {
            const c = document.createElementNS(SVG, 'circle');
            c.setAttribute('class', 'lanes-dot lanes-color-' + row.color + (row.merge ? ' is-merge' : ''));
            c.setAttribute('cx', String(fx(row.lane)));
            c.setAttribute('cy', String(Math.round(y(row.index) * 100) / 100));
            c.setAttribute('r', String(o.dot));
            dots.appendChild(c);
            this.dots.push(c);
        });
        this.svg.appendChild(dots);
        if (this._lit !== undefined) this.highlight(this._lit);
        return this;
    }

    /** Lights the links of a row (its id), dims the rest; null puts everything back. */
    highlight(id) {
        this._lit = id === null || id === undefined ? undefined : String(id);
        const lit = this._lit;
        this.root.classList.toggle('is-lit', lit !== undefined);
        this.paths.forEach(({ element, link }) => element.classList.toggle('is-lit', lit !== undefined && (link.from === lit || link.to === lit)));
        this.result.rows.forEach((row, i) => {
            const on = lit !== undefined && (row.id === lit || row.parents.includes(lit) || row.children.includes(lit));
            if (this.dots[i]) this.dots[i].classList.toggle('is-lit', on);
            if (this.rows[i]) this.rows[i].classList.toggle('is-lit', on);
        });
        return this;
    }

    // -- events ------------------------------------------------------------

    _on(target, type, fn) {
        target.addEventListener(type, fn);
        this._listeners.push([target, type, fn]);
    }

    _bind() {
        if (this.options.lineage === 'off') return;
        this.rows.forEach((el) => {
            const id = el.dataset.lanesNode;
            this._on(el, 'pointerenter', () => this.highlight(id));
            this._on(el, 'pointerleave', () => this.highlight(null));
            this._on(el, 'focusin', () => this.highlight(id));
            this._on(el, 'focusout', () => this.highlight(null));
        });
    }

    _unbind() {
        this._listeners.forEach(([target, type, fn]) => target.removeEventListener(type, fn));
        this._listeners = [];
    }

    /** Gives the element back as it was: the gutter, the SVG, the rows' classes and attributes gone. */
    destroy() {
        this._unbind();
        if (this._observer) this._observer.disconnect();
        cancelAnimationFrame(this._frame);
        this.svg.remove();
        this.rows.forEach((el) => {
            el.classList.remove('lanes-row', 'is-lit');
            if (!el.getAttribute('class')) el.removeAttribute('class');
            delete el.dataset.lanesLane;
        });
        // the inline style the script set is written back to the attribute lazily: take the gutter
        // out and read the attribute first, or Chrome writes an empty style="" after the removal
        this.root.style.removeProperty('--lanes-gutter');
        this.root.getAttribute('style');
        ['class', 'style'].forEach((name) => {
            const value = this._given[name];
            if (value === null) this.root.removeAttribute(name);
            else this.root.setAttribute(name, value);
        });
        this.rows = [];
        this.paths = [];
        this.dots = [];
        instances.delete(this.root);
    }
}

Lanes.lanes = lanes;
Lanes.path = lanePath;

if (typeof window !== 'undefined') {
    window.Lanes = window.Lanes || Lanes;
    const start = () => Lanes.ready();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
    // transparent.js swaps the page without loading it: the new lists start then
    window.addEventListener('transparent:load', start);
}

export default Lanes;
