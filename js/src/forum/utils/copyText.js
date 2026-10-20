/**
 * Copy text to the clipboard; resolves to whether it worked.
 *
 * The Clipboard API exists only on secure (https) pages and can be refused by the
 * browser — then fall back to a selected, off-screen textarea + execCommand('copy')
 * (which also needs the click's user activation, still valid at that point).
 *
 * Declared first and exported at the bottom: flarum-webpack-config's module-registry
 * loader reads the first word after the default-export keywords as the module's name
 * (it would register "async" and break the whole forum bundle). For the same reason
 * keep that keyword pair out of this file's comments.
 */
async function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (e) {
            // fall through to the legacy path
        }
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.top = '-1000px';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    let ok = false;
    try {
        ok = document.execCommand('copy');
    } catch (e) {
        ok = false;
    }
    document.body.removeChild(textarea);

    return ok;
}

export default copyText;
