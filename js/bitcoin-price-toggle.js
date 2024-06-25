jQuery(document).ready(function($) {
    var currentDisplay = bitcoinPriceData.initialDisplay;
    var bitcoinOnly = bitcoinPriceData.bitcoinOnly;

    function updatePriceDisplay(display) {
        if (bitcoinOnly || display === 'bitcoin') {
            $('.price-wrapper .usd-price').hide();
            $('.price-wrapper .bitcoin-price').show();
        } else {
            $('.price-wrapper .usd-price').show();
            $('.price-wrapper .bitcoin-price').hide();
        }
        
        if (!bitcoinOnly) {
            $('#toggle-price-display').text(display === 'usd' ? 'Show Bitcoin' : 'Show EUR');
        }
        
        currentDisplay = display;
        localStorage.setItem('bitcoin_price_display', display);
    }

    function applyPriceDisplay() {
        updatePriceDisplay(currentDisplay);
    }

    // Set initial state and apply to all elements
    applyPriceDisplay();

    // Handle toggle button click (only if not Bitcoin-only)
    if (!bitcoinOnly) {
        $(document).on('click', '#toggle-price-display', function(e) {
            e.preventDefault();
            var newDisplay = currentDisplay === 'bitcoin' ? 'eur' : 'bitcoin';
            updatePriceDisplay(newDisplay);
            $(document.body).trigger('update_checkout');
        });
    }

    // Apply price display after specific events
    $(document.body).on('updated_cart_totals updated_checkout added_to_cart removed_from_cart wc_fragments_loaded wc_fragments_refreshed', function() {
        setTimeout(applyPriceDisplay, 100); // Short delay to ensure DOM is updated
    });
});
