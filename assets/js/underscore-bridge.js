(function (global) {
    if (typeof global._ === 'function') {
        return;
    }

    var candidate = null;

    if (global.module && typeof global.module.exports === 'function') {
        candidate = global.module.exports;
    } else if (global.module && global.module.exports && typeof global.module.exports._ === 'function') {
        candidate = global.module.exports._;
    } else if (global.exports && typeof global.exports === 'function') {
        candidate = global.exports;
    } else if (global.exports && typeof global.exports._ === 'function') {
        candidate = global.exports._;
    }

    if (!candidate && global._ && typeof global._ === 'object' && typeof global._.VERSION === 'string') {
        candidate = global._.default || global._;
    }

    if (typeof candidate === 'function') {
        global._ = candidate;
    }
})(typeof window !== 'undefined' ? window : this);
