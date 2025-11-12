$(function () {
    function parseJsonPayload(container, key) {
        var cached = container.data(key);
        if (cached && typeof cached !== 'string') {
            return cached;
        }

        var raw = typeof cached === 'string' ? cached : container.attr('data-' + key);
        if (!raw || typeof raw !== 'string') {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch (e) {
            try {
                var decoded = $('<textarea/>').html(raw).text();
                return JSON.parse(decoded);
            } catch (inner) {
                console.error('Invalid ' + key + ' JSON', inner);
            }
        }

        return null;
    }

    $('.bracket-container').each(function () {
        var container = $(this);
        var data = parseJsonPayload(container, 'bracket');
        var mode = container.data('mode');
        var field = container.data('target');
        if (!data) {
            return;
        }
        var readOnly = mode !== 'admin';
        var options = {
            init: data,
            teamWidth: 120,
            matchMargin: 10,
            disableToolbar: readOnly,
            disableTeamEdit: readOnly,
            save: function () {}
        };

        if (!readOnly && field) {
            options.save = function (updatedData) {
                $('#' + field).val(JSON.stringify(updatedData));
            };
        }

        container.bracket(options);
    });

    $('.group-container').each(function () {
        var container = $(this);
        var data = parseJsonPayload(container, 'group');
        var mode = container.data('mode');
        var field = container.data('target');
        if (!data) {
            return;
        }
        var options = { data: data };
        if (mode !== 'admin') {
            options.readonly = true;
        } else if (field) {
            options.onChange = function (updated) {
                $('#' + field).val(JSON.stringify(updated));
            };
        }
        container.group(options);
    });

    $('.js-confirm').on('submit', function (e) {
        var message = $(this).data('confirm') || 'Are you sure?';
        if (!window.confirm(message)) {
            e.preventDefault();
        }
    });
});
