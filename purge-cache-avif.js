window.purgeCache = function(onlyPng) {
    var data = {
        action: 'webpexpress_purge_cache',
        nonce: window.webpExpress['ajax-nonces']['purge-cache'],
        'only-png': onlyPng
    };

    jQuery.post(ajaxurl, data, function(response) {
        if ((typeof response == 'object') && (response.success == false)) {
            alert((response.data && (typeof response.data == 'string')) ? response.data : 'Something failed');
            return;
        }

        var result = (typeof response == 'string') ? JSON.parse(response) : response;
        var webpDeleted = parseInt(result['delete-count'], 10) || 0;
        var webpFailed = parseInt(result['fail-count'], 10) || 0;
        var avifDeleted = parseInt(result['avif-delete-count'], 10) || 0;
        var avifFailed = parseInt(result['avif-fail-count'], 10) || 0;
        var deleted = [];
        var failed = [];

        if (webpDeleted > 0) {
            deleted.push(webpDeleted + ' WebP file' + (webpDeleted == 1 ? '' : 's'));
        }
        if (avifDeleted > 0) {
            deleted.push(avifDeleted + ' AVIF file' + (avifDeleted == 1 ? '' : 's'));
        }
        if (webpFailed > 0) {
            failed.push(webpFailed + ' WebP file' + (webpFailed == 1 ? '' : 's'));
        }
        if (avifFailed > 0) {
            failed.push(avifFailed + ' AVIF file' + (avifFailed == 1 ? '' : 's'));
        }

        if (failed.length == 0) {
            alert(deleted.length == 0
                ? 'No converted WebP or AVIF files were found, so none was deleted.'
                : 'Successfully deleted ' + deleted.join(' and ') + '.');
        } else if (deleted.length == 0) {
            alert('Failed deleting ' + failed.join(' and ') + '. None was deleted.');
        } else {
            alert('Deleted ' + deleted.join(' and ') + '. However, failed deleting ' + failed.join(' and ') + '.');
        }
    });
};
