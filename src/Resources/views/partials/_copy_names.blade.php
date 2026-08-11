{{--
    Copy an account's character names to the clipboard, one per line, for
    pasting straight into an alliance blacklist check, a spreadsheet, or an
    in-game search.

    Params:
      $names  (array)  — plain strings, already resolved. Blanks are dropped.
      $cid    (string) — unique id suffix so several of these can coexist on
                         one page without their textareas colliding.

    Clipboard write mirrors the Structure Compliance copy button: the async API
    on a secure context, falling back to execCommand for plain-HTTP installs
    (a self-hosted SeAT on http:// is common, and navigator.clipboard is
    undefined there).
--}}
@php
    $copyNames = array_values(array_filter(array_map('trim', (array) ($names ?? []))));
    $copyId    = $cid ?? uniqid();
@endphp

@if(!empty($copyNames))
    <span class="hr-copy-names-wrap" style="display: inline-flex; align-items: center;">
        <button type="button" class="btn btn-sm btn-hr-secondary hr-copy-names"
                data-target="hrCopyNames_{{ $copyId }}"
                title="{{ trans('hr-manager::players.copy_names_title', ['count' => count($copyNames)]) }}">
            <i class="fas fa-copy"></i> {{ trans('hr-manager::players.copy_names') }}
        </button>
        {{-- readonly + off-screen rather than hidden: a display:none textarea
             can't be selected, which the execCommand fallback needs. --}}
        <textarea id="hrCopyNames_{{ $copyId }}" readonly aria-hidden="true"
                  style="position:absolute; left:-9999px; top:0; width:1px; height:1px; opacity:0;">{{ implode("\n", $copyNames) }}</textarea>
    </span>

    {{-- One delegated listener for every button on the page, however many
         times this partial is included. The window flag keeps it to a single
         registration without relying on include order. --}}
    <script>
    (function () {
        if (window.__hrCopyNamesBound) { return; }
        window.__hrCopyNamesBound = true;

        var COPIED = @json(trans('hr-manager::players.copy_names_done'));

        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.hr-copy-names') : null;
            if (!btn) { return; }

            var ta = document.getElementById(btn.getAttribute('data-target'));
            if (!ta) { return; }

            var flash = function () {
                if (btn.getAttribute('data-orig') === null) {
                    btn.setAttribute('data-orig', btn.innerHTML);
                }
                btn.innerHTML = '<i class="fas fa-check"></i> ' + COPIED;
                setTimeout(function () {
                    btn.innerHTML = btn.getAttribute('data-orig');
                }, 1500);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(ta.value).then(flash, function () { legacy(ta, flash); });
            } else {
                legacy(ta, flash);
            }
        });

        function legacy(ta, cb) {
            ta.focus();
            ta.select();
            try { if (document.execCommand('copy')) { cb(); } } catch (err) {}
            ta.blur();
        }
    })();
    </script>
@endif
