(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var buttons = document.querySelectorAll('[data-webpexpress-avif-test]');
        var output = document.getElementById('webpexpress-avif-live-test-output');
        var settings = window.WebPExpressAvifLiveTests;

        if (!output || !settings) {
            return;
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var test = button.getAttribute('data-webpexpress-avif-test');
                var body = new FormData();
                body.append('action', 'webpexpress_avif_live_test');
                body.append('nonce', settings.nonce);
                body.append('test', test);
                buttons.forEach(function (item) {
                    item.disabled = true;
                });
                output.textContent = 'Running AVIF live test...';

                fetch(settings.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: body
                }).then(function (response) {
                    return response.json().then(function (result) {
                        if (!response.ok || !result.success) {
                            var message = result.data && result.data.message
                                ? result.data.message
                                : 'The AVIF live test failed.';
                            var log = result.data && Array.isArray(result.data.log)
                                ? result.data.log.join('\n') + '\n'
                                : '';
                            throw new Error(log + 'FAIL: ' + message);
                        }
                        output.textContent = result.data.log.join('\n');
                    });
                }).catch(function (error) {
                    output.textContent = error.message;
                }).then(function () {
                    buttons.forEach(function (item) {
                        item.disabled = false;
                    });
                });
            });
        });
    });
}());
