/* Written by Doug Webster in 2016 - 2017. It is a web app which allows trailer dealers to customize trailers and get quotes for their selection. Allows for filtering, searching, selection of colors, and selection of various options. */

$(document).ready(function(){
	
	// setup expanding of brand models
	// default to closed
	$('.brand-models').addClass('closed');
	$('.brand-models .models').hide();
	
	$('.brand-models .brand').click(function(e){
		var parent = $(this).parent();
		if (parent.hasClass('closed')) {
			parent.removeClass('closed');
			parent.addClass('opened');
			parent.find('.models').slideDown();
		} else {
			parent.removeClass('opened');
			parent.addClass('closed');
			parent.find('.models').slideUp();
		}
	});
	
	// navigate through steps by clicking on step / progress bar
	$('.step-bar .step').click(function(e){
		quote.switchStep($(this).data('step'));
	});
	
	// click filter option
	$('.quoting #filters .datalist .option').click(function(e){
		var opt = $(this);
		if (opt.hasClass('selected')){
			opt.removeClass('selected');
			opt.addClass('not-selected');
			// if no other options are selected, remove all not-selected classes
			if (opt.parent().find('.option.selected').length == 0)
				opt.parent().find('.option').removeClass('not-selected');
		} else {
			opt.removeClass('not-selected');
			opt.addClass('selected');
			// mark all other non-selected options as not-selected
			opt.parent().find('.option').each(function(i){
				if (!$(this).hasClass('selected'))
					$(this).addClass('not-selected');
			});
		}
		quote.filtersRefresh();
	});
	
	// click filters reset
	$('#filters a.reset').click(quote.resetFilters);
	$('#option-filters a.reset').click(quote.resetOptionFilters);
	
	// search models
	//$('#search-models').on('change keyup',quote.filtersRefresh);
	$('#search-models').keyup(function(event){
		if (event.keyCode != 13) return;
		quote.resetFilters(true);
		quote.filtersRefresh();
	});
	$('#search-models ~ button.search').click(function(event){
		quote.resetFilters(true);
		quote.filtersRefresh();
	});
	$('#search-models ~ button.clear').click(function(){
		quote.resetFilters();
		$('#search-models').val('');
		quote.filtersRefresh();
	});
	
	// select model
	$('.brand-models .model').click(function(e){
		quote.selectModel($(this).data('key'));
	});
	
	// expand / collapse standards/features
	$('#selected-model .header-bar').click(function(e){
		var el = $(this);
		if (el.hasClass('closed')){
			el.removeClass('closed');
			el.addClass('opened');
			el.next().slideDown();
		} else {
			el.removeClass('opened');
			el.addClass('closed');
			el.next().slideUp();
		}
	});
	
	// change base color
	$('#base-color').change(function(){
		quote.changeBaseColor();
	});
	
	// change overlay color
	$('#overlay-color').change(function(){
		quote.changeOverlayColor();
	});
	
	// handle change of markup
	$('input.markup').change(quote.updateMarkup);
	
	// recalculate on input change
	$('input.freight, input.tax, input.misc, input.discount').change(function(){
		quote.recalculate();
	});
	
	// show hide settings on settings icon click
	$('.header-bar img.settings').click(function(e){
		$('.row.settings').slideToggle();
	});
	
	// collapse available options
	$('#available-options .header').click(function(){
		$('.option-category').slideToggle();
		quote.showHideOptCategoryTitles();
	});
	
	// search options
	//$('#search-options').on('change keyup',quote.filterOptions);
	$('#search-options').keyup(function(event){
		if (event.keyCode != 13) return;
		quote.resetOptionFilters(true);
		quote.filterOptions();
	});
	$('#search-options ~ button.search').click(function(event){
		quote.resetOptionFilters(true);
		quote.filterOptions();
	});
	$('#search-options ~ button.clear').click(function(){
		quote.resetOptionFilters();
		$('#search-options').val('');
		quote.filterOptions();
	});
	
	// reset
	$('.buttons .reset').click(function(e){
		location.replace('quote');
	});
	
	// warn about leaving with unsaved changes
	$('.quoting').append('<input type="hidden" id="ChangeFlag" name="ChangeFlag" value="0" />');
	$(window).bind("beforeunload", function() {
		console.log($('#ChangeFlag').val());
		if ( $('#ChangeFlag').val() == 1 ) {
			return "Your changes will be lost if you click OK. Click CANCEL to go back and save.";
		}
	});
});

