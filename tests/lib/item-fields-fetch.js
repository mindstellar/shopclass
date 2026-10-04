/*
 * Stands in for fetch() in tests/item-form-custom-fields.php. Category 1 answers late and
 * ignores the abort signal, 2 answers fast with window.replyTwo, 3 answers HTTP 500.
 */
window.requests = 0;
window.fetch = function (url, opts) {
    window.requests++;
    var cat = new URLSearchParams(opts.body).get('catId');
    var reply = {
        '1': { delay: 300, status: 200, body: '<p>one</p>' },
        '2': { delay: 10, status: 200, body: window.replyTwo },
        '3': { delay: 10, status: 500, body: '<h1>Fatal error</h1>' }
    }[cat];

    return new Promise(function (resolve) {
        setTimeout(function () {
            resolve(new Response(reply.body, { status: reply.status }));
        }, reply.delay);
    });
};
