(function (global) {
    'use strict';

    var existing = global && global._;
    if (existing) {
        if (typeof existing.extend !== 'function') {
            existing.extend = function extend(target) {
                if (target == null) {
                    target = {};
                }
                for (var i = 1; i < arguments.length; i++) {
                    var source = arguments[i];
                    if (!source) {
                        continue;
                    }
                    for (var key in source) {
                        if (Object.prototype.hasOwnProperty.call(source, key)) {
                            target[key] = source[key];
                        }
                    }
                }
                return target;
            };
        }
        if (typeof existing.range !== 'function') {
            existing.range = function range(start, stop, step) {
                if (stop == null) {
                    stop = start || 0;
                    start = 0;
                }
                if (step == null) {
                    step = stop < start ? -1 : 1;
                }
                if (step === 0) {
                    throw new Error('Step cannot be zero');
                }
                var result = [];
                var ascending = step > 0;
                if (ascending) {
                    for (var i = start; i < stop; i += step) {
                        result.push(i);
                    }
                } else {
                    for (var j = start; j > stop; j += step) {
                        result.push(j);
                    }
                }
                return result;
            };
        }
        return;
    }

    function extend(target) {
        if (target == null) {
            target = {};
        }
        for (var i = 1; i < arguments.length; i++) {
            var source = arguments[i];
            if (!source) {
                continue;
            }
            for (var key in source) {
                if (Object.prototype.hasOwnProperty.call(source, key)) {
                    target[key] = source[key];
                }
            }
        }
        return target;
    }

    function range(start, stop, step) {
        if (stop == null) {
            stop = start || 0;
            start = 0;
        }
        if (step == null) {
            step = stop < start ? -1 : 1;
        }
        if (step === 0) {
            throw new Error('Step cannot be zero');
        }
        var result = [];
        var ascending = step > 0;
        if (ascending) {
            for (var i = start; i < stop; i += step) {
                result.push(i);
            }
        } else {
            for (var j = start; j > stop; j += step) {
                result.push(j);
            }
        }
        return result;
    }

    global._ = {
        extend: extend,
        range: range
    };
}(typeof window !== 'undefined' ? window : this));
