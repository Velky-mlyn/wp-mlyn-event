(function () {
	'use strict';
	var root = document.getElementById('mlyn-organizer-logo-control');
	if (!root || !window.wp || !wp.media) { return; }
	var input = root.querySelector('input');
	var preview = root.querySelector('.mlyn-organizer-logo-preview');
	var remove = root.querySelector('.mlyn-organizer-logo-remove');
	var frame;

	root.querySelector('.mlyn-organizer-logo-select').addEventListener('click', function () {
		if (!frame) {
			frame = wp.media({ title: mlynOrganizerLogo.title, button: { text: mlynOrganizerLogo.button }, library: { type: 'image' }, multiple: false });
			frame.on('open', function () {
				var selection = frame.state().get('selection');
				selection.reset();
				if (Number(input.value)) { selection.add(wp.media.attachment(Number(input.value))); }
			});
			frame.on('select', function () {
				var selected = frame.state().get('selection').first();
				if (!selected) { return; }
				var media = selected.toJSON();
				var img = document.createElement('img');
				img.src = media.sizes && media.sizes.medium ? media.sizes.medium.url : media.url;
				img.alt = media.alt || '';
				img.style.cssText = 'max-width:100%;height:auto;display:block';
				preview.textContent = '';
				preview.appendChild(img);
				input.value = media.id;
				preview.hidden = false;
				remove.hidden = false;
			});
		}
		frame.open();
	});

	remove.addEventListener('click', function () {
		input.value = '0';
		preview.textContent = '';
		preview.hidden = true;
		remove.hidden = true;
	});
}());
