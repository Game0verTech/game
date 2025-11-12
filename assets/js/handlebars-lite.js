(function (global) {
    if (global.Handlebars && typeof global.Handlebars.compile === 'function') {
        return;
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).replace(/[&<>'"]/g, function (char) {
            switch (char) {
                case '&':
                    return '&amp;';
                case '<':
                    return '&lt;';
                case '>':
                    return '&gt;';
                case '"':
                    return '&quot;';
                case "'":
                    return '&#39;';
                default:
                    return char;
            }
        });
    }

    function truthy(value) {
        return !!value;
    }

    function compile(template) {
        if (typeof template !== 'string') {
            return function () {
                return '';
            };
        }

        var code = "var __ctx = context || {};\n";
        code += "var out = '';\n";
        code += "with (__ctx) {\n";

        var index = 0;
        var stack = [];
        while (index < template.length) {
            var start = template.indexOf('{{', index);
            if (start === -1) {
                var literal = template.slice(index);
                if (literal) {
                    code += "out += " + JSON.stringify(literal) + ";\n";
                }
                break;
            }

            var literalText = template.slice(index, start);
            if (literalText) {
                code += "out += " + JSON.stringify(literalText) + ";\n";
            }

            var end = template.indexOf('}}', start + 2);
            if (end === -1) {
                break;
            }

            var tag = template.slice(start + 2, end).trim();
            index = end + 2;

            if (!tag) {
                continue;
            }

            if (tag.indexOf('#if ') === 0) {
                var expr = tag.slice(4).trim();
                if (!expr) {
                    expr = '__ctx';
                } else if (expr === 'this') {
                    expr = '__ctx';
                }
                stack.push('if');
                code += "if (truthy((function(){try{return " + expr + ";}catch(e){return undefined;}})())) {\n";
            } else if (tag === 'else') {
                if (stack.length && stack[stack.length - 1] === 'if') {
                    code += "} else {\n";
                }
            } else if (tag === '/if') {
                if (stack.length && stack[stack.length - 1] === 'if') {
                    stack.pop();
                    code += "}\n";
                }
            } else {
                var exprValue = tag === 'this' ? '__ctx' : tag;
                code += "out += escapeHtml((function(){try{return " + exprValue + ";}catch(e){return '';}})());\n";
            }
        }

        while (stack.length) {
            var block = stack.pop();
            if (block === 'if') {
                code += "}\n";
            }
        }

        code += "}\n";
        code += "return out;";

        var render;
        try {
            render = new Function('context', 'escapeHtml', 'truthy', code);
        } catch (e) {
            console.error('Failed to compile template', e);
            return function () {
                return '';
            };
        }

        return function (context) {
            return render.call(context || {}, context || {}, escapeHtml, truthy);
        };
    }

    global.Handlebars = {
        compile: compile,
        helpers: {
            truthy: truthy,
            escape: escapeHtml,
        },
        escapeExpression: escapeHtml,
    };
})(typeof window !== 'undefined' ? window : this);
