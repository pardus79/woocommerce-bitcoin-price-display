jQuery(document).ready(function($) {
    var currentDisplay = bitcoinPriceData.initialDisplay;
    var displayMode = bitcoinPriceData.displayMode;

    function updatePriceDisplay(display) {
        if (displayMode === 'toggle') {
            if (display === 'bitcoin') {
                $('.price-wrapper .original-price').hide();
                $('.price-wrapper .bitcoin-price').show();
            } else {
                $('.price-wrapper .original-price').show();
                $('.price-wrapper .bitcoin-price').hide();
            }
            
            $('#toggle-price-display').text(display === 'bitcoin' ? 'Show Original' : 'Show Bitcoin');
        } else if (displayMode === 'bitcoin_only') {
            $('.price-wrapper .original-price').hide();
            $('.price-wrapper .bitcoin-price').show();
        } else if (displayMode === 'side_by_side') {
            $('.price-wrapper .original-price, .price-wrapper .bitcoin-price').show();
        }
        
        currentDisplay = display;
        localStorage.setItem('bitcoin_price_display', display);
    }

    function applyPriceDisplay() {
        updatePriceDisplay(currentDisplay);
    }

    // Set initial state and apply to all elements
    applyPriceDisplay();

    // Handle toggle button click (only if display mode is 'toggle')
    if (displayMode === 'toggle') {
        $(document).on('click', '#toggle-price-display', function(e) {
            e.preventDefault();
            var newDisplay = currentDisplay === 'bitcoin' ? 'original' : 'bitcoin';
            updatePriceDisplay(newDisplay);
            $(document.body).trigger('update_checkout');

            // AJAX call to update server-side session
            $.ajax({
                url: bitcoinPriceData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'toggle_price_display',
                    nonce: bitcoinPriceData.nonce,
                    display: newDisplay
                },
                success: function(response) {
                    if (response.success) {
                        $('#toggle-price-display').text(response.data.button_text);
                    }
                }
            });
        });
    }

    // Apply price display after specific events
    $(document.body).on('updated_cart_totals updated_checkout added_to_cart removed_from_cart wc_fragments_loaded wc_fragments_refreshed', function() {
        setTimeout(applyPriceDisplay, 100); // Short delay to ensure DOM is updated
    });
});