var quote = {
	selectedModel: {},
	models: [],
	availableOptions: [],
	quote_id: null,
	
	// clear all filters
	resetFilters: function(keep_search) {
		var els = $('#filters .datalist .option');
		els.removeClass('selected');
		els.removeClass('not-selected');
		els.removeClass('not-available');
		// show all models, collapse all brands
		$('.brand-models .models').hide();
		$('.brand-models .model').show();
		$('.brand-models').removeClass('opened');
		$('.brand-models').addClass('closed');
		$('.brand-models').show();
		$('.no-matches-msg').hide();
		if (!keep_search)
			$('#search-models').val('');
		//quote.filtersRefresh();
	},
	
	// get the currently selected filter options, excluding specified filter if set
	// returns selectedOptions = {column/filter: [selected options],...}
	getFilterSelections: function(filter) {
		var selectedOptions = {};
		// loop through each filter group
		$('#filters .datalist').each(function(i){
			var column = $(this).data('column');
			// skip specified filter if present
			if (filter && filter.toLowerCase() == column.toLowerCase())
				return true; // same as continue;
			var opts = []; // multiple selections possible
			// loop through each selected option in filter group
			$(this).find('.option.selected').each(function(j){
				opts.push($(this).text());
			});
			// don't add this column as a property if no options are selected
			if (opts.length > 0)
				selectedOptions[column] = opts;
		});
		return selectedOptions;
	},
	
	// determine which models are available for the selected filter options
	getAvailableModels: function(selectedOptions) {
		var search = $('#search-models').val();
		if ($.isEmptyObject(selectedOptions) && !search)
			return quote.models;
		var availableModels = [];
		// loop through each model
		for (var i=0; i<quote.models.length; i++){
			var available = true;
			
			// loop through each selected option
			for (column in selectedOptions){
				// effectively checks to see if model's value for column is in
				// the array of selected filter options for column
				var inArray = selectedOptions[column].some(function(v){
					return quote.models[i][column] == v;
				});
				//if (quote.models[i][column] != selectedOptions[column]){
				if (!inArray){
					available = false;
					break;
				}
			} // end loop for each selected filter option
			
			// check against search
			if (available && !quote.searchModelsMatches(quote.models[i])){
				available = false;
			}
			
			if (available)
				availableModels.push(quote.models[i]);
		} // end loop for each model
		return availableModels;
	},
	
	// returns true if given model matches search term, false otherwise
	searchModelsMatches: function(model) {
		var search = $('#search-models').val();
		// if search is empty, pass all
		if (!search) return true;
		
		var re = new RegExp(escapeRegExp(search),'i');
		if (  re.test(model.model_key)
		   //|| re.test(model.brand)
		   || re.test(model.model_full_projection)
		   || re.test(model.model)
		) {
			return true;
		}
		return false;
	},
	
	// sets the availability of each option in a filter group based upon whether there is a match in the list of available models
	setFilterOptionAvailability: function(column, availableModels) {
		// loop through each option in filter group
		$('#filters .datalist[data-column="'+column+'"] .option').each(function(i){
			var foption = $(this);
			var opt = foption.text();
			// clear previous availability setting
			foption.removeClass('available');
			foption.removeClass('not-available');
			// this ought to set available to true if any of the available models
			// has this option for the value of column
			var available = availableModels.some(function(model){
				return model[column].toLowerCase() == opt.toLowerCase();
			});
			var oclass = (available) ? 'available' : 'not-available';
			foption.addClass(oclass);
		});
	},
	
	// show / hide brands and models in model list based on availability
	filterModelList: function(availableModels) {
		$('.brand-models .model').hide();
		$('.brand-models .models').show();
		$('.brand-models').show();
		$('.brand-models').removeClass('closed');
		$('.brand-models').addClass('opened');
		// loop through each available model and show it
		for (var i=0; i<availableModels.length; i++){
			$('.brand-models .model[data-key="'+availableModels[i].model_key+'"]').show();
		}
		// loop through each brand in order to show / hide depending on if there are any available models
		$('.brand-models').each(function(i){
			var brand = $(this);
			var available = brand.find('.model:visible').length;
			if (available){
				brand.removeClass('closed');
				brand.addClass('opened');
				brand.find('models').show();
				brand.show();
			} else {
				brand.hide();
			}
		});
	},
	
	// updates items on page after filter selection change
	filtersRefresh: function() {
		var search = $('#search-models').val();
		// first, update the availability of filter options
		// loop through each filter group
		$('#filters .datalist').each(function(i){
			var column = $(this).data('column');
			var selectedOptions = quote.getFilterSelections(column);
			// if there are no selected options for any other filter group
			// all options for this filter group are available
			// this is a short cut which prevents extra processing below
			if ($.isEmptyObject(selectedOptions) && !search){
				$(this).find('.option').removeClass('not-available');
				$(this).find('.option').addClass('available');
				return true; // same as continue;
			}
			// if the above is not true, we have to go through and determine what options are available
			// first, we have to filter the list of available models to only those matching the selected options
			var availableModels = quote.getAvailableModels(selectedOptions);
			// then we can determine if each filter option is available based upon
			// whether there is a match in the list of available models
			quote.setFilterOptionAvailability(column, availableModels);
		});
		
		// next, filter the list of available trailer models
		var selectedOptions = quote.getFilterSelections();
		if ($.isEmptyObject(selectedOptions) && !search){
			$('.brand-models').show();
			$('.brand-models').removeClass('opened');
			$('.brand-models').addClass('closed');
			$('.brand-models .models').hide();
			$('.brand-models .model').show();
			$('.no-matches-msg').hide();
		} else {
			var availableModels = quote.getAvailableModels(selectedOptions);
			quote.filterModelList(availableModels);
			if (availableModels.length > 0){
				$('.no-matches-msg').hide();
			} else {
				$('.no-matches-msg').show();
			}
		}
	},
	
	// return the model for the specified key
	getModel: function(key){
		for (var i=0; i<quote.models.length; i++){
			if (quote.models[i].model_key == key)
				return quote.models[i];
		}
	},
	
	// handle step 1 selection of model and fill in details in right column
	selectModel: function(key,callback){
		$('#ChangeFlag').val('1'); // indicate changes
		
		$('.brand-models .model').removeClass('selected');
		$('.brand-models .model[data-key="'+key+'"]').addClass('selected');
		$('#selected-model').show();
		
		// clear previous info
		$('#selected-model .standards-list').html('');
		$('#selected-model .features-list').html('');
		
		var model = quote.getModel(key);
		quote.selectedModel = model;
		$('#selected-model .brand').text(model.brand);
		$('#selected-model .axle_config').text(model.axle_config+' Trailer');
		//$('#selected-model .preview-img').html('<img src="'+key+'.jpg" alt="">');
		$('#selected-model .model_and_info').text(model.model_and_info);
		for (var i=0; i<model.standards.length; i++) {
			var row = "<div class='row'>"+model.standards[i]+"</div>";
			$('#selected-model .standards-list').append(row);
		}
		quote.selectedModel.features = {};
		for (column in quote.featuresScema) {
			if (model[column]){
				quote.selectedModel.features[quote.featuresScema[column]] = model[column];
				var row = "<div class='row'><span>"+model[column]+"</span><span>"+quote.featuresScema[column]+"</span></div>";
				$('#selected-model .features-list').append(row);
			}
		}
		
		// collapse standards and features
		$('#selected-model .header-bar').removeClass('opened');
		$('#selected-model .header-bar').addClass('closed');
		$('#selected-model .standards-list').hide();
		$('#selected-model .features-list').hide();
		
		// calculate price summary
		$('#quote-summary .base-price').data('model_total', model.model_total);
		quote.updateMarkup();
		
		// add default image
		$('#base-color').val('Black');
		quote.changeBaseColor();
		$('#overlay-color').val('');
		quote.changeOverlayColor();
		
		// get available options
		quote.getAvailableOptions(model.model_key, callback);
		// clear selected options since they may not be correct anymore
		$('#selected-options tbody tr').remove();
		quote.updateOptionsTotal();
	},
	
	// formats a value and displays it
	displayCost: function(el, amount, displayZero, decimal) {
		if (!decimal) decimal = 0;
		var check = 1;
		if (decimal) check = 1 / (10 * decimal);
		if (displayZero || (amount && parseFloat(amount) >= check)){
			el.text('$'+numberFormat(amount,decimal));
		} else {
			el.text('');
		}
	},
	
	// return a value obtained from DOM element
	getCost: function(el) {
		if (!el) return 0;
		var v = (el.get(0).tagName == 'INPUT') ? el.val() : el.text();
		v = v.replace(/\$|,/g,''); // remove $ and ,
		return parseFloat(v);
	},
	
	// recalculate values after the markup has changed
	updateMarkup: function(){
		// set the base price in the summary section
		var model_total = parseFloat($('#quote-summary .base-price').data('model_total'));
		var markup = quote.getMarkup();
		base = markupValue(model_total, markup);
		quote.displayCost($('#quote-summary .base-price .price'), base, false, QUOTING_DOLLAR_PRECISION);
		
		// adjust all of the model selection list
		$('#model-selector .brand-models .model .price').each(function(i){
			var model_total = parseFloat($(this).data('model_total'));
			base = markupValue(model_total, markup);
			quote.displayCost($(this), base, false, QUOTING_DOLLAR_PRECISION);
		});
		
		// update all option totals on load
		$('#selected-options tbody tr').each(function(i){
			quote.updateOptionPrices($(this).data('id'));
		});
		
		quote.sumSurcharge();
		quote.recalculate();
	},
	
	// recalculate total surcharge
	sumSurcharge: function() {
		var markup = quote.getMarkup();
		var surcharge = 0;
		
		// get model surcharge, markup
		surcharge += parseFloat($('.brand-models .model.selected .price').data('surcharge'));
		
		// get options surcharges, markup
		$('#selected-options tbody tr .cost').each(function(i){
			var qty = $(this).parent().find('.o-qty').val();
			surcharge += parseFloat($(this).data('surcharge')) * qty;
		});
		
		// set surcharge
		//var old = $('.surcharge .old-price').detach();
		surcharge = markupValue(surcharge, markup);
		quote.displayCost($('.surcharge'), surcharge, true, QUOTING_DOLLAR_PRECISION);
		//$('.surcharge').prepend(old);
	},
	
	// update quote totals
	recalculate: function() {
		var basePrice = quote.getCost($('.base-price .price'));
		var subtotal = basePrice;
		var optionsTotal = quote.getCost($('.options-total'));
		if (!isNaN(optionsTotal))
			subtotal += optionsTotal;
		var surchargeTotal = quote.getCost($('.surcharge'));
		if (!isNaN(surchargeTotal))
			subtotal += surchargeTotal;
		quote.displayCost($('.subtotal'), subtotal, false, QUOTING_DOLLAR_PRECISION);
		var total = subtotal;
		var freight = quote.getCost($('.freight'));
		if (!isNaN(freight))
			total += freight;
		var tax = quote.getCost($('.tax'));
		if (!isNaN(tax))
			total += tax;
		var misc = quote.getCost($('.misc'));
		if (!isNaN(misc))
			total += misc;
		var discount = quote.getCost($('.discount'));
		if (!isNaN(discount)){
			//if (discount > total) discount = total; // 0 minimum for total
			total -= discount;
		}
		quote.displayCost($('.quote-total'), total, false, 2);
	},
	
	// handle changing the base color and photo
	changeBaseColor: function() {
		var img_base = quote.selectedModel.img_base;
console.log(img_base);
		
		// handle trailers w/o color options
		var no_color_options = false;
		for (i=0; i<quote.non_color_img_bases.length; i++) {
			if (quote.non_color_img_bases[i] != img_base) continue;
			no_color_options = true;
		}
		if (no_color_options) {
			$('#color-selector .inputs, #color-selector #no-color-options + p, #color-summary').hide();
			$('#color-selector #no-color-options').show();
		} else {
			$('#color-selector .inputs, #color-selector #no-color-options + p, #color-summary').show();
			$('#color-selector #no-color-options').hide();
		}
		
		var color = $('#base-color').val();
		$('.trailer-preview img.base, .preview-img img.base').remove();
		if (img_base && color && quote.images[img_base]
			&& quote.images[img_base][color]
			&& quote.images[img_base][color].base) {
			var src = base_url + quote.images[img_base][color].base;
			var img = "<img src='"+src+"' alt='' class='base'>";
			$('.trailer-preview, .preview-img').prepend(img);
		}
		// changing the base color could mean that there is no longer a base image
		// in this case, we want to remove the overlay because it won't display correctly
		// conversely, we may need to add the overlay image if it was previously hidden due to no base image
		quote.changeOverlayColor();
	},
	
	// handle changing the overlay (back) color and photo
	changeOverlayColor: function() {
		var img_base = quote.selectedModel.img_base;
		var color = $('#overlay-color').val();
		$('.trailer-preview img.overlay, .preview-img img.overlay').remove();
		// overlay image won't display correctly if the base image doesn't exist
		if ($('.trailer-preview img.base').length < 1 ||
			$('.preview-img img.base').length < 1)
			return;
		if (img_base && color && quote.images[img_base]
			&& quote.images[img_base][color]
			&& quote.images[img_base][color].overlay) {
			var src = base_url + quote.images[img_base][color].overlay;
			var img = "<img src='"+src+"' alt='' class='overlay'>";
			$('.trailer-preview, .preview-img').append(img);
		}
	},
	
	// handle switching between steps
	switchStep: function(to) {
		var from = $('.step-bar .step.active').data('step');
		// go to step 1 if model is not selected
		if ($('.brand-models .model.selected').length < 1)
			to = 1;
		
		if (to == 'back' && from > 1)
			to = from - 1;
		if (to == 'next' && from < 4)
			to = from + 1;
		
		if (from == to) return;
		
		$('.quoting .column .buttons').hide();
		switch(from) {
			case 1:{
				$('#filters, #model-selector').hide();
				$('#selected-model button').hide();
				break;
			}
			case 2:{
				$('#color-selector, #quote-summary').hide();
				break;
			}
			case 3:{
				$('#options-selector, #quote-summary, #option-filters').hide();
				break;
			}
			case 4:{
				$('#quote-summary2, #quote-summary').hide();
				$('#quote-summary .row > span > input').show();
				$('#quote-summary .row > span:not(.base-price) > span').hide();
				$('#selected-options input').show();
				$('#selected-options input + span').hide();
				break;
			}
		}
		
		if (from == 1 || to == 1)
			var selectedModel = $('#selected-model').detach();
		if (to > 1)
			$('#column1').prepend(selectedModel);
		
		if (from == 4 || to == 4)
			var selectedOptions = $('#selected-options').detach();
		if (to < 4)
			$('#options-selector .subtitle-2').after(selectedOptions);
		
		switch(to) {
			case 1:{
				$('#column3').append(selectedModel);
				$('#filters, #model-selector, .buttons.step1').show();
				$('#selected-model button').show();
				//$('.buttons .next').text('Step 2 - Choose Colors');
				break;
			}
			case 2:{
				$('#color-selector, #quote-summary, .buttons.step2').show();
				//$('.buttons .next').text('Step 3 - Options');
				break;
			}
			case 3:{
				$('#options-selector, #quote-summary, .buttons.step3').show();
				quote.showHideOptCategoryTitles();
				//$('.buttons .next').text('Step 4 - Review');
				if ($('#option-filters .datalist .option').length > 1)
					$('#option-filters').show();
				// update all option totals on load
				$('#selected-options tbody tr').each(function(i){
					quote.updateOptionPrices($(this).data('id'));
				});
				break;
			}
			case 4:{
				$('#quote-summary2, #quote-summary, .buttons.step4').show();
				$('#options-summary').append(selectedOptions);
				$('#quote-summary .row > span > input').hide();
				$('#quote-summary .row > span > input').each(function(i){
					if ($(this).hasClass('markup'))
						$(this).next().text($(this).val());
					else
						quote.displayCost($(this).next(), $(this).val(), true, 2);
				});
				$('#quote-summary .row > span:not(.base-price) > span').show();
				$('#selected-colors .base .value').text($('#base-color :selected').text());
				$('#selected-colors .overlay .value').text($('#overlay-color :selected').text());
				$('#selected-options input').each(function(i){
					$(this).next('span').text($(this).val());
				});
				$('#selected-options input + span').show();
				$('#selected-options input').hide();
				break;
			}
		}
		
		// collapse standards and features
		$('#selected-model .header-bar').removeClass('opened');
		$('#selected-model .header-bar').addClass('closed');
		$('#selected-model .standards-list').hide();
		$('#selected-model .features-list').hide();
		
		// update step / progress bar
		$('.step-bar .active').removeClass('active');
		$('.step-bar > .step[data-step="'+to+'"]').addClass('active');
		$('.step-bar > .step[data-step="'+to+'"]').prev('.tail').addClass('active');
	},
	
	// gets available options from server for specified model
	getAvailableOptions: function(model_key,callback) {
		request.post(base_url+'quote', {action: 'get-available-options', model_key:model_key}, function(r){
			r = eval(r);
			if (r.success) {
				quote.availableOptions = r.options;
				quote.outputOptionFilters(r.filters);
				quote.outputAvailableOptions(callback);
			}
		});
	},
	
	// fill in the available options
	outputAvailableOptions: function(callback) {
		// clear previous
		$('#available-options .option-category').remove();
		
		var category = false;
		var output = '';
		var prices = [];
		for (var i=0; i<quote.availableOptions.length; i++){
			var option = quote.availableOptions[i];
			// if this begins a new category
			if (category != option.category) {
				// if this isn't the first category, close previous
				if (category) {
					output += "\
					</div><!-- .datalist -->\
				</div>\
					";
				}
				category = option.category;
				output += "\
				<div class='option-category'>\
					<div class='title'>"+category+"</div>\
					<div class='datalist'>\
				";
			}
			var notes = (option.notes) ? "<div class='description'>"+option.notes.replace(/(?:\r\n|\r|\n)/g, '<br />')+"</div>" : '';
			output += "\
						<div class='option' value='"+option.option_id+"' data-group='"+option.group+"'>\
							<span class='price'></span>\
							"+option.option_id+" - "+option.option+"\
						</div>\
						"+notes+"\
			";
			var price = markupValue(option.price, quote.getMarkup());
			prices.push({id: option.option_id, price: price});
		} // end loop for each option
		$('#available-options').append(output);
		
		// add prices
		for (var i=0; i<prices.length; i++) {
			quote.displayCost($('.option[value="'+prices[i].id+'"] .price'), prices[i].price, true, QUOTING_DOLLAR_PRECISION);
		}
		
		// add option click event handler
		$('#available-options .option').click(function(){
			quote.addOption($(this).attr('value'));
		});
		
		// start collapsed
		//$('.option-category').slideUp();
		
		if (typeof callback == 'function')
			callback();
	},
	
	// clear option filter selections
	resetOptionFilters: function(keep_search) {
		$('#option-filters .datalist .option').removeClass('selected');
		$('#option-filters .datalist .option').removeClass('not-selected');
		$('#available-options .option').removeClass('filter-out');
		if (!keep_search)
			$('#search-options').val('');
		quote.showHideOptCategoryTitles();
	},
	
	outputOptionFilters: function(filters) {
		// clear filters
		$('#option-filters .datalist .option').remove();
		
		/*if (filters.length < 2) {
			$('#option-filters').addClass('hide');
			return;
		}*/
		
		// add filters
		for (var i=0; i<filters.length; i++) {
			var opt = "<div class='option'>"+filters[i]+"</div> ";
			$('#option-filters .datalist').append(opt);
		}
		
		// bind event
		$('#option-filters .datalist .option').click(function(e){
			var opt = $(this);
			if (opt.hasClass('selected')){
				opt.removeClass('selected');
				opt.addClass('not-selected');
				// if no other options are selected, remove all not-selected classes
				if (opt.parent().find('.option.selected').length == 0)
					opt.parent().find('.option').removeClass('not-selected');
			} else {
				opt.removeClass('not-selected');
				opt.addClass('selected');
				// mark all other non-selected options as not-selected
				opt.parent().find('.option').each(function(i){
					if (!$(this).hasClass('selected'))
						$(this).addClass('not-selected');
				});
			}
			quote.filterOptions();
		});
	},
	
	// show/hide options based on filter selections
	filterOptions: function() {
		// get selected options
		var selected_filters = [];
		$('#option-filters .datalist .option.selected').each(function(i){
			selected_filters.push($(this).text());
		});
		var search = $('#search-options').val();
		// if none selected, reset
		if (!selected_filters.length && !search) {
			quote.resetOptionFilters();
			return;
		}
		
		$('#available-options .option').each(function(i){
			var group = $(this).data('group');
			var match = selected_filters.indexOf(group);
			var option = quote.getOption($(this).attr('value'));
			if ((selected_filters.length && match == -1) || !quote.searchOptionsMatches(option)) {
				$(this).addClass('filter-out');
			} else {
				$(this).removeClass('filter-out');
			}
		});
		
		quote.showHideOptCategoryTitles();
	},
	
	// returns true if given option matches search term, false otherwise
	searchOptionsMatches: function(option) {
		var search = $('#search-options').val();
		// if search is empty, pass all
		if (!search) return true;
		
		var re = new RegExp(escapeRegExp(search),'i');
		if (  re.test(option.option)
		   || re.test(option.group)
		   || re.test(option.option_id)
		) {
			return true;
		}
		return false;
	},
	
	// update the price total for an option
	updateOptionPrices: function(id){
		if ($('#option-'+id).hasClass('old-option'))
			return;
		var markup = quote.getMarkup();
		var qty = parseInt($('#option-'+id+' .o-qty').val());
		if (qty < 1) {
			quote.removeOption(id);
			return;
		}
		var price = parseFloat($('#option-'+id+' .cost').data('price'));
		var cost = markupValue(price, markup);
		quote.displayCost($('#option-'+id+' .cost .price'), cost, true, QUOTING_DOLLAR_PRECISION);
		var total = cost * qty;
		quote.displayCost($('#option-'+id+' .opt-tot'), total, true, QUOTING_DOLLAR_PRECISION);
		quote.updateOptionsTotal();
	},
	
	// update the total for all options
	updateOptionsTotal: function(){
		var optionsTotal = 0;
		$('#selected-options .opt-tot').each(function(i){
			optionsTotal += quote.getCost($(this));
		});
		quote.displayCost($('#quote-summary .options-total'), optionsTotal, true, QUOTING_DOLLAR_PRECISION);
		quote.sumSurcharge();
		quote.recalculate();
	},
	
	// return selected option from available options array
	getOption: function(id){
		for (var i=0; i<quote.availableOptions.length; i++){
			if (quote.availableOptions[i].option_id == id)
				return quote.availableOptions[i];
		}		
	},
	
	// select option
	addOption: function(id, option_index, callback){
		$('#selected-options tfoot').hide();
		var markup = quote.getMarkup();
		var option = quote.getOption(id);
		// hide available option
		$('#available-options .option[value="'+id+'"] + .description').fadeOut();
		$('#available-options .option[value="'+id+'"]').fadeOut({
			complete: function(){
				var row = "\
					<tr id='option-"+id+"' data-id='"+id+"'>\
						<td>"+id+"</td>\
						<td>"+option.option+"</td>\
						<td><input type='number' min='1' value='"+option.default_qty+"' class='o-qty'><span></span></td>\
						<td class='cost' data-price='"+option.price+"' data-surcharge='"+option.surcharge+"'><span class='price'></span></td>\
						<td class='opt-tot'></td>\
						<td>\
							<span class='note'></span>\
							<img src='media/images/icon-remove.svg' alt='remove' class='remove'>\
						</td>\
					</tr>\
				";
				$('#selected-options tbody').append(row);
				quote.saveOptionNote(id, option.notes);
				var cost = markupValue(option.price, markup);
				quote.displayCost($('#option-'+id+' .cost .price'), cost, false, QUOTING_DOLLAR_PRECISION);
				quote.updateOptionPrices(id);
				//$('.option-category').slideUp(); // close options "drop down"
				quote.showHideOptCategoryTitles();
				
				// change of option quantity
				$('#selected-options #option-'+id+' input.o-qty').change(function(){
					quote.updateOptionPrices($(this).parents('tr').data('id'));
				});
				
				// notes click
				$('#selected-options #option-'+id+' .note').click(function(e){
					$('.modal-content .title span').html('<img src="'+base_url+'media/images/icon-note-white.svg" alt="">Notes');
					var noteForm = "<label for='i-note'>Add Notes:</label>\
					 <div style='overflow: auto;'><textarea id='i-note'></textarea></div>\
					";
					var buttons = "\
						<button type='button' class='gray-bg cancel'>Cancel</button>\
						<button type='button' class='blue-bg save'>Save</button>\
					";
					$('.modal-content .content').html(noteForm);
					$('.modal-content .buttons').html(buttons);
					$('.modal-bg').fadeIn();
					// show existing note if present
					$('#i-note').val(quote.getOptionNote(id));
					
					// cancel - close modal form
					$('.modal-bg .buttons .cancel').click(function(event){
						event.preventDefault();
						$('.modal-bg').fadeOut();
					});
					
					// save
					$('.modal-bg .buttons .save').click(function(event){
						event.preventDefault();
						// save note
						quote.saveOptionNote(id, $('#i-note').val());
						$('.modal-bg').fadeOut(function(){});
					});
				});
				
				// close modal form
				$('.modal-close').click(function(event){
					event.preventDefault();
					$('.modal-bg').fadeOut(function(){
					});
				});
				
				// remove option
				$('#selected-options #option-'+id+' .remove').click(function(){
					quote.removeOption($(this).parents('tr').data('id'));
				});
				
				if (typeof callback == 'function') callback(option_index);
			},
		});
	},
	
	// save option note in DOM
	saveOptionNote: function(id,note){
		if (note.replace(/ /g,'').length > 0){
			$('#selected-options #option-'+id+' .note').addClass('active');
			if ($('#o-note-'+id).length < 1){
				var row = "<tr class='note'><td></td><td colspan='99' id='o-note-"+id+"'>Note: <span></span></td></tr>";
				$('#option-'+id).after(row);
			}
			// html() / innerHTML apparently escapes & to &amp; if not already done
			$('#o-note-'+id+' span').html(note.replace(/(?:\r\n|\r|\n)/g, '<br />\r\n'));
		} else {
			$('#o-note-'+id).remove();
			$('#selected-options #option-'+id+' .note').removeClass('active');
		}
	},
	
	// get note content from DOM
	getOptionNote: function(id){
		if ($('#o-note-'+id).length > 0) {
			return $('#o-note-'+id+' span').text();
		} else
			return '';
	},
	
	// remove option
	removeOption: function(id){
		// remove note first if exists
		$('#selected-options #option-'+id+' + .note').remove();
		$('#selected-options #option-'+id).remove();
		// remove display on element so as not to interfere with filtering
		$('#available-options .option[value="'+id+'"], #available-options .option[value="'+id+'"] + .description').each(function(i){
			$(this).get(0).style.display = '';
		});
		// show option in available (not-selected) list if not filtered out
		$('#available-options .option[value="'+id+'"]:not(.filter-out), #available-options .option[value="'+id+'"]:not(.filter-out) + .description').fadeIn({
			complete: quote.showHideOptCategoryTitles,
		});
		quote.updateOptionsTotal();
		if ($('#selected-options tbody tr').length < 1)
			$('#selected-options tfoot').show();
	},
	
	// shows or hide an option category title based on whether there are any visible options
	showHideOptCategoryTitles: function(){
		$('#available-options .option-category').each(function(i){
			var cat = $(this);
			if (cat.find('.option:visible').length > 0)
				cat.find('.title').show();
			else
				cat.find('.title').hide();
		});
	},
	
	// return the markup
	getMarkup: function(){
		return parseFloat($('input.markup').val());
	},
	
	// save the quote
	save: function(fn){
		// collect data
		var data = {
			quote_id: quote.quote_id,
			customer_id: quote.customer_id,
			model: quote.selectedModel,
			//base_color: $('#base-color option:selected').text(),
			//overlay_color: $('#overlay-color option:selected').text(),
			base_color: $('#base-color').val(),
			overlay_color: $('#overlay-color').val(),
			markup: quote.getMarkup(), //$('input.markup').val(),
			freight: $('input.freight').val(),
			tax: $('input.tax').val(),
			misc: $('input.misc').val(),
			discount: $('input.discount').val(),
			name: $('#quote-name').val(),
			phone: $('#quote-phone').val(),
			po: $('#quote-po').val(),
			est_delivery: $('#quote-est_delivery').val(),
			notes: $('#quote-notes textarea').val(),
			options: [],
		};
		$('#selected-options tbody tr:not(.note)').each(function(i){
			var oid = $(this).data('id');
			data.options.push({
				option_id: oid,
				option: quote.getOption(oid),
				qty: $('#option-'+oid+' .o-qty').val(),
				note: quote.getOptionNote(oid),
			});
		});
		
		// send to PHP
		request.post(base_url+'quote', {action: 'save-quote', data: JSON.stringify(data)}, function(r){
			r = eval(r);
			if (!r.success) {
				alert('Error attempting to save.');
			} else {
				$('#ChangeFlag').val('0'); // indicate no unsaved changes
				quote.quote_id = r.quote_id;
				if (typeof fn == 'function')
					fn(quote.quote_id, false);
				else
					location.assign(base_url+'quotes');
			}
		});
	},
	
	// save then call print function
	saveAndPrint: function(){
		quote.save(quote.print);
	},
	
	// direct to view quote page
	print: function(){
		var url = base_url + 'quote-view?qid='+quote.quote_id;
		messages.alert('<a href="'+url+'" target="_blank">Click here</a> to view print page.');
		//location.assign(url);
		//window.open(url);
	},
	
	// save then call email function
	saveAndEmail: function(){
		quote.save(quote.launchEmailDialog);
	},
	
	// email a link to the view quote page
	launchEmailDialog: function(quote_id, redirect){
		request.post(base_url+'quote', {action: 'get-email-quote-form', quote_id: quote_id}, function(r){
			r = eval(r);
			if (r.success) {
				$('.modal-content .title span').html('<img src="'+base_url+'media/images/icon-email-white.svg" alt="" style="vertical-align: top;">Email');
				var buttons = "\
			<button type='button' class='gray-bg cancel'>Cancel</button>\
			<button type='button' class='blue-bg send'>Send</button>\
				";
				$('.modal-content .content').html(r.form);
				$('.modal-content .buttons').html(buttons);
				$('.modal-bg').fadeIn();
				
				// cancel - close modal form
				$('.modal-bg .buttons .cancel').click(function(event){
					event.preventDefault();
					$('.modal-bg').fadeOut();
				});
				
				// close modal form
				$('.modal-close').click(function(event){
					event.preventDefault();
					$('.modal-bg').fadeOut(function(){
					});
				});
				
				// send
				$('.modal-bg .buttons .send').click(function(event){
					event.preventDefault();
					// send email
					quote.email($('#i-quote_id').val(), $('#i-to').val(), $('#i-from').val(), $('#i-subject').val(), $('#i-message').val(), redirect);
					$('.modal-bg').fadeOut(function(){});
				});
			}
		});
	},
	
	// email quote
	email: function(quote_id,to,from,subject,message,redirect){
		var close = function(){
			if (redirect)
				location.assign(base_url + CUSTOMER_HOME_SLUG);
		};
		request.post(base_url+'quote', {action: 'email-quote', quote_id: quote_id, to: to, from: from, subject: subject, message: message}, function(r){
			r = eval(r);
			if (r.success) {
				messages.alert('Email sent.', close);
			}
		});
	},
	
	// save then call submit function
	saveAndSubmit: function(){
		quote.save(quote.submit);
	},
	
	// email a link to the view quote page to AH
	submit: function(){
		var close = function(){
			location.assign(base_url+'quotes');
		};
		request.post(base_url+'quote', {action: 'submit-quote', quote_id: quote.quote_id}, function(r){
			r = eval(r);
			if (r.success) {
				messages.alert('Quote submitted.', close);
			}
		});
	},
	
	// takes a quote and updates the quoting page with the info
	loadQuote: function(q,copy){
		q = eval(q);
		var current_surcharge = 0;
		//customer_id = quote.customer_id,
		if (!copy)
			quote.quote_id = q.quote_id;
		
		// update inputs
		$('input.markup').val(q.markup);
		$('input.freight').val(q.freight);
		$('input.tax').val(q.tax);
		$('input.misc').val(q.misc);
		$('input.discount').val(q.discount);
		$('#quote-name').val(q.name);
		$('#quote-phone').val(q.phone);
		$('#quote-po').val(q.po);
		$('#quote-est_delivery').val(q.est_delivery);
		$('#quote-notes textarea').val(q.notes);
		
		// check for change in base price
		var saved_price = ceil(markupValue(parseFloat(q.model_record.model_total), q.markup), QUOTING_DOLLAR_PRECISION);
		var model = quote.getModel(q.model_key);
		var current_price = ceil(markupValue(parseFloat(model.model_total), q.markup), QUOTING_DOLLAR_PRECISION);
		if (saved_price != current_price) {
			$('.base-price').prepend('<span class="old-price"></span>');
			quote.displayCost($('.base-price .old-price'), saved_price, false, QUOTING_DOLLAR_PRECISION);
		}
		
		// set the majority of loadQuote function to run after options are returned from the server
		var fn = function() {
			quote.switchStep(2);
			
			// select colors
			$('#base-color').val(q.base_color);
			$('#base-color').change();
			$('#overlay-color').val(q.secondary_color);
			$('#overlay-color').change();
			quote.switchStep(3);
			
			current_surcharge += parseFloat($('.brand-models .model.selected .price').data('surcharge'));
			
			// select options
			for (var i=0; i<q.options.length; i++){
				var option = q.options[i];
				
				// check to see if option still exists; if not, add
				if (!quote.getOption(option.option_id)) {
					var row = "\
					<tr id='option-"+option.option_id+"' data-id='"+option.option_id+"' class='old-option'>\
						<td>"+option.option_id+"</td>\
						<td>"+option.option_record.option+"</td>\
						<td>"+option.qty+"</td>\
						<td class='cost' data-price='"+option.price+"'><span class='price'></span></td>\
						<td class='opt-tot'>No Longer Available</td>\
						<td>\
							<span class='note'></span>\
							<img src='media/images/icon-remove.svg' alt='remove' class='remove'>\
						</td>\
					</tr>\
					";
					$('#selected-options tbody').append(row);
					quote.saveOptionNote(option.option_id, option.note);
					var cost = markupValue(option.price, q.markup);
					quote.displayCost($('#option-'+option.option_id+' .cost .price'), cost, false, QUOTING_DOLLAR_PRECISION);
					
					// notes click
					$('#selected-options #option-'+option.option_id+' .note').click(function(e){
						$('.modal-content .title span').html('<img src="'+base_url+'media/images/icon-note-white.svg" alt="">Notes');
						var noteForm = "<label for='i-note'>Add Notes:</label>\
						<div style='overflow: auto;'><textarea id='i-note' disabled='disabled'></textarea></div>\
						";
						var buttons = "\
							<button type='button' class='gray-bg cancel'>Close</button>\
						";
						$('.modal-content .content').html(noteForm);
						$('.modal-content .buttons').html(buttons);
						$('.modal-bg').fadeIn();
						// show existing note if present
						$('#i-note').val(quote.getOptionNote(option.option_id));
						
						// cancel - close modal form
						$('.modal-bg .buttons .cancel').click(function(event){
							event.preventDefault();
							$('.modal-bg').fadeOut();
						});
					});
					
					// close modal form
					$('.modal-close').click(function(event){
						event.preventDefault();
						$('.modal-bg').fadeOut(function(){
						});
					});
					
					// remove option
					$('#selected-options #option-'+option.option_id+' .remove').click(function(){
						quote.removeOption($(this).parents('tr').data('id'));
					});
				}
				// option still exists
				else {
					quote.addOption(option.option_id, i, function(i){
						var option = q.options[i];
						$('#option-'+option.option_id+' .o-qty').val(option.qty);
						//$('#option-'+q.options[i].option_id+' .o-qty').change();
						quote.saveOptionNote(option.option_id, option.note);
						
						// check to see if the price has changed and handle accordingly
						var saved_price = ceil(markupValue(parseFloat(option.option_record.price), q.markup), QUOTING_DOLLAR_PRECISION);
						var cost_el = $('#option-'+option.option_id+' .cost');
						var current_price = ceil(markupValue(parseFloat(cost_el.data('price')), q.markup), QUOTING_DOLLAR_PRECISION);
						if (saved_price != current_price) {
							cost_el.prepend('<span class="old-price"></span>');
							quote.displayCost($('#option-'+option.option_id+' .cost .old-price'), saved_price, false, QUOTING_DOLLAR_PRECISION);
						}
						current_surcharge += parseFloat($('#option-'+option.option_id+' .cost').data('surcharge')) * option.qty;
						
						// check for change in surcharge
						// doing this here because of timing/callback
						var markedup_current_surcharge = ceil(markupValue(parseFloat(current_surcharge), q.markup), QUOTING_DOLLAR_PRECISION);
						var saved_surcharge = ceil(markupValue(parseFloat(q.surcharge), q.markup), QUOTING_DOLLAR_PRECISION);
//console.log('q.surcharge: '+q.surcharge);
//console.log('saved_surcharge: '+saved_surcharge);
//console.log('current_surcharge: '+current_surcharge);
//console.log('markedup_current_surcharge: '+markedup_current_surcharge);
						$('.surcharge .old-price').remove();
						if (markedup_current_surcharge != saved_surcharge) {
							$('.surcharge').prepend('<span class="old-price"></span>');
							quote.displayCost($('.surcharge .old-price'), saved_surcharge, true, QUOTING_DOLLAR_PRECISION);
						}
					});
				}
			} // end options loop
			quote.switchStep(4);
			
			/*// check for change in surcharge
			current_surcharge += parseFloat($('.brand-models .model.selected .price').data('surcharge'));
			var saved_surcharge = ceil(markupValue(parseFloat(q.surcharge), q.markup), QUOTING_DOLLAR_PRECISION);
console.log(q.surcharge);
console.log(saved_surcharge);
console.log(typeof saved_surcharge);
console.log(current_surcharge);
console.log(typeof current_surcharge);
			$('.surcharge .old-price').remove();
			if (current_surcharge != saved_surcharge) {
console.log($('.surcharge').html());
				$('.surcharge').prepend('<span class="old-price"></span>');
console.log($('.surcharge').html());
				quote.displayCost($('.surcharge .old-price'), saved_surcharge, true, QUOTING_DOLLAR_PRECISION);
console.log($('.surcharge').html());
			}*/
		}
		
		// select model
		quote.selectModel(q.model_key, fn);
	},
	
	// display help message
	help: function() {
		messages.alert(quote.helpMessage);
	},
	optionFilterHelp: function() {
		messages.alert("Use the filters on the left to narrow down the list of options.");
	},
}

