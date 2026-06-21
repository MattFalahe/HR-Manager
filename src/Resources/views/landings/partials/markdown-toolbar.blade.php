{{--
    Minimal Markdown formatting toolbar. Bound by data-target to the
    textarea id passed in. Pure vanilla JS, no library dep. Designed
    for the recruitment landing form (headline + body) but reusable.

    Buttons emit canonical Markdown for everything except center-align,
    which has no native Markdown — emits a <center> tag (rendered as
    HTML by Str::markdown() with the default unsafe-html allow).

    Included multiple times per page (headline + body). The behavior
    script self-registers each toolbar via data-target so duplicates
    don't double-bind.
--}}
@once
<style>
    .hr-md-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        margin-bottom: 4px;
        padding: 6px;
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 4px 4px 0 0;
        border-bottom: none;
    }
    .hr-md-toolbar button {
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.08);
        color: #c9d1d9;
        padding: 4px 10px;
        border-radius: 3px;
        font-size: 0.85rem;
        cursor: pointer;
        transition: background 0.1s;
        min-width: 32px;
    }
    .hr-md-toolbar button:hover {
        background: rgba(102, 126, 234, 0.2);
        border-color: rgba(102, 126, 234, 0.4);
    }
    .hr-md-toolbar .hr-md-sep {
        width: 1px;
        background: rgba(255,255,255,0.08);
        margin: 2px 4px;
    }
    .hr-md-toolbar .hr-md-hint {
        margin-left: auto;
        font-size: 0.78rem;
        color: var(--hr-text-muted, #8b95a5);
        align-self: center;
    }
    textarea.hr-md-editor {
        border-radius: 0 0 4px 4px !important;
        font-family: 'Consolas', 'Monaco', monospace !important;
        font-size: 0.92rem !important;
    }
</style>
@endonce

<div class="hr-md-toolbar" data-target="{{ $target }}">
    <button type="button" data-action="bold" title="Bold (Ctrl+B)"><strong>B</strong></button>
    <button type="button" data-action="italic" title="Italic (Ctrl+I)"><em>I</em></button>
    <span class="hr-md-sep"></span>
    <button type="button" data-action="h1" title="Heading 1">H1</button>
    <button type="button" data-action="h2" title="Heading 2">H2</button>
    <button type="button" data-action="h3" title="Heading 3">H3</button>
    <span class="hr-md-sep"></span>
    <button type="button" data-action="ul" title="Bullet list"><i class="fas fa-list-ul"></i></button>
    <button type="button" data-action="ol" title="Numbered list"><i class="fas fa-list-ol"></i></button>
    <button type="button" data-action="quote" title="Blockquote"><i class="fas fa-quote-right"></i></button>
    <span class="hr-md-sep"></span>
    <button type="button" data-action="link" title="Insert link"><i class="fas fa-link"></i></button>
    <button type="button" data-action="center" title="Centre text"><i class="fas fa-align-center"></i></button>
    <button type="button" data-action="hr" title="Horizontal divider">&mdash;</button>
    <span class="hr-md-hint">Markdown supported. Preview by saving + opening the landing page.</span>
</div>

@once
<script>
(function () {
    // -----------------------------------------------------------------
    // Each .hr-md-toolbar is bound to the textarea with id=data-target.
    // Actions: wrap selection (bold/italic/center/link), prefix each
    // line of selection (h1-h3/ul/ol/quote), or insert at cursor (hr).
    // -----------------------------------------------------------------

    function applyAction(textarea, action) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const value = textarea.value;
        const selection = value.substring(start, end);

        let replacement = selection;
        let newCursorStart = start;
        let newCursorEnd = end;

        switch (action) {
            case 'bold':
                replacement = '**' + (selection || 'bold text') + '**';
                newCursorStart = start + 2;
                newCursorEnd = newCursorStart + (selection || 'bold text').length;
                break;
            case 'italic':
                replacement = '*' + (selection || 'italic text') + '*';
                newCursorStart = start + 1;
                newCursorEnd = newCursorStart + (selection || 'italic text').length;
                break;
            case 'h1':
            case 'h2':
            case 'h3':
                replacement = prefixLines(selection || 'Heading', '#'.repeat(action.slice(1)) + ' ');
                newCursorEnd = start + replacement.length;
                break;
            case 'ul':
                replacement = prefixLines(selection || 'Item', '- ');
                newCursorEnd = start + replacement.length;
                break;
            case 'ol':
                replacement = numberedLines(selection || 'Item');
                newCursorEnd = start + replacement.length;
                break;
            case 'quote':
                replacement = prefixLines(selection || 'Quote', '> ');
                newCursorEnd = start + replacement.length;
                break;
            case 'link':
                const url = prompt('Link URL:', 'https://');
                if (!url) return;
                const text = selection || prompt('Link text:', '') || 'link';
                replacement = '[' + text + '](' + url + ')';
                newCursorEnd = start + replacement.length;
                break;
            case 'center':
                // Markdown has no native centre. <center> is widely
                // supported by Markdown parsers (including SeAT's
                // CommonMark) and the public landing template renders
                // raw HTML safely from trusted director input.
                replacement = '<center>' + (selection || 'centered text') + '</center>';
                newCursorStart = start + 8;
                newCursorEnd = newCursorStart + (selection || 'centered text').length;
                break;
            case 'hr':
                replacement = (start > 0 && value[start - 1] !== '\n' ? '\n' : '') + '\n---\n\n';
                newCursorEnd = start + replacement.length;
                break;
        }

        textarea.value = value.substring(0, start) + replacement + value.substring(end);
        textarea.focus();
        textarea.setSelectionRange(newCursorStart, newCursorEnd);

        // Trigger input event so any frame listening (autosave, dirty
        // tracking) picks up the change.
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function prefixLines(text, prefix) {
        if (text === '') return prefix;
        return text.split('\n').map(line => prefix + line).join('\n');
    }

    function numberedLines(text) {
        const lines = text.split('\n');
        return lines.map((line, i) => (i + 1) + '. ' + line).join('\n');
    }

    function bindToolbar(toolbar) {
        const targetId = toolbar.getAttribute('data-target');
        const textarea = document.getElementById(targetId);
        if (!textarea) return;

        toolbar.querySelectorAll('button[data-action]').forEach(btn => {
            btn.addEventListener('click', e => {
                e.preventDefault();
                applyAction(textarea, btn.getAttribute('data-action'));
            });
        });

        // Keyboard shortcuts: Ctrl/Cmd + B / I
        textarea.addEventListener('keydown', e => {
            const ctrl = e.ctrlKey || e.metaKey;
            if (!ctrl) return;
            if (e.key === 'b' || e.key === 'B') {
                e.preventDefault();
                applyAction(textarea, 'bold');
            } else if (e.key === 'i' || e.key === 'I') {
                e.preventDefault();
                applyAction(textarea, 'italic');
            }
        });
    }

    function init() {
        document.querySelectorAll('.hr-md-toolbar').forEach(bindToolbar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
@endonce
