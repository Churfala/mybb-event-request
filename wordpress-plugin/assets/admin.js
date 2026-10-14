/* MyBB Event Request - settings page: repeatable custom-question rows. */
(function () {
    'use strict';

    var list = document.getElementById('evreq-questions');
    var addBtn = document.getElementById('evreq-add-question');
    var template = document.getElementById('evreq-question-template');
    if (!list || !addBtn || !template) {
        return;
    }

    // New rows need an index that cannot collide with a saved one. Start past the highest
    // index already on the page.
    var nextIndex = list.querySelectorAll('.evreq-q-row').length;

    function addRow() {
        var html = template.innerHTML.replace(/\{\{i\}\}/g, String(nextIndex));
        nextIndex++;

        var holder = document.createElement('div');
        holder.innerHTML = html.trim();
        var row = holder.firstChild;
        list.appendChild(row);
        syncOptions(row);
    }

    // Only dropdown and checkbox questions have a list of options; hide the box otherwise.
    function syncOptions(row) {
        var type = row.querySelector('.evreq-q-type');
        var options = row.querySelector('.evreq-q-options');
        if (!type || !options) {
            return;
        }
        var needs = type.value === 'select' || type.value === 'checkbox';
        options.style.display = needs ? '' : 'none';
    }

    addBtn.addEventListener('click', addRow);

    list.addEventListener('click', function (e) {
        var remove = e.target.closest ? e.target.closest('.evreq-q-remove') : null;
        if (remove) {
            e.preventDefault();
            var rows = list.querySelectorAll('.evreq-q-row');
            if (rows.length > 1) {
                remove.closest('.evreq-q-row').remove();
            } else {
                // Keep at least one row; just clear the last one.
                var row = remove.closest('.evreq-q-row');
                row.querySelector('.evreq-q-label').value = '';
                row.querySelector('.evreq-q-options').value = '';
            }
        }
    });

    list.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('evreq-q-type')) {
            syncOptions(e.target.closest('.evreq-q-row'));
        }
    });
})();
