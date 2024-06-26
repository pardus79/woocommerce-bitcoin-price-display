jQuery(document).ready(function($) {
    $('#upload_icon_button').click(function(e) {
        e.preventDefault();
        var image = wp.media({ 
            title: 'Upload Bitcoin Icon',
            multiple: false,
            library: {
                type: bitcoinPriceDisplayAdmin.allowedTypes
            }
        }).open()
        .on('select', function(e){
            var uploaded_image = image.state().get('selection').first();
            var image_url = uploaded_image.toJSON().url;
            $('#sats_icon').val(image_url);
            updatePreview();
        });
    });
	
    // Live preview functionality
    function updatePreview() {
        var prefix = $('#sats_prefix').val();
        var suffix = $('#sats_suffix').val();
        var icon = $('#sats_icon').val();
        var sampleAmount = '100,000';

        var previewHtml = '';
        if (icon) {
            previewHtml += '<img src="' + icon + '" alt="Bitcoin Icon" style="height: 1em; vertical-align: middle; margin-right: 0.2em;" />';
        }
        previewHtml += prefix + ' ' + sampleAmount + ' ' + suffix;

        $('#sats_preview').html(previewHtml);
    }

    $('#sats_prefix, #sats_suffix, #sats_icon').on('input', updatePreview);

    // Initial preview
    updatePreview();
});