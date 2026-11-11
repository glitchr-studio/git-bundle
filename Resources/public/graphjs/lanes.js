// Lanes - a history drawn as lanes, one row per node - no DOM, no dependency.
//
//   lanes([{id, parents: [ids]}], options)
//
// takes nodes in the order they are listed - each before its parents, as
// `git log --topo-order` gives commits - and gives each a lane (a column) and
// each link to a parent the lanes it travels through, so that a list of rows
// can carry its graph in a gutter beside it:
//
//  - a node takes the lane that was waiting for it (the lane of its first
//    child), or the first free lane when nothing waits for it (a tip);
//  - its first parent continues its lane, even when another lane waits for
//    it too; each other parent (a merge) is waited for in a lane of its own,
//    the lowest free one, unless a lane already waits for it;
//  - two lanes that wait for the same node meet at that node's row, and the
//    lane it does not take is free again from the next row on;
//  - a parent that is not in the list (a history read page by page) keeps its
//    lane open to the bottom (`open`).
//
// So a lane never carries links to two different nodes on the same row, and no
// link runs under a node of another lane. Each lane change gets a colour, kept by the
// first parent: a branch is one colour from its tip to where it forks.

const DEFAULTS = {
    colors: 8,      // how many colours are cycled through
};

/**
 * Lays a history out in lanes. Returns {rows: [{id, index, lane, color,
 * parents, children, merge, through}], links: [{from, to, fromRow, toRow,
 * fromLane, lane, toLane, color, open}], width}:
 *
 * - `rows[i].through`: the lanes that pass the row without stopping there,
 *   `[{lane, color}]` - what a renderer draws as straight lines;
 * - `links[j].lane`: the lane the link runs down, from the row after its
 *   child to the row before its parent (`toRow`; the number of rows when the
 *   parent is not listed);
 * - `width`: how many lanes the widest row needs.
 */
export function lanes(nodes, options = {}) {
    const o = Object.assign({}, DEFAULTS, options);
    const palette = Math.max(1, o.colors | 0);
    const list = [];
    const index = new Map();
    (nodes || []).forEach((node) => {
        const id = String(node && node.id);
        if (!node || index.has(id)) return;
        index.set(id, list.length);
        const parents = [];
        (node.parents || []).forEach((p) => {
            const parent = String(p);
            if (parent !== id && !parents.includes(parent)) parents.push(parent);
        });
        list.push({ id, parents });
    });

    const rows = [];
    const links = [];
    // active[k]: the lane k, {wait: the node it waits for, color, links: the links it carries}; null when free
    const active = [];
    let nextColor = 0;
    let width = 0;
    const free = () => {
        const k = active.indexOf(null);
        return k === -1 ? active.length : k;
    };

    list.forEach((node, i) => {
        // the lanes that wait for this node: the first is its own, the others end here
        const waiting = [];
        active.forEach((slot, k) => { if (slot && slot.wait === node.id) waiting.push(k); });
        let lane;
        let color;
        if (waiting.length) {
            lane = waiting[0];
            color = active[lane].color;
        } else {
            lane = free();
            color = nextColor++ % palette;
        }
        waiting.forEach((k) => {
            active[k].links.forEach((link) => { link.toRow = i; link.toLane = lane; link.open = false; });
            active[k] = null;
        });

        // what passes the row without stopping: every other lane still busy
        const through = [];
        active.forEach((slot, k) => { if (slot && k !== lane) through.push({ lane: k, color: slot.color }); });

        const row = {
            id: node.id,
            index: i,
            lane,
            color,
            parents: node.parents.slice(),
            children: [],
            merge: node.parents.length > 1,
            through,
        };
        rows.push(row);

        // the parents: the first continues this lane, the others wait in a lane of their own
        active[lane] = null;
        node.parents.forEach((parent, p) => {
            // the first parent always continues this lane (two lanes waiting for one node meet at
            // its row); another parent shares a lane that already waits for it
            let k = p === 0 ? -1 : active.findIndex((slot) => slot && slot.wait === parent);
            let linkColor;
            if (k !== -1) {
                linkColor = active[k].color;
            } else {
                k = p === 0 ? lane : free();
                linkColor = p === 0 ? color : nextColor++ % palette;
                active[k] = { wait: parent, color: linkColor, links: [] };
            }
            const link = {
                from: node.id,
                to: parent,
                fromRow: i,
                toRow: list.length,
                fromLane: lane,
                lane: k,
                toLane: k,
                color: linkColor,
                open: true,
            };
            active[k].links.push(link);
            links.push(link);
        });
        while (active.length && active[active.length - 1] === null) active.pop();
        width = Math.max(width, lane + 1, active.length, ...through.map((t) => t.lane + 1));
    });

    rows.forEach((row) => row.parents.forEach((parent) => {
        const at = index.get(parent);
        if (at !== undefined) rows[at].children.push(row.id);
    }));

    return { rows, links, width };
}

/**
 * The SVG path of a link, for rows `rowHeight` px high whose centres are at
 * `y(row)` (default: row × rowHeight + rowHeight / 2) and lanes `laneWidth`
 * px apart from `x(lane)` (default: lane × laneWidth + laneWidth / 2): from
 * the child's node into its lane over the next row, down the lane, and into
 * the parent's node over the last row. An open link runs to `bottom` (the
 * bottom of the last row).
 */
export function lanePath(link, options = {}) {
    const rowHeight = options.rowHeight || 28;
    const laneWidth = options.laneWidth || 14;
    const x = options.x || ((lane) => lane * laneWidth + laneWidth / 2);
    const y = options.y || ((row) => row * rowHeight + rowHeight / 2);
    const r = (n) => Math.round(n * 100) / 100;
    const x0 = x(link.fromLane);
    const y0 = y(link.fromRow);
    const xl = x(link.lane);
    let d = 'M' + r(x0) + ' ' + r(y0);

    const curve = (xa, ya, xb, yb) => {
        if (xa === xb) return ' L' + r(xb) + ' ' + r(yb);
        const mid = (ya + yb) / 2;
        return ' C' + r(xa) + ' ' + r(mid) + ' ' + r(xb) + ' ' + r(mid) + ' ' + r(xb) + ' ' + r(yb);
    };

    if (link.open) {
        const bottom = options.bottom !== undefined ? options.bottom : y(link.toRow - 1) + rowHeight / 2;
        // the last row: one curve to the bottom; otherwise into the lane over the next row, then down
        if (link.fromRow + 1 >= link.toRow) return d + curve(x0, y0, xl, bottom);
        return d + curve(x0, y0, xl, y(link.fromRow + 1)) + ' L' + r(xl) + ' ' + r(bottom);
    }

    const xt = x(link.toLane);
    const yt = y(link.toRow);
    if (link.toRow === link.fromRow + 1) {
        // the next row: one curve from node to node
        return d + curve(x0, y0, xt, yt);
    }
    const y1 = y(link.fromRow + 1);
    const y2 = y(link.toRow - 1);
    d += curve(x0, y0, xl, y1);
    if (y2 > y1) d += ' L' + r(xl) + ' ' + r(y2);
    d += curve(xl, y2, xt, yt);
    return d;
}

export default lanes;
