"use strict";
jQuery(document).ready(function($) {
	var grid = $('.grid').masonry({
		itemSelector: '.grid-item',
		columnWidth: 250,
		horizontalOrder: true,
		gutter: 10,
		fitWidth: true
	});
	grid.imagesLoaded().progress(function()
		{
			grid.masonry('layout');
		});
	$('a[data-imagelightbox]').imageLightbox({
		activity: true,
		arrows: true,
		button: true,
		gutter: 5,
		overlay: true,
		quitOnEnd: true
	});
});
