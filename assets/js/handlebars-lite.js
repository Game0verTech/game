(function (global) {
    if (global.Handlebars && typeof global.Handlebars.compile === 'function') {
        return;
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).replace(/[&<>"']/g, function (char) {
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

    function createTextNode(value) {
        return { type: 'text', value: value };
    }

    function createVariableNode(expr) {
        return { type: 'variable', expr: expr };
    }

    function createIfNode(expr, truthyNodes, falsyNodes) {
        return { type: 'if', expr: expr, truthy: truthyNodes || [], falsy: falsyNodes || [] };
    }

    function createEachNode(expr, bodyNodes, emptyNodes) {
        return { type: 'each', expr: expr, body: bodyNodes || [], empty: emptyNodes || [] };
    }

    function parseTemplate(template) {
        var index = 0;
        var length = template.length;

        function parseNodes(section) {
            var nodes = [];
            while (index < length) {
                var start = template.indexOf('{{', index);
                if (start === -1) {
                    if (index < length) {
                        nodes.push(createTextNode(template.slice(index)));
                        index = length;
                    }
                    break;
                }

                if (start > index) {
                    nodes.push(createTextNode(template.slice(index, start)));
                }

                var end = template.indexOf('}}', start + 2);
                if (end === -1) {
                    nodes.push(createTextNode(template.slice(start)));
                    index = length;
                    break;
                }

                var tag = template.slice(start + 2, end).trim();
                index = end + 2;

                if (!tag) {
                    continue;
                }

                if (section && tag === '/' + section) {
                    return { nodes: nodes, terminator: tag };
                }

                if (section && tag === 'else') {
                    return { nodes: nodes, terminator: 'else' };
                }

                if (tag.indexOf('#if ') === 0) {
                    var ifExpr = tag.slice(4).trim();
                    var ifBlock = parseSection('if');
                    nodes.push(createIfNode(ifExpr, ifBlock.truthy, ifBlock.falsy));
                    continue;
                }

                if (tag.indexOf('#each ') === 0) {
                    var eachExpr = tag.slice(6).trim();
                    var eachBlock = parseSection('each');
                    nodes.push(createEachNode(eachExpr, eachBlock.truthy, eachBlock.falsy));
                    continue;
                }

                if (tag === '/if' || tag === '/each') {
                    // Unexpected closing tag; bubble up to caller.
                    return { nodes: nodes, terminator: tag };
                }

                nodes.push(createVariableNode(tag));
            }
            return { nodes: nodes, terminator: null };
        }

        function parseSection(type) {
            var firstPass = parseNodes(type);
            var truthyNodes = firstPass.nodes;
            var falsyNodes = [];
            var terminator = firstPass.terminator;

            if (terminator === 'else') {
                var secondPass = parseNodes(type);
                falsyNodes = secondPass.nodes;
                terminator = secondPass.terminator;
            }

            if (terminator !== '/' + type) {
                throw new Error('Unmatched Handlebars section: ' + type);
            }

            return { truthy: truthyNodes, falsy: falsyNodes };
        }

        var result = parseNodes(null);
        if (result.terminator) {
            throw new Error('Unexpected closing tag "' + result.terminator + '" in template.');
        }
        return result.nodes;
    }

    function resolvePath(context, root, expr) {
        if (!expr) {
            return context;
        }

        if (expr === 'this' || expr === '.') {
            return context;
        }

        var parts = expr.split('.');
        var value = context;
        if (!hasPath(value, parts)) {
            value = root;
        }

        return walkPath(value, parts);
    }

    function hasPath(object, parts) {
        if (object === null || object === undefined) {
            return false;
        }
        var current = object;
        for (var i = 0; i < parts.length; i++) {
            var key = parts[i];
            if (key === 'this' || key === '.') {
                continue;
            }
            if (current === null || current === undefined || !(key in Object(current))) {
                return false;
            }
            current = current[key];
        }
        return true;
    }

    function walkPath(object, parts) {
        var current = object;
        for (var i = 0; i < parts.length; i++) {
            var key = parts[i];
            if (key === 'this' || key === '.') {
                continue;
            }
            if (current === null || current === undefined) {
                return undefined;
            }
            current = current[key];
        }
        return current;
    }

    function renderNodes(nodes, context, root) {
        var output = '';
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            if (!node) {
                continue;
            }
            switch (node.type) {
                case 'text':
                    output += node.value;
                    break;
                case 'variable':
                    var value = resolvePath(context, root, node.expr);
                    if (typeof value === 'function') {
                        value = value.call(context);
                    }
                    output += escapeHtml(value === undefined || value === null ? '' : value);
                    break;
                case 'if':
                    var condition = resolvePath(context, root, node.expr);
                    if (truthy(condition)) {
                        output += renderNodes(node.truthy, context, root);
                    } else {
                        output += renderNodes(node.falsy, context, root);
                    }
                    break;
                case 'each':
                    var collection = resolvePath(context, root, node.expr);
                    var hasItems = false;
                    if (Array.isArray(collection)) {
                        for (var j = 0; j < collection.length; j++) {
                            hasItems = true;
                            output += renderNodes(node.body, collection[j], root);
                        }
                    } else if (collection && typeof collection === 'object') {
                        for (var key in collection) {
                            if (Object.prototype.hasOwnProperty.call(collection, key)) {
                                hasItems = true;
                                output += renderNodes(node.body, collection[key], root);
                            }
                        }
                    }
                    if (!hasItems) {
                        output += renderNodes(node.empty, context, root);
                    }
                    break;
                default:
                    break;
            }
        }
        return output;
    }

    function compile(template) {
        if (typeof template !== 'string') {
            return function () {
                return '';
            };
        }

        var ast;
        try {
            ast = parseTemplate(template);
        } catch (error) {
            console.error('Failed to compile template', error);
            return function () {
                return '';
            };
        }

        return function (context) {
            var data = context || {};
            return renderNodes(ast, data, data);
        };
    }

    global.Handlebars = {
        compile: compile,
        helpers: {},
        escapeExpression: escapeHtml,
    };
})(typeof window !== 'undefined' ? window : this);
