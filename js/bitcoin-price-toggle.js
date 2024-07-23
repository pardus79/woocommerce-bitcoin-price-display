jQuery(document).ready(function($) {
    var currentDisplay = bitcoinPriceData.initialDisplay;
    var displayMode = bitcoinPriceData.displayMode;
    var prefix = bitcoinPriceData.prefix;
    var suffix = bitcoinPriceData.suffix;
    var faIcon = bitcoinPriceData.faIcon;
    var faIconColor = bitcoinPriceData.faIconColor;
    var faIconAnimation = bitcoinPriceData.faIconAnimation;

	function formatBitcoinPrice(priceElement) {
		var $element = $(priceElement);
		var price = $element.data('sats');
		var suffix = $element.data('suffix');

		if (typeof price === 'undefined' || price === '') {
			return; // Skip if no price data
		}

		var iconStyle = bitcoinPriceData.faIconColor ? ' style="color: ' + bitcoinPriceData.faIconColor + ';"' : '';
		var iconClass = bitcoinPriceData.faIcon + (bitcoinPriceData.faIconAnimation ? ' ' + bitcoinPriceData.faIconAnimation : '');
		var iconHtml = bitcoinPriceData.faIcon ? '<i class="' + iconClass + '"' + iconStyle + '></i> ' : '';
		
		var formattedPrice = iconHtml + bitcoinPriceData.prefix + ' ' + price + ' ' + suffix;
		$element.html(formattedPrice);
	}	
	
	function updatePriceDisplay() {
		if (displayMode === 'bitcoin_only' || (displayMode === 'toggle' && currentDisplay === 'bitcoin')) {
			$('.price-wrapper .original-price').hide();
			$('.price-wrapper .bitcoin-price').show();
			$('.wc-bitcoin-price').each(function() {
				formatBitcoinPrice(this);
			});
		} else if (displayMode === 'both_prices') {
			$('.price-wrapper .original-price, .price-wrapper .bitcoin-price').show();
			$('.wc-bitcoin-price').each(function() {
				formatBitcoinPrice(this);
			});
			// Apply both prices option styles
			switch (bitcoinPriceData.bothPricesOption) {
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
	
	// Check if we're on a WooCommerce page before accessing WC objects
    if (typeof wc_checkout_params !== 'undefined') {
        // Handle mini cart and other dynamic content updates
        $(document.body).on('wc_fragments_loaded wc_fragments_refreshed updated_wc_div added_to_cart removed_from_cart updated_cart_totals updated_checkout', function() {
            setTimeout(updatePriceDisplay, 100);
        });

        // Update prices when switching variations
        $(document).on('found_variation', function(event, variation) {
            setTimeout(updatePriceDisplay, 100);
        });

        // Recalculate prices on quantity change
        $(document).on('change', '.quantity .qty', function() {
            $(document.body).trigger('update_checkout');
        });
    }
});