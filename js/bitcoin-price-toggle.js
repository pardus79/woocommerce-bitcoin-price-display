jQuery(document).ready(function($) {
    var currentDisplay = bitcoinPriceData.initialDisplay;
    var displayMode = bitcoinPriceData.displayMode;
    var prefix = bitcoinPriceData.prefix;
    var suffix = bitcoinPriceData.suffix;
    var faIcon = bitcoinPriceData.faIcon;
    var bothPricesOption = bitcoinPriceData.bothPricesOption;

    function formatBitcoinPrice(price) {
        // Remove any existing formatting
        price = price.replace(/[^\d]/g, '');
        // Apply new formatting
        var iconHtml = faIcon ? '<i class="fa ' + faIcon + '"></i> ' : '';
        return iconHtml + prefix + ' ' + Number(price).toLocaleString() + ' ' + suffix;
    }

    function updatePriceDisplay() {
        if (displayMode === 'bitcoin_only' || (displayMode === 'toggle' && currentDisplay === 'bitcoin')) {
            $('.price-wrapper .original-price').hide();
            $('.price-wrapper .bitcoin-price').show();
            $('.wc-bitcoin-price').each(function() {
                var $this = $(this);
                var price = $this.text();
                $this.html(formatBitcoinPrice(price));
            });
        } else if (displayMode === 'both_prices') {
            $('.price-wrapper .original-price, .price-wrapper .bitcoin-price').show();
            $('.wc-bitcoin-price').each(function() {
                var $this = $(this);
                var price = $this.text();
                $this.html(formatBitcoinPrice(price));
            });
            // Apply both prices option styles
            switch (bothPricesOption) {
                case 'before_inline':
                case 'after_inline':
                    $('.price-wrapper').css('display', 'inline');
                    break;
                case 'below':
                case 'above':
                    $('.price-wrapper').css('display', 'block');
                    break;
            }
        } else {
            $('.price-wrapper .original-price').show();
            $('.price-wrapper .bitcoin-price').hide();
        }
    }
	
    // Initial update
    updatePriceDisplay();
	
    // Handle toggle button click (only if display mode is 'toggle')
    if (displayMode === 'toggle') {
        $(document).on('click', '#toggle-price-display', function(e) {
            e.preventDefault();
            currentDisplay = currentDisplay === 'bitcoin' ? 'original' : 'bitcoin';
            updatePriceDisplay();
            $(document.body).trigger('update_checkout');

            // AJAX call to update server-side session
            $.ajax({
                url: bitcoinPriceData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'toggle_price_display',
                    nonce: bitcoinPriceData.nonce,
                    display: currentDisplay
                },
                success: function(response) {
                    if (response.success) {
                        $('#toggle-price-display').text(response.data.button_text);
                    }
                }
            });
        });
    }

    // Handle mini cart and other dynamic content updates
    $(document.body).on('wc_fragments_loaded wc_fragments_refreshed updated_wc_div added_to_cart removed_from_cart updated_cart_totals updated_checkout', function() {
        setTimeout(updatePriceDisplay, 100); // Short delay to ensure DOM is updated
    });

    // Update prices when switching variations
    $(document).on('found_variation', function(event, variation) {
        setTimeout(updatePriceDisplay, 100);
    });

    // Recalculate prices on quantity change
    $(document).on('change', '.quantity .qty', function() {
        $(document.body).trigger('update_checkout');
    });
});