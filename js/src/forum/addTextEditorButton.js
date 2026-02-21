import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import TextEditor from 'flarum/common/components/TextEditor';

export default function addTextEditorButton() {
    extend(TextEditor.prototype, 'toolbarItems', function (items) {
        const editor = this;
        
        items.add(
            'magnet',
            m('button.Button.Button--icon.Button--link', {
                type: 'button',
                title: app.translator.trans('tryhackx-magnet-link.forum.editor.tooltip'),
                onclick: () => {
                    insertMagnetTag(editor);
                }
            }, m('i.fas.fa-magnet')),
            10
        );
    });
}

function insertMagnetTag(editor) {
    // Znajdź textarea bezpośrednio - najpewniejsza metoda
    const ta = document.querySelector('.TextEditor textarea, .TextEditor-editor textarea, .Composer textarea');
    
    if (!ta) {
        console.warn('MagnetLink: Could not find textarea');
        return;
    }
    
    const start = ta.selectionStart;
    const end = ta.selectionEnd;
    const selectedText = ta.value.substring(start, end);
    const beforeSelection = ta.value.substring(0, start);
    const afterSelection = ta.value.substring(end);
    
    let newValue;
    let newCursorPos;
    
    if (selectedText) {
        // Jest zaznaczony tekst - otocz tagami [magnet][/magnet]
        newValue = beforeSelection + '[magnet]' + selectedText + '[/magnet]' + afterSelection;
        // Kursor na końcu całego tagu
        newCursorPos = start + '[magnet]'.length + selectedText.length + '[/magnet]'.length;
    } else {
        // Brak zaznaczenia - wstaw puste tagi i ustaw kursor między nimi
        newValue = beforeSelection + '[magnet][/magnet]' + afterSelection;
        // Kursor między tagami
        newCursorPos = start + '[magnet]'.length;
    }
    
    // Ustaw nową wartość
    ta.value = newValue;
    
    // Ustaw kursor
    ta.selectionStart = newCursorPos;
    ta.selectionEnd = newCursorPos;
    
    // Focus na textarea
    ta.focus();
    
    // Wywołaj zdarzenia żeby Flarum zauważył zmianę
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    ta.dispatchEvent(new Event('change', { bubbles: true }));
    
    // Jeśli Flarum używa własnego edytora, zaktualizuj też jego stan
    try {
        const textArea = editor.attrs?.composer?.editor;
        if (textArea && typeof textArea.setValue === 'function') {
            textArea.setValue(newValue);
        }
    } catch (e) {
        // Ignoruj błędy - textarea już zostało zaktualizowane
    }
}
