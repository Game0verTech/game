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
        if (mode === 'admin') {
            container.bracket({
                init: data,
                save: function (updatedData) {
                    if (field) {
                        $('#' + field).val(JSON.stringify(updatedData));
                    }
                }
            });
        } else {
            container.bracket({
                init: data,
                save: function () {},
                disableToolbar: true,
                teamWidth: 120,
                matchMargin: 10
            });
        }
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
