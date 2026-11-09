(function () {
    var module_routes = [
    {
        "uri": "mcp-tokens\/ajax",
        "name": "mcpforfreescout.tokens.ajax"
    }
];

    if (typeof(laroute) != "undefined") {
        laroute.add_routes(module_routes);
    } else {
        console.log('laroute not initialized, can not add module routes:');
        console.log(module_routes);
    }
})();