// similar to PHP's number_format()
function numberFormat(n, decPlaces, decSeparator, thouSeparator) {
	decPlaces = isNaN(decPlaces = Math.abs(decPlaces)) ? 0 : decPlaces,
	decSeparator = decSeparator == undefined ? "." : decSeparator,
	thouSeparator = thouSeparator == undefined ? "," : thouSeparator,
	sign = n < 0 ? "-" : "",
	i = parseInt(n = Math.abs(+n || 0).toFixed(decPlaces)) + "",
	j = (j = i.length) > 3 ? j % 3 : 0;
	return sign + (j ? i.substr(0, j) + thouSeparator : "") + i.substr(j).replace(/(\d{3})(?=\d)/g, "$1" + thouSeparator) + (decPlaces ? decSeparator + Math.abs(n - i).toFixed(decPlaces).slice(2) : "");
}

// return the given amount with markup added
function markupValue(price, markup) {
	price = parseFloat(price);
	markup = parseFloat(markup);
	if (QUOTING_DOLLAR_PRECISION)
		return price + price * (markup / 100);
	return Math.ceil(price + price * ( markup / 100 ));
}

function round(value, decimals) {
  return Number(Math.round(value+'e'+decimals)+'e-'+decimals);
}
function ceil(value, decimals) {
  return Number(Math.ceil(value+'e'+decimals)+'e-'+decimals);
}
function floor(value, decimals) {
  return Number(Math.floor(value+'e'+decimals)+'e-'+decimals);
}
// escapes a string for use in a regular expression
function escapeRegExp(str) {
  return str.replace(/[\-\[\]\/\{\}\(\)\*\+\?\.\\\^\$\|]/g, "\\$&");
}
