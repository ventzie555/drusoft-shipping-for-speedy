/* global speedy_params */
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
        
        // State persistence across AJAX updates
        let lastDeliveryType = 'address';
        let lastOfficeId = '';

        // Guard flag: true while restoring Speedy UI after a WooCommerce
        // checkout AJAX refresh. Prevents update_checkout → updated_checkout loops.
        let restoringState = false;

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

        // Listen for shipping method changes (User click)
        $('form.checkout').on('change', 'input[name^="shipping_method"]', function() {
            checkShippingMethod();
        });

        // Capture state BEFORE update
        $(document.body).on('update_checkout', function() {
            if (isSpeedyActive) {
                const type = $('input[name="speedy_delivery_type"]:checked').val();
                if (type) lastDeliveryType = type;
                
                const office = $('#speedy_office_id').val();
                if (office) lastOfficeId = office;
            }
        });

        // Listen for WooCommerce checkout updates (Page load / AJAX refresh)
        $(document.body).on('updated_checkout', function() {
            // Only re-capture originals when Speedy is NOT active,
            // otherwise we'd capture the Speedy-modified fields (select2 city etc.)
            // as "originals" and could never restore the real text inputs.
            if (!isSpeedyActive) {
                captureOriginals('billing');
                captureOriginals('shipping');
            }

            // Determine context based on checkbox state
            updateContext();

            // Check if form was replaced (check current context city field)
            // If it's not a select2, it means the form was refreshed
            if (!$('#' + currentContext + '_city').hasClass('select2-hidden-accessible')) {
                isSpeedyActive = false; 
            }

            // Guard: while restoring UI after AJAX refresh, don't trigger another update_checkout.
            // This flag is set before checkShippingMethod and cleared AFTER the async
            // city-loading AJAX completes (inside handleCityChange success callback).
            restoringState = true;
            checkShippingMethod();
            // Note: restoringState is cleared inside handleCityChange's AJAX success,
            // or immediately below if Speedy was already active / no AJAX needed.
            if (isSpeedyActive && $('#' + currentContext + '_city').hasClass('select2-hidden-accessible')) {
                // City select already exists – no async work was triggered
                restoringState = false;
            }
        });

        // Listen for Country Change (WC re-sorts fields on this event)
        $(document.body).on('country_to_state_changed', function() {
            if (isSpeedyActive) {
                setTimeout(function() {
                    reorderFieldsForSpeedy();
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
                activateSpeedy();
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
        checkShippingMethod();

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

        function checkShippingMethod() {
            const selectedMethod = $('input[name^="shipping_method"]:checked').val();
            if (selectedMethod && selectedMethod.indexOf(speedyMethodId) !== -1) {
                if (!isSpeedyActive) {
                    activateSpeedy();
                }
            } else {
                if (isSpeedyActive) {
                    deactivateSpeedy();
                }
            }
        }

        function activateSpeedy() {
            isSpeedyActive = true;
            console.log('Speedy Modern Activated on ' + currentContext);

            reorderFieldsForSpeedy();
            sortRegions();
            makeRegionRequired();

            const currentState = $('#' + currentContext + '_state').val();
            const currentCity = $('#' + currentContext + '_city').val();

            if (!currentState) {
                $('#' + currentContext + '_city_field').hide();
                restoringState = false;
            } else {
                handleStateChange(currentState, currentCity);
            }

            $('body').on('change.speedy', '#' + currentContext + '_state', function() {
                const state = $(this).val();
                if (isSpeedyActive) {
                    if (state) {
                        $('#' + currentContext + '_city_field').show();
                        handleStateChange(state);
                    } else {
                        $('#' + currentContext + '_city_field').hide();
                    }
                }
            });
        }

        function deactivateSpeedy() {
            isSpeedyActive = false;
            console.log('Speedy Modern Deactivated on ' + currentContext);

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

        function sortRegions() {
            const $stateSelect = $('#' + currentContext + '_state');
            const options = $stateSelect.find('option');
            const placeholder = options.filter('[value=""]');
            const sofiaCity = options.filter('[value="BG-22"]');
            const others = options.filter(function() {
                return this.value !== "" && this.value !== "BG-22";
            });

            others.sort(function(a, b) {
                return a.text.localeCompare(b.text);
            });

            $stateSelect.empty();
            $stateSelect.append(placeholder);
            if (sofiaCity.length) $stateSelect.append(sofiaCity);
            $stateSelect.append(others);

            if ($stateSelect.hasClass('select2-hidden-accessible')) {
                $stateSelect.trigger('change.select2'); 
            }
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

            $cityField.show();
        }

        function handleStateChange(stateCode, preSelectedCity) {
            if (!isSpeedyActive || !stateCode) {
                restoringState = false;
                return;
            }

            return $.ajax({
                url: params.ajax_url,
                type: 'POST',
                data: {
                    action: 'speedy_get_cities',
                    region: stateCode
                },
                success: function(response) {
                    if (response.success) {
                        replaceCityInputWithSelect(response.data, preSelectedCity);
                    }
                }
            });
        }

        function replaceCityInputWithSelect(cities, preSelectedCity) {
            const $cityField = $('#' + currentContext + '_city_field');
            const $cityWrapper = $cityField.find('.woocommerce-input-wrapper');
            const currentCity = preSelectedCity || $('#' + currentContext + '_city').val();

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
            
            if ($newCitySelect.val()) {
                 handleCityChange($newCitySelect.val());
            } else {
                 // No preselected city – clear the restoration flag
                 restoringState = false;
            }
        }

        function handleCityChange(cityId) {
            if (!cityId) return;

            const $selectedOption = $('#' + currentContext + '_city').find(':selected');
            const postcode = $selectedOption.data('postcode');
            if (postcode) {
                $('#' + currentContext + '_postcode').val(postcode).trigger('change');
            }

            $.ajax({
                url: params.ajax_url,
                type: 'POST',
                data: {
                    action: 'speedy_check_availability',
                    city_id: cityId
                },
                success: function(response) {
                    if (response.success) {
                        presentDeliveryOptions(response.data);
                    }
                    // City changed → recalculate shipping (skip during UI restoration)
                    if (!restoringState) {
                        $(document.body).trigger('update_checkout');
                    }
                    // Always clear the flag here – this is the end of the async restoration chain
                    restoringState = false;
                }
            });
        }

        function presentDeliveryOptions(data) {
            $('#speedy-delivery-type-field').remove();
            $('#speedy-office-field').remove();
            $('#speedy-map-button-wrapper').remove();

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
            
            sessionStorage.setItem('speedy_delivery_type', type);
            lastDeliveryType = type;
            
            const $address1Field = $('#' + currentContext + '_address_1_field');
            const $address2Field = $('#' + currentContext + '_address_2_field');

            if (type === 'address') {
                $address1Field.show();
                $address2Field.show();
                $address1Field.find('input').val('');
                $address2Field.find('input').val('');
            } else {
                $address1Field.hide();
                $address2Field.hide();
                
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
            let options = '<option value=""></option>';

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

            if (lastOfficeId) {
                $officeSelect.val(lastOfficeId).trigger('change');
            }

            const mapBtnHtml = '<p class="form-row form-row-wide" id="speedy-map-button-wrapper" style="margin-top: 10px;">' +
                '<button type="button" id="speedy-open-map" class="button" style="width: 100%;">' + params.i18n.select_from_map + '</button>' +
                '</p>';
            
            $('#speedy-office-field').after(mapBtnHtml);

            $('#speedy-open-map').on('click', function() {
                openSpeedyMap();
            });

            $officeSelect.on('change', function() {
                const selectedText = $(this).find('option:selected').text();
                const deliveryType = $('input[name="speedy_delivery_type"]:checked').val();
                
                const $address1Field = $('#' + currentContext + '_address_1_field');
                const $address2Field = $('#' + currentContext + '_address_2_field');

                if (deliveryType === 'office') {
                    $address1Field.find('input').val(params.i18n.to_office);
                } else if (deliveryType === 'automat') {
                    $address1Field.find('input').val(params.i18n.to_automat);
                }
                
                $address2Field.find('input').val(selectedText);
                
                lastOfficeId = $(this).val();
                sessionStorage.setItem('speedy_office_id', lastOfficeId);

                // Office/automat selected → recalculate shipping
                $(document.body).trigger('update_checkout');
            });
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

        // --- Transliteration Logic ---

        function transliterate(text) {
            const map = {
                'A': 'А', 'B': 'Б', 'V': 'В', 'G': 'Г', 'D': 'Д', 'E': 'Е', 'Z': 'З', 'I': 'И', 'J': 'Й', 'K': 'К', 'L': 'Л', 'M': 'М', 'N': 'Н', 'O': 'О', 'P': 'П', 'R': 'Р', 'S': 'С', 'T': 'Т', 'U': 'У', 'F': 'Ф', 'H': 'Х', 'C': 'Ц',
                'a': 'а', 'b': 'б', 'v': 'в', 'g': 'г', 'd': 'д', 'e': 'е', 'z': 'з', 'i': 'и', 'j': 'й', 'k': 'к', 'l': 'л', 'm': 'м', 'n': 'н', 'o': 'о', 'p': 'п', 'r': 'р', 's': 'с', 't': 'т', 'u': 'у', 'f': 'ф', 'h': 'х', 'c': 'ц',
                'Sht': 'Щ', 'sht': 'щ', 'Sh': 'Ш', 'sh': 'ш', 'Ch': 'Ч', 'ch': 'ч', 'Yu': 'Ю', 'yu': 'ю', 'Ya': 'Я', 'ya': 'я', 'Zh': 'Ж', 'zh': 'ж', 'Ts': 'Ц', 'ts': 'ц',
                'Y': 'Й', 'y': 'й', 'X': 'Х', 'x': 'х', 'W': 'В', 'w': 'в', 'Q': 'Я', 'q': 'я'
            };

            const multiChars = ['Sht', 'sht', 'Sh', 'sh', 'Ch', 'ch', 'Yu', 'yu', 'Ya', 'ya', 'Zh', 'zh', 'Ts', 'ts'];
            for (let i = 0; i < multiChars.length; i++) {
                const latin = multiChars[i];
                const cyrillic = map[latin];
                const regex = new RegExp(latin, 'g');
                text = text.replace(regex, cyrillic);
            }

            let result = '';
            for (let i = 0; i < text.length; i++) {
                const char = text[i];
                result += map[char] || char;
            }
            return result;
        }

        function modelMatcher(params, data) {
            if ($.trim(params.term) === '') {
                return data;
            }
            if (typeof data.text === 'undefined') {
                return null;
            }
            const original = data.text.toUpperCase();
            const term = params.term.toUpperCase();
            const transliteratedTerm = transliterate(params.term).toUpperCase();

            if (original.indexOf(term) > -1 || original.indexOf(transliteratedTerm) > -1) {
                return data;
            }
            return null;
        }

    });
})(jQuery, window.speedy_params);