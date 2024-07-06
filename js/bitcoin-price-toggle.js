jQuery(document).ready(function($) {
    var currentDisplay = bitcoinPriceData.initialDisplay;
    var displayMode = bitcoinPriceData.displayMode;
    var prefix = bitcoinPriceData.prefix;
    var suffix = bitcoinPriceData.suffix;
    var faIcon = bitcoinPriceData.faIcon;
    var bothPricesOption = bitcoinPriceData.bothPricesOption;
    var rangeOption = bitcoinPriceData.rangeOption;
    var roundingThousand = bitcoinPriceData.roundingThousand;
    var thousandSuffix = bitcoinPriceData.thousandSuffix;

    function formatBitcoinPrice(price) {
        // Remove any existing formatting
        price = price.replace(/[^\d-]/g, '');
        
        if (price.includes('-')) {
            // Handle price range
            var prices = price.split('-');
            var formattedPrices = prices.map(function(p) {
                return formatSinglePrice(p.trim());
            });
            
            if (rangeOption === 'both') {
                return formattedPrices.join(' - ');
            } else {
                return formattedPrices[0] + '+';
            }
        } else {
            // Handle single price
            return formatSinglePrice(price);
        }
    }

	function formatSinglePrice(price) {
		console.log("Formatting price:", price);
		price = parseInt(price, 10);
		console.log("Parsed price:", price);
		
		let formattedPrice;
		if (roundingThousand && price >= 1000) {
			price = Math.round(price / 1000) * 1000;
			formattedPrice = (price / 1000).toFixed(3);
			formattedPrice = formattedPrice.replace(/\.?0+$/, ''); // Remove trailing zeros and decimal point if necessary
			formattedPrice += thousandSuffix; // Add the thousand suffix
		} else {
			formattedPrice = price.toLocaleString();
		}
		
		console.log("Formatted price:", formattedPrice);
		var iconHtml = faIcon ? '<i class="fa ' + faIcon + '"></i> ' : '';
		return iconHtml + prefix + ' ' + formattedPrice + ' ' + suffix;
	}
	
	function updatePriceDisplay() {
		if (displayMode === 'bitcoin_only' || (displayMode === 'toggle' && currentDisplay === 'bitcoin')) {
			$('.price-wrapper .original-price').hide();
			$('.price-wrapper .bitcoin-price').show();
			$('.wc-bitcoin-price').each(function() {
				var $this = $(this);
				var sats = $this.data('sats');
				if (sats) {
					$this.html(formatSinglePrice(sats));
				}
			});
		} else if (displayMode === 'both_prices') {
			$('.price-wrapper .original-price, .price-wrapper .bitcoin-price').show();
			$('.wc-bitcoin-price').each(function() {
				var $this = $(this);
				var sats = $this.data('sats');
				if (sats) {
					$this.html(formatSinglePrice(sats));
				}
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

    // Function to update prices via AJAX
    function updateBitcoinPrices() {
        $.ajax({
            url: bitcoinPriceData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'update_bitcoin_prices',
                nonce: bitcoinPriceData.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update fragments
                    $.each(response.data, function(selector, content) {
                        $(selector).replaceWith(content);
                    });
                    updatePriceDisplay();
                }
            }
        });
    }

    // Update prices periodically (e.g., every 5 minutes)
    setInterval(updateBitcoinPrices, 300000); // 300000 ms = 5 minutes

    // Update prices when page becomes visible again
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            updateBitcoinPrices();
        }
    });
});