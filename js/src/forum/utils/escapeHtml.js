// No Flarum imports: unit-tested with plain Node (js/tests).

const ENTITIES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

/**
 * Escape a value for an HTML string — between tags AND inside an attribute value
 * (both quote kinds).
 *
 * The cards and the tooltip are built as HTML strings. The previous helper (a
 * detached element's textContent read back through innerHTML) escapes only & < >:
 * fine between tags, but inside title="…" / value="…" a quote in a magnet name —
 * which comes from the post's own dn= parameter — closed the attribute and let the
 * name add attributes of its own (onmouseover=…): stored XSS for every viewer.
 */
export default function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ENTITIES[c]);
}
