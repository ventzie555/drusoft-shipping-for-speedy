/* global speedy_params */
// noinspection CssInvalidHtmlTagReference

/**
 * @global speedy_params
 * @type {object}
 * @property {string} ajax_url
 * @property {string} method_id
 * @property {Object} i18n
 * @property {string} i18n.to_address
 * @property {string} i18n.to_office
 * @property {string} i18n.to_automat
 * @property {string} i18n.select_office
 * @property {string} i18n.select_automat
 * @property {string} i18n.select_from_map
 * @property {string} i18n.select_city
 * @property {string} i18n.alert_select_city
 */

/**
 * @typedef {Object} SpeedyOfficeData
 * @property {string|number} id
 * @property {string|number} siteId
 * @property {string} name
 * @property {string} type
 * @property {string} [label]
 * @property {string|Object} [address]
 * @property {string} [address.fullAddressString]
 * @property {string} [address.line1]
 */

/**
 * @typedef {Object} SpeedyAvailabilityData
 * @property {boolean} has_office
 * @property {boolean} has_automat
 * @property {Array} offices
 * @property {Array} automats
 */

(function($, params) {
    'use strict';

    $(document).ready(function() {
        const speedyMethodId = params.method_id; // 'speedy_modern'
        let isSpeedyActive = false;
        
        // State persistence across AJAX updates.
        // Initialize from server params (session data set by cart page).
        let lastDeliveryType = params.current_type || 'address';
        let lastOfficeId = params.current_office_id ? String(params.current_office_id) : '';

        // Cache to avoid redundant AJAX calls.
        let cachedState = '';
        let cachedCities = null;
        let cachedCityId = '';
        let cachedAvailability = null;

        // True while we are inside setupSpeedyUI — prevents update_checkout
        // that our own code triggers from causing a re-entrant loop.
        let settingUp = false;

        // Modal references (assigned when modal HTML is injected)
        let $speedyMapModal = null;
        let $speedyMapFrame = null;

        // Current address context ('billing' or 'shipping')
        let currentContext = 'billing';

        // Store original HTML for both contexts to restore later
        const originals = {
            billing: {},
            shipping: {}
        };

        // Helper to capture originals
        function captureOriginals(context) {
            if ($('#' + context + '_city_field').length) {
                originals[context].cityHtml = $('#' + context + '_city_field').html();
                originals[context].address1Html = $('#' + context + '_address_1_field').html();
                originals[context].address2Html = $('#' + context + '_address_2_field').html();
                originals[context].stateHtml = $('#' + context + '_state_field').html();
                
                // Capture priorities
                originals[context].priorities = {
                    state: $('#' + context + '_state_field').attr('data-priority'),
                    city: $('#' + context + '_city_field').attr('data-priority'),
                    address1: $('#' + context + '_address_1_field').attr('data-priority'),
                    address2: $('#' + context + '_address_2_field').attr('data-priority')
                };
            }
        }

        // Initial capture
        captureOriginals('billing');
        captureOriginals('shipping');

        // Listen for shipping method changes — use mousedown in CAPTURE phase
        // so our DOM cleanup runs before any other plugin's event handlers.
        document.addEventListener('mousedown', function(e) {
            const radio = e.target.closest('input[name^="shipping_method"]');
            if (!radio) return;

            // The radio hasn't changed value yet on mousedown, but we can
            // check whether the clicked radio is NOT Speedy.
            const isSpeedy = radio.value && radio.value.indexOf(speedyMethodId) !== -1;
            if (!isSpeedy && isSpeedyActive) {
                deactivateSpeedy();
            }
        }, true);

        // Capture state BEFORE WC destroys the DOM
        $(document.body).on('update_checkout', function() {
            if (isSpeedyActive && !settingUp) {
                const type = $('input[name="speedy_delivery_type"]:checked').val();
                if (type) lastDeliveryType = type;
                
                // Only capture office if the user explicitly selected one
                // (the change handler sets lastOfficeId directly, so we just
                // preserve whatever value it already has — don't read from DOM
                // because select2 may report a stale or auto-selected value).

                // Save current city id from the dropdown
                const cityVal = $('#' + currentContext + '_city').val();
                if (cityVal) cachedCityId = cityVal;
            }
            // Unbind state handler to prevent WC DOM teardown from triggering it
            $('body').off('change.speedy');
        });

        // ===== SINGLE ENTRY POINT: updated_checkout =====
        // WooCommerce fires this after every checkout AJAX refresh,
        // including the initial one on page load. This is where we
        // set up or restore the Speedy UI — never from document.ready.
        $(document.body).on('updated_checkout', function() {
            if (settingUp) return; // guard against re-entrance

            updateContext();

            const selectedMethod = $('input[name^="shipping_method"]:checked').val();
            const speedySelected = selectedMethod && selectedMethod.indexOf(speedyMethodId) !== -1;

            if (!speedySelected) {
                if (!isSpeedyActive) {
                    captureOriginals('billing');
                    captureOriginals('shipping');
                }
                if (isSpeedyActive) {
                    deactivateSpeedy();
                }
                return;
            }

            // Speedy is selected — set up / restore UI.
            // If our select2 city is still in the DOM, WC didn't rebuild — nothing to do.
            if ($('#' + currentContext + '_city').hasClass('select2-hidden-accessible')) {
                refreshServiceSelector();
                return;
            }

            // WC rebuilt the DOM. Capture clean originals, then set up Speedy.
            captureOriginals('billing');
            captureOriginals('shipping');

            setupSpeedyUI();
        });

        // Listen for Country Change (WC re-sorts fields on this event)
        $(document.body).on('country_to_state_changed', function() {
            if (isSpeedyActive) {
                setTimeout(function() {
                    reorderFieldsForSpeedy();
                    initStateSelect2WithTransliteration();
                    makeRegionRequired();
                }, 100);
            }
        });

        // Listen for "Ship to different address" toggle
        $('form.checkout').on('change', '#ship-to-different-address-checkbox', function() {
            // If Speedy is active, we need to switch contexts
            if (isSpeedyActive) {
                // Deactivate on current context (restore fields)
                deactivateSpeedy();
                
                // Update context
                updateContext();
                
                // Re-activate on new context
                setupSpeedyUI();
            } else {
                updateContext();
            }
        });

        function updateContext() {
            if ($('#ship-to-different-address-checkbox').is(':checked')) {
                currentContext = 'shipping';
            } else {
                currentContext = 'billing';
            }
        }

        // Initial check on page load
        updateContext();

        // Inject Modal HTML if not present
        if ($('#speedy-map-modal').length === 0) {
            const modalHtml = 
                '<div id="speedy-map-modal" class="speedy-modal">' +
                    '<div class="speedy-modal-content">' +
                        '<div class="speedy-modal-header">' +
                            '<h3 class="speedy-modal-title">' + params.i18n.select_from_map + '</h3>' +
                            '<span class="speedy-close">&times;</span>' +
                        '</div>' +
                        '<div class="speedy-modal-body">' +
                            '<iframe id="speedy-map-frame" src="" allow="geolocation"></iframe>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            $('body').append(modalHtml);
            
            // Cache selectors after appending
            $speedyMapModal = $('#speedy-map-modal');
            $speedyMapFrame = $('#speedy-map-frame');

            // Close handlers
            $('.speedy-close').on('click', function() {
                $speedyMapModal.hide();
                $speedyMapFrame.attr('src', '');
            });

            $(window).on('click', function(event) {
                if (event.target.id === 'speedy-map-modal') {
                    $speedyMapModal.hide();
                    $speedyMapFrame.attr('src', '');
                }
            });
        } else {
            $speedyMapModal = $('#speedy-map-modal');
            $speedyMapFrame = $('#speedy-map-frame');
        }

        // Listen for Speedy Map selection (postMessage)
        window.addEventListener('message', function(e) {
            const data = e.data;
            
            if (data && data.id && (data.type === 'OFFICE' || data.type === 'APT')) {
                if (isSpeedyActive) {
                    updateOfficeFromMap(data);
                    if ($speedyMapModal) $speedyMapModal.hide(); 
                    if ($speedyMapFrame) $speedyMapFrame.attr('src', '');
                }
            }
        });

        /**
         * @param {SpeedyOfficeData} officeData
         */
        function updateOfficeFromMap(officeData) {
            const $cityInput = $('#' + currentContext + '_city');
            const currentCityId = $cityInput.val();
            const targetCityId = String(officeData.siteId); 

            if (currentCityId !== targetCityId) {
                $.ajax({
                    url: params.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'speedy_get_region_by_city',
                        nonce: params.nonce,
                        city_id: targetCityId
                    },
                    success: function(response) {
                        if (response.success) {
                            const regionCode = response.data.region;
                            const $stateInput = $('#' + currentContext + '_state');
                            const currentRegion = $stateInput.val();

                            if (currentRegion !== regionCode) {
                                $stateInput.val(regionCode).trigger('change');
                                
                                const request = handleStateChange(regionCode);
                                
                                if (request) {
                                    request.done(function() {
                                        setTimeout(function() {
                                            $('#' + currentContext + '_city').val(targetCityId).trigger('change');
                                            setTimeout(function() {
                                                setOfficeInDropdown(officeData);
                                            }, 1000);
                                        }, 100);
                                    });
                                }
                            } else {
                                $cityInput.val(targetCityId).trigger('change');
                                setTimeout(function() {
                                    setOfficeInDropdown(officeData);
                                }, 1000);
                            }
                        } else {
                            alert('Could not determine region for the selected city.');
                        }
                    }
                });
            } else {
                setOfficeInDropdown(officeData);
            }
        }

        /**
         * @param {SpeedyOfficeData} officeData
         */
        function setOfficeInDropdown(officeData) {
            const currentType = $('input[name="speedy_delivery_type"]:checked').val();
            const mapType = (officeData.type === 'APT') ? 'automat' : 'office';
            
            if (currentType !== mapType) {
                $('input[name="speedy_delivery_type"][value="' + mapType + '"]').prop('checked', true).trigger('change');
                setTimeout(function() {
                    setOfficeValue(officeData);
                }, 500);
            } else {
                setOfficeValue(officeData);
            }
        }

        /**
         * @param {SpeedyOfficeData} officeData
         */
        function setOfficeValue(officeData) {
            const $select = $('#speedy_office_id');
            const targetOfficeId = String(officeData.id);

            if ($select.length) {
                let exists = false;
                $select.find('option').each(function() {
                    if (String($(this).val()) === targetOfficeId) {
                        $select.val($(this).val()).trigger('change');
                        exists = true;
                        return false;
                    }
                });

                if (!exists) {
                    let addressStr = '';
                    if (officeData.address) {
                        if (typeof officeData.address === 'object') {
                            addressStr = officeData.address.fullAddressString || officeData.address.line1 || '';
                        } else {
                            addressStr = officeData.address;
                        }
                    }
                    const label = officeData.id + ' ' + officeData.name + (addressStr ? ' - ' + addressStr : '');
                    const newOption = new Option(label, officeData.id, true, true);
                    $select.append(newOption).trigger('change');
                }
            }
        }

        /**
         * Single setup/restore function for the Speedy UI.
         * Called only from updated_checkout — never from document.ready.
         * Uses caches when available to avoid redundant AJAX calls.
         */
        function setupSpeedyUI() {
            isSpeedyActive = true;
            settingUp = true;

            reorderFieldsForSpeedy();
            initStateSelect2WithTransliteration();
            makeRegionRequired();

            // Determine state: session > DOM
            const effectiveState = params.current_state || $('#' + currentContext + '_state').val();

            if (params.current_state) {
                const $stateEl = $('#' + currentContext + '_state');
                if ($stateEl.val() !== params.current_state) {
                    $stateEl.val(params.current_state).trigger('change.select2');
                }
            }

            // City to pre-select: session (numeric ID) > DOM text value
            const preSelectCity = cachedCityId || params.current_city_id || $('#' + currentContext + '_city').val();

            if (!effectiveState) {
                $('#' + currentContext + '_city_field').hide();
                settingUp = false;
                bindStateChangeHandler();
                return;
            }

            // Load cities (cached or AJAX) then continue the chain
            loadCities(effectiveState, function(cities) {
                if (!cities) { settingUp = false; bindStateChangeHandler(); return; }

                replaceCityInputWithSelect(cities, preSelectCity, true); // skip auto-trigger

                const $citySelect = $('#' + currentContext + '_city');
                const selectedCityId = $citySelect.val();

                if (!selectedCityId) {
                    // No city matched — user will have to pick one
                    settingUp = false;
                    bindStateChangeHandler();
                    return;
                }

                // Load availability (cached or AJAX) then continue
                loadAvailability(selectedCityId, function(availData) {
                    if (availData) {
                        presentDeliveryOptions(availData);
                    }

                    settingUp = false;
                    bindStateChangeHandler();

                    // Now trigger a single update_checkout to get the correct price
                    $(document.body).trigger('update_checkout');
                });
            });
        }

        /**
         * Load cities for a state — uses cache if available.
         * Calls callback(cities) when done.
         */
        function loadCities(stateCode, callback) {
            let deferred = $.Deferred();

            if (stateCode === cachedState && cachedCities) {
                callback(cachedCities);
                deferred.resolve();
                return deferred.promise();
            }

            $.ajax({
                url: params.ajax_url,
                type: 'POST',
                data: { action: 'speedy_get_cities', nonce: params.nonce, region: stateCode },
                success: function(response) {
                    if (response.success) {
                        cachedState = stateCode;
                        cachedCities = response.data;
                        callback(response.data);
                    } else {
                        callback(null);
                    }
                    deferred.resolve();
                },
                error: function() { callback(null); deferred.resolve(); }
            });

            return deferred.promise();
        }

        /**
         * Load availability for a city — uses cache if available.
         * Calls callback(data) when done.
         */
        function loadAvailability(cityId, callback) {
            if (String(cityId) === String(cachedCityId) && cachedAvailability) {
                callback(cachedAvailability);
                return;
            }

            $.ajax({
                url: params.ajax_url,
                type: 'POST',
                data: { action: 'speedy_check_availability', nonce: params.nonce, city_id: cityId },
                success: function(response) {
                    if (response.success) {
                        cachedCityId = cityId;
                        cachedAvailability = response.data;
                        callback(response.data);
                    } else {
                        callback(null);
                    }
                },
                error: function() { callback(null); }
            });
        }

        /**
         * Bind the state change handler (user changes state dropdown).
         */
        function bindStateChangeHandler() {
            $('body').off('change.speedy');
            $('body').on('change.speedy', '#' + currentContext + '_state', function() {
                const state = $(this).val();
                if (!isSpeedyActive) return;

                // State changed — remove stale delivery options
                $('#speedy-delivery-type-field').remove();
                $('#speedy-office-field').remove();
                $('#speedy-map-button-wrapper').remove();
                $('#speedy-service-field').remove();
                lastDeliveryType = 'address';
                lastOfficeId = '';

                // Invalidate caches
                cachedState = '';
                cachedCities = null;
                cachedCityId = '';
                cachedAvailability = null;

                // Clear city field — destroy select2 if present, then reset
                const $cityEl = $('#' + currentContext + '_city');
                if ($cityEl.is('select') && $cityEl.hasClass('select2-hidden-accessible')) {
                    $cityEl.val('').trigger('change.select2');
                    $cityEl.select2('destroy');
                }
                const $cityField = $('#' + currentContext + '_city_field');
                if (originals[currentContext] && originals[currentContext].cityHtml) {
                    $cityField.html(originals[currentContext].cityHtml);
                    $('#' + currentContext + '_city').val('');
                }

                // Clear postcode — it belongs to the previous city
                $('#' + currentContext + '_postcode').val('');

                if (state) {
                    $('#' + currentContext + '_city_field').show();
                    handleStateChange(state);
                } else {
                    $('#' + currentContext + '_city_field').hide();
                }
                $(document.body).trigger('update_checkout');
            });
        }



        function deactivateSpeedy() {
            isSpeedyActive = false;

            setWcAutocompleteProvider(false);

            $('body').off('change.speedy');
            restoreOriginalFields();
            restoreFieldOrder();
            
            lastDeliveryType = 'address';
            lastOfficeId = '';
            sessionStorage.removeItem('speedy_delivery_type');
            sessionStorage.removeItem('speedy_office_id');
        }

        function reorderFieldsForSpeedy() {
            const $stateField = $('#' + currentContext + '_state_field');
            const $countryField = $('#' + currentContext + '_country_field');
            const $cityField = $('#' + currentContext + '_city_field');

            $stateField.insertAfter($countryField);
            $stateField.attr('data-priority', 41);
            
            $cityField.insertAfter($stateField);
            $cityField.attr('data-priority', 42);
            
            $('#speedy-delivery-type-field').insertAfter($cityField);
        }

        function restoreFieldOrder() {
            const $stateField = $('#' + currentContext + '_state_field');
            const $countryField = $('#' + currentContext + '_country_field');
            const $cityField = $('#' + currentContext + '_city_field');
            const $address1Field = $('#' + currentContext + '_address_1_field');
            const $address2Field = $('#' + currentContext + '_address_2_field');
            
            const originalPrio = originals[currentContext].priorities;

            $address1Field.insertAfter($countryField);
            $address2Field.insertAfter($address1Field);
            $cityField.insertAfter($address2Field);
            if (originalPrio && originalPrio.city) $cityField.attr('data-priority', originalPrio.city);

            $stateField.insertAfter($cityField);
            if (originalPrio && originalPrio.state) $stateField.attr('data-priority', originalPrio.state);
        }

        /**
         * Re-init the state select2 with our transliteration-aware matcher.
         * WooCommerce initializes it without transliteration support.
         */
        function initStateSelect2WithTransliteration() {
            SpeedyModern.initStateSelect2(
                $('#' + currentContext + '_state'),
                params.current_state
            );
        }

        function makeRegionRequired() {
            const $field = $('#' + currentContext + '_state_field');
            if (!$field.hasClass('validate-required')) {
                $field.addClass('validate-required');
                $field.find('label .optional').hide();
                if ($field.find('label .required').length === 0) {
                    $field.find('label').append('&nbsp;<abbr class="required" title="required">*</abbr>');
                }
            } else {
                $field.find('label .optional').hide();
                if ($field.find('label .required').length === 0) {
                    $field.find('label').append('&nbsp;<abbr class="required" title="required">*</abbr>');
                }
            }
        }

        function restoreOriginalFields() {
            const $cityInput = $('#' + currentContext + '_city');
            const $cityField = $('#' + currentContext + '_city_field');
            
            if ($cityInput.is('select')) {
                if ($cityInput.data('select2')) {
                    $cityInput.select2('destroy');
                }
                $cityField.html(originals[currentContext].cityHtml);
                $('#' + currentContext + '_city').prop('disabled', false).val('');
            }

            const $stateField = $('#' + currentContext + '_state_field');
            $stateField.find('label .optional').show();
            $stateField.find('label .required').remove();
            $stateField.removeClass('validate-required');

            const $address1Field = $('#' + currentContext + '_address_1_field');
            const $address2Field = $('#' + currentContext + '_address_2_field');

            $address1Field.html(originals[currentContext].address1Html).show();
            $address2Field.html(originals[currentContext].address2Html).show();
            
            const addr1 = $address1Field.find('#' + currentContext + '_address_1').val();
            if (addr1 === params.i18n.to_office || addr1 === params.i18n.to_automat) {
                $address1Field.find('#' + currentContext + '_address_1').val('');
                $address2Field.find('#' + currentContext + '_address_2').val('');
            }
            
            $('#' + currentContext + '_postcode').val('');
            
            $('#speedy-delivery-type-field').remove();
            $('#speedy-office-field').remove();
            $('#speedy-map-button-wrapper').remove();
            $('#speedy-service-field').remove();

            $cityField.show();

            // Re-apply WC's native selectWoo on the state field so the searchable
            // dropdown is restored after we destroyed our custom Select2.
            var $stateEl = $('#' + currentContext + '_state');
            if ($stateEl.is('select') && $.fn.selectWoo) {
                if ($stateEl.hasClass('select2-hidden-accessible')) {
                    $stateEl.select2('destroy');
                }
                $stateEl.selectWoo({ width: '100%' });
            }
        }

        function handleStateChange(stateCode, preSelectedCity) {
            if (!isSpeedyActive || !stateCode) {
                return;
            }

            // Use loadCities which handles caching
            return loadCities(stateCode, function(cities) {
                if (cities) {
                    replaceCityInputWithSelect(cities, preSelectedCity);
                }
            });
        }

        function replaceCityInputWithSelect(cities, preSelectedCity, skipAutoTrigger) {
            const $cityField = $('#' + currentContext + '_city_field');
            const $cityWrapper = $cityField.find('.woocommerce-input-wrapper');
            const currentCity = preSelectedCity || $('#' + currentContext + '_city').val() || params.current_city_id;

            let options = '<option value="">' + params.i18n.select_city + '</option>';
            $.each(cities, function(index, city) {
                let selected = '';
                if (currentCity) {
                    if (String(city.id) === String(currentCity)) {
                        selected = 'selected';
                    } else if (city.name.toUpperCase() === String(currentCity).toUpperCase()) {
                        selected = 'selected';
                    }
                }
                
                options += '<option value="' + city.id + '" data-postcode="' + (city.postcode || '') + '" ' + selected + '>' + city.name + ' ' + (city.postcode ? '(' + city.postcode + ')' : '') + '</option>';
            });

            const selectHtml = '<select name="' + currentContext + '_city" id="' + currentContext + '_city" class="select2-hidden-accessible" data-placeholder="' + params.i18n.select_city + '">' + options + '</select>';
            
            $cityWrapper.html(selectHtml);

            const $newCitySelect = $('#' + currentContext + '_city');
            $newCitySelect.select2({
                width: '100%',
                matcher: modelMatcher
            });

            $newCitySelect.on('change', function() {
                handleCityChange($(this).val());
            });
            
            // When called from setupSpeedyUI, skip auto-trigger — the caller controls the chain.
            if (!skipAutoTrigger) {
                if ($newCitySelect.val()) {
                     handleCityChange($newCitySelect.val());
                } else {
                     settingUp = false;
                }
            }

            // Set postcode for pre-selected city
            if ($newCitySelect.val()) {
                const $sel = $newCitySelect.find(':selected');
                const pc = $sel.data('postcode');
                if (pc) {
                    $('#' + currentContext + '_postcode').val(pc).trigger('change');
                }
            }
        }

        function handleCityChange(cityId) {
            if (!cityId) return;

            const $selectedOption = $('#' + currentContext + '_city').find(':selected');
            const postcode = $selectedOption.data('postcode');
            if (postcode) {
                $('#' + currentContext + '_postcode').val(postcode).trigger('change');
            }

            loadAvailability(cityId, function(data) {
                if (data) {
                    presentDeliveryOptions(data);
                }
                $(document.body).trigger('update_checkout');
                settingUp = false;
            });
        }

        function presentDeliveryOptions(data) {
            $('#speedy-delivery-type-field').remove();
            $('#speedy-office-field').remove();
            $('#speedy-map-button-wrapper').remove();
            $('#speedy-service-field').remove();

            const $address1Field = $('#' + currentContext + '_address_1_field');
            const $address2Field = $('#' + currentContext + '_address_2_field');

            if (!data.has_office && !data.has_automat) {
                $address1Field.show();
                $address2Field.show();
                $address1Field.find('input').val('');
                $address2Field.find('input').val('');
                return;
            }

            let radios = '<span class="woocommerce-input-wrapper" id="speedy-delivery-type-wrapper">';
            
            radios += '<input type="radio" name="speedy_delivery_type" id="speedy_delivery_type_address" value="address" checked="checked" style="margin-left: 0;">' +
                      '<label for="speedy_delivery_type_address" style="display: inline-block; margin-right: 15px; margin-left: 5px;">' + params.i18n.to_address + '</label>';

            if (data.has_office) {
                radios += '<input type="radio" name="speedy_delivery_type" id="speedy_delivery_type_office" value="office">' +
                          '<label for="speedy_delivery_type_office" style="display: inline-block; margin-right: 15px; margin-left: 5px;">' + params.i18n.to_office + '</label>';
                $('#' + currentContext + '_city_field').data('offices', data.offices || []);
            }
            if (data.has_automat) {
                radios += '<input type="radio" name="speedy_delivery_type" id="speedy_delivery_type_automat" value="automat">' +
                          '<label for="speedy_delivery_type_automat" style="display: inline-block; margin-left: 5px;">' + params.i18n.to_automat + '</label>';
                $('#' + currentContext + '_city_field').data('automats', data.automats || []);
            }
            radios += '</span>';

            const radioHtml = '<p class="form-row form-row-wide" id="speedy-delivery-type-field">' +
                '<label>Delivery Method</label>' + radios + '</p>';

            $('#' + currentContext + '_city_field').after(radioHtml);

            $('input[name="speedy_delivery_type"]').on('change', function() {
                handleDeliveryTypeChange($(this).val());
                // Delivery type changed → recalculate shipping
                $(document.body).trigger('update_checkout');
            });
            
            // Trigger initial state
            if (lastDeliveryType !== 'address') {
                $('input[name="speedy_delivery_type"][value="' + lastDeliveryType + '"]').prop('checked', true);
            }
            handleDeliveryTypeChange(lastDeliveryType);
        }

        function handleDeliveryTypeChange(type) {
            $('#speedy-office-field').remove();
            $('#speedy-map-button-wrapper').remove();
            $('#speedy-service-field').remove();

            sessionStorage.setItem('speedy_delivery_type', type);
            lastDeliveryType = type;
            
            const $address1Field = $('#' + currentContext + '_address_1_field');
            const $address2Field = $('#' + currentContext + '_address_2_field');

            if (type === 'address') {
                $address1Field.show();
                $address2Field.show();
                $address1Field.find('input').val('');
                $address2Field.find('input').val('');
                // Register so WC skips keydown/change/blur updates on address_1
                setWcAutocompleteProvider(true);
            } else {
                $address1Field.hide();
                $address2Field.hide();
                // Unregister — address fields are hidden, no autocomplete needed
                setWcAutocompleteProvider(false);

                if (type === 'office') {
                    $address1Field.find('input').val(params.i18n.to_office);
                } else if (type === 'automat') {
                    $address1Field.find('input').val(params.i18n.to_automat);
                }

                let points = [];
                if (type === 'office') {
                    points = $('#' + currentContext + '_city_field').data('offices');
                } else if (type === 'automat') {
                    points = $('#' + currentContext + '_city_field').data('automats');
                }

                showPointsDropdown(points, type);
            }
        }

        function showPointsDropdown(points, type) {
            const label = (type === 'office') ? params.i18n.select_office : params.i18n.select_automat;
            let options = '<option value="" selected></option>';

            $.each(points, function(index, point) {
                options += '<option value="' + point.id + '">' + point.label + '</option>';
            });

            const selectHtml = '<p class="form-row form-row-wide" id="speedy-office-field">' +
                '<label for="speedy_office_id">' + label + '&nbsp;<abbr class="required" title="required">*</abbr></label>' +
                '<span class="woocommerce-input-wrapper">' +
                '<select name="speedy_office_id" id="speedy_office_id">' + options + '</select>' +
                '</span></p>';

            $('#speedy-delivery-type-field').after(selectHtml);
            
            const $officeSelect = $('#speedy_office_id');
            $officeSelect.select2({
                width: '100%',
                placeholder: label + '...',
                allowClear: true,
                matcher: modelMatcher
            });

            // Pre-select saved office (if any) BEFORE binding the change handler.
            // Otherwisek, ensure the placeholder is shown (no office selected).
            if (lastOfficeId) {
                $officeSelect.val(lastOfficeId).trigger('change.select2');
            } else {
                $officeSelect.val('').trigger('change.select2');
            }

            // Now bind the change handler for user-initiated changes
            $officeSelect.on('change', function() {
                const officeVal = $(this).val();
                const selectedText = $(this).find('option:selected').text();
                const deliveryType = $('input[name="speedy_delivery_type"]:checked').val();
                
                const $address1Field = $('#' + currentContext + '_address_1_field');
                const $address2Field = $('#' + currentContext + '_address_2_field');

                if (deliveryType === 'office') {
                    $address1Field.find('input').val(params.i18n.to_office);
                } else if (deliveryType === 'automat') {
                    $address1Field.find('input').val(params.i18n.to_automat);
                }
                
                $address2Field.find('input').val(officeVal ? selectedText : '');

                lastOfficeId = officeVal || '';
                sessionStorage.setItem('speedy_office_id', lastOfficeId);

                // Office/automat selected → recalculate shipping
                $(document.body).trigger('update_checkout');
            });

            const mapBtnHtml = '<p class="form-row form-row-wide" id="speedy-map-button-wrapper" style="margin-top: 10px;">' +
                '<button type="button" id="speedy-open-map" class="button" style="width: 100%;">' + params.i18n.select_from_map + '</button>' +
                '</p>';

            $('#speedy-office-field').after(mapBtnHtml);

            $('#speedy-open-map').on('click', function() {
                openSpeedyMap();
            });
        }

        /**
         * Fetch available services from the session and show a selector
         * if there are multiple options. When the user picks a different
         * service we call a lightweight AJAX endpoint that updates the
         * session and returns the new cost — no full update_checkout needed.
         */
        function refreshServiceSelector() {
            $.ajax({
                url: params.ajax_url,
                method: 'POST',
                data: { action: 'speedy_modern_get_services', nonce: params.nonce },
                success: function(response) {
                    $('#speedy-service-field').remove();

                    if (!response.success) return;

                    const services = response.data.services;
                    const selected = response.data.selected;

                    // Only show a selector when there are 2+ services
                    if (!services || services.length < 2) return;

                    let radios = '';
                    $.each(services, function(i, svc) {
                        const checked = (svc.id === selected) ? ' checked="checked"' : '';
                        const costFormatted = parseFloat(svc.cost).toFixed(2);
                        radios += '<label class="speedy-service-option" style="display:block; margin-bottom:6px; font-weight:normal; cursor:pointer;">' +
                            '<input type="radio" name="speedy_service_select" value="' + svc.id + '"' + checked +
                            ' style="margin-right:6px; vertical-align:middle;">' +
                            '<span>' + svc.name + ' — ' + costFormatted + ' ' + (params.currency_symbol || '') + '</span>' +
                            '</label>';
                    });

                    const html = '<p class="form-row form-row-wide" id="speedy-service-field">' +
                        '<label>' + params.i18n.select_service + '</label>' +
                        '<span class="woocommerce-input-wrapper">' + radios + '</span></p>';

                    // Insert after the last Speedy field
                    const $anchor = $('#speedy-map-button-wrapper').length
                        ? $('#speedy-map-button-wrapper')
                        : ($('#speedy-office-field').length
                            ? $('#speedy-office-field')
                            : ($('#speedy-delivery-type-field').length
                                ? $('#speedy-delivery-type-field')
                                : $('#' + currentContext + '_city_field')));

                    $anchor.after(html);

                    $('input[name="speedy_service_select"]').on('change', function() {
                        const serviceId = $(this).val();
                        $.ajax({
                            url: params.ajax_url,
                            method: 'POST',
                            data: {
                                action: 'speedy_modern_select_service',
                                nonce: params.nonce,
                                service_id: serviceId
                            },
                            success: function(res) {
                                if (res.success) {
                                    // Update the shipping cost in the order review
                                    // without triggering a full update_checkout.
                                    updateSpeedyPriceInUI(res.data.cost);
                                }
                            }
                        });
                    });
                }
            });
        }

        /**
         * Update the Speedy shipping price displayed in the order summary.
         * The session is already updated with the selected service/price,
         * so we just trigger update_checkout to recalculate totals.
         */
        function updateSpeedyPriceInUI() {
            $(document.body).trigger('update_checkout');
        }

        function openSpeedyMap() {
            const cityId = $('#' + currentContext + '_city').val();
            let cityName = $('#' + currentContext + '_city option:selected').text();
            
            if (cityName) {
                cityName = cityName.replace(/^(гр\.|с\.|к\.|к\.к\.|в\.с\.)\s+/i, ''); 
                cityName = cityName.replace(/\s*\(\d+\)$/, ''); 
            }

            if (!cityId) {
                alert(params.i18n.alert_select_city);
                return;
            }

            const url = 'https://services.speedy.bg/office_locator_widget_v3/office_locator.php?lang=bg&showAddressForm=0&showOfficesList=0&selectOfficeButtonCaption=Select&siteName=' + encodeURIComponent(cityName);
            
            $speedyMapFrame.attr('src', url);
            $speedyMapModal.show();
        }

        // ── Street Autocomplete ──
        // When delivery type is 'address' and a city is selected, suggest
        // streets from the Speedy nomenclature as the user types in address_1.
        let streetAutocompleteOpen = false;

        // Register / unregister as a WC address autocomplete provider.
        // WC's queue_update_checkout (keydown) checks this BEFORE setting
        // its 1-second update timer. We must register BEFORE the user types.
        function setWcAutocompleteProvider(active) {
            window.wc = window.wc || {};
            window.wc.addressAutocomplete = window.wc.addressAutocomplete || {};
            window.wc.addressAutocomplete.activeProvider = window.wc.addressAutocomplete.activeProvider || {};
            if (active) {
                window.wc.addressAutocomplete.activeProvider['billing'] = true;
                window.wc.addressAutocomplete.activeProvider['shipping'] = true;
            } else {
                delete window.wc.addressAutocomplete.activeProvider['billing'];
                delete window.wc.addressAutocomplete.activeProvider['shipping'];
            }
        }

        (function initStreetAutocomplete() {
            let streetTimer = null;
            let $streetList = null;
            let streetSelectedIndex = -1;
            let selectedStreetName = ''; // Track selected street to avoid re-querying

            function getStreetList() {
                if (!$streetList) {
                    $streetList = $('<ul id="speedy-street-suggestions">')
                        .css({
                            border: '1px solid #ccc',
                            maxHeight: '200px',
                            overflow: 'auto',
                            listStyle: 'none',
                            padding: '5px',
                            marginTop: '0',
                            position: 'absolute',
                            background: '#fff',
                            zIndex: 9999,
                            display: 'none',
                            width: '100%',
                            boxSizing: 'border-box'
                        });
                }
                return $streetList;
            }

            function attachList() {
                const $input = $('#' + currentContext + '_address_1');
                if (!$input.length) return;
                const $wrapper = $input.closest('.woocommerce-input-wrapper, .form-row');
                $wrapper.css('position', 'relative');
                const $list = getStreetList();
                if (!$.contains(document.body, $list[0])) {
                    $wrapper.append($list);
                }
            }

            function highlightItem($items) {
                $items.css({ background: '', color: '' });
                if (streetSelectedIndex >= 0 && streetSelectedIndex < $items.length) {
                    $items.eq(streetSelectedIndex).css({ background: '#007BFF', color: '#fff' });
                }
            }

            function suppressWcUpdates() {
                if (!streetAutocompleteOpen) {
                    streetAutocompleteOpen = true;
                    // Set attribute so WC's should_skip_address_update skips
                    // change events, and should_trigger_address_blur_update
                    // skips blur events while autocomplete is open.
                    $('#' + currentContext + '_address_1')
                        .attr('data-autocomplete-manipulating', 'true');
                }
            }

            function restoreWcUpdates() {
                if (streetAutocompleteOpen) {
                    streetAutocompleteOpen = false;
                    $('#' + currentContext + '_address_1')
                        .removeAttr('data-autocomplete-manipulating');
                }
            }

            function selectStreet(street) {
                const $input = $('#' + currentContext + '_address_1');
                $input.val(street.label);
                selectedStreetName = street.label;
                getStreetList().empty().hide();
                streetSelectedIndex = -1;
                restoreWcUpdates();
            }

            // Listen on input events for the address field — use delegation
            // because WC may rebuild the field during AJAX updates.
            $(document.body).on('input', '#billing_address_1, #shipping_address_1', function() {
                if (!isSpeedyActive) return;

                const deliveryType = $('input[name="speedy_delivery_type"]:checked').val();
                if (deliveryType !== 'address') return;

                const query = $(this).val();
                const $cityInput = $('#' + currentContext + '_city');
                const siteId = $cityInput.val();

                if (!siteId || query.length < 2) {
                    getStreetList().empty().hide();
                    restoreWcUpdates();
                    selectedStreetName = '';
                    return;
                }

                // If a street was already selected and the user is just
                // appending text (street number, block, etc.), don't search again.
                if (selectedStreetName && query.indexOf(selectedStreetName) === 0) {
                    return;
                }
                // If the user deleted back into the street name, reset the selection
                if (selectedStreetName && query.indexOf(selectedStreetName) !== 0) {
                    selectedStreetName = '';
                }

                suppressWcUpdates();

                clearTimeout(streetTimer);
                streetTimer = setTimeout(function() {
                    attachList();
                    $.ajax({
                        url: params.ajax_url,
                        method: 'POST',
                        data: {
                            action: 'speedy_modern_search_streets',
                            nonce: params.nonce,
                            siteId: siteId,
                            name: query
                        },
                        success: function(response) {
                            const $list = getStreetList();
                            $list.empty();
                            streetSelectedIndex = -1;

                            if (response && response.length) {
                                $.each(response, function(i, street) {
                                    $('<li>')
                                        .text(street.label)
                                        .css({ cursor: 'pointer', padding: '4px 6px' })
                                        .on('mouseenter', function() {
                                            $(this).css({ background: '#f0f0f0' });
                                        })
                                        .on('mouseleave', function() {
                                            $(this).css({ background: '' });
                                        })
                                        .on('click', function() {
                                            selectStreet(street);
                                        })
                                        .appendTo($list);
                                });
                                $list.show();
                            } else {
                                $('<li>')
                                    .text(params.i18n.no_results)
                                    .css({ color: '#999', padding: '4px 6px' })
                                    .appendTo($list);
                                $list.show();
                            }
                        }
                    });
                }, 300); // Debounce 300ms
            });

            // Keyboard navigation
            $(document.body).on('keydown', '#billing_address_1, #shipping_address_1', function(e) {
                const $list = getStreetList();
                const $items = $list.find('li');
                if (!$items.length || $list.css('display') === 'none') return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    streetSelectedIndex = (streetSelectedIndex + 1) % $items.length;
                    highlightItem($items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    streetSelectedIndex = (streetSelectedIndex - 1 + $items.length) % $items.length;
                    highlightItem($items);
                } else if (e.key === 'Enter' && streetSelectedIndex >= 0) {
                    e.preventDefault();
                    let text = $items.eq(streetSelectedIndex).text();
                    if (text !== params.i18n.no_results) {
                        selectStreet({ label: text });
                    }
                } else if (e.key === 'Escape') {
                    $list.empty().hide();
                    streetSelectedIndex = -1;
                    restoreWcUpdates();
                }
            });

            // Close on outside click
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#billing_address_1, #shipping_address_1, #speedy-street-suggestions').length) {
                    getStreetList().empty().hide();
                    streetSelectedIndex = -1;
                    restoreWcUpdates();
                }
            });
        })();

        // --- Select2 matcher (from speedy-common.js) ---
        var modelMatcher = SpeedyModern.modelMatcher;

    });
})(jQuery, window.speedy_params);




