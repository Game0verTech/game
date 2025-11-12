$(function () {
    $('.bracket-container').each(function () {
        var container = $(this);
        var data = container.data('bracket');
        var mode = container.data('mode');
        var field = container.data('target');
        if (!data) {
            return;
        }
        if (typeof data === 'string') {
            try {
                data = JSON.parse(data);
            } catch (e) {
                console.error('Invalid bracket JSON', e);
                return;
            }
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
        var data = container.data('group');
        var mode = container.data('mode');
        var field = container.data('target');
        if (!data) {
            return;
        }
        if (typeof data === 'string') {
            try {
                data = JSON.parse(data);
            } catch (e) {
                console.error('Invalid group JSON', e);
                return;
            }
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
