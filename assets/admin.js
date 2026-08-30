(function () {
	'use strict';

	const control = document.getElementById('mlyn-event-image-focal-point-control');
	if (!control || !window.wp || !wp.element || !wp.components || !wp.components.FocalPointPicker) return;

	const createElement = wp.element.createElement;
	const useEffect = wp.element.useEffect;
	const useState = wp.element.useState;
	const FocalPointPicker = wp.components.FocalPointPicker;
	const Button = wp.components.Button;
	const xInput = control.querySelector('input[name="mlyn_event_image_focal_x"]');
	const yInput = control.querySelector('input[name="mlyn_event_image_focal_y"]');
	const initialImageId = Number(control.dataset.imageId || 0);
	const initialUrl = control.dataset.imageUrl || '';
	const initialPoint = {
		x: Math.min(1, Math.max(0, Number(control.dataset.x || 50) / 100)),
		y: Math.min(1, Math.max(0, Number(control.dataset.y || 50) / 100))
	};

	function featuredImageId() {
		const input = document.querySelector('#postimagediv input[name="_thumbnail_id"], input#_thumbnail_id');
		return input ? Number(input.value || 0) : 0;
	}

	function attachmentUrl(id) {
		if (!id || !wp.media) return Promise.resolve('');
		const attachment = wp.media.attachment(id);
		return attachment.fetch().then(function () {
			const data = attachment.toJSON();
			return (data.sizes && data.sizes.large ? data.sizes.large.url : data.url) || '';
		});
	}

	function App() {
		const [image, setImage] = useState({ id: initialImageId, url: initialUrl });
		const [point, setPoint] = useState(initialPoint);

		function updatePoint(next) {
			const normalized = {
				x: Math.min(1, Math.max(0, Number(next.x))),
				y: Math.min(1, Math.max(0, Number(next.y)))
			};
			setPoint(normalized);
			xInput.value = String(Math.round(normalized.x * 100));
			yInput.value = String(Math.round(normalized.y * 100));
		}

		useEffect(function () {
			const featuredBox = document.getElementById('postimagediv');
			if (!featuredBox || !window.MutationObserver) return undefined;
			let observedId = featuredImageId();
			const observer = new MutationObserver(function () {
				const nextId = featuredImageId();
				if (nextId === observedId) return;
				observedId = nextId;
				xInput.value = nextId ? '50' : '';
				yInput.value = nextId ? '50' : '';
				setPoint({ x: 0.5, y: 0.5 });
				attachmentUrl(nextId).then(function (url) { setImage({ id: nextId, url: url }); });
			});
			observer.observe(featuredBox, { childList: true, subtree: true, attributes: true });
			return function () { observer.disconnect(); };
		}, []);

		if (!image.id || !image.url) {
			return createElement('p', { className: 'description' }, mlynEventAdmin.noImage);
		}

		return createElement(
			wp.element.Fragment,
			null,
			createElement(FocalPointPicker, { url: image.url, value: point, onChange: updatePoint, onDrag: updatePoint }),
			createElement('p', { className: 'mlyn-event-focal-preview-label' }, mlynEventAdmin.previewLabel),
			createElement('div', {
				className: 'mlyn-event-focal-preview',
				style: { backgroundImage: 'url("' + image.url.replace(/"/g, '%22') + '")', backgroundPosition: (point.x * 100) + '% ' + (point.y * 100) + '%' }
			}),
			createElement(Button, { variant: 'secondary', onClick: function () { updatePoint({ x: 0.5, y: 0.5 }); } }, mlynEventAdmin.reset)
		);
	}

	const rootNode = control.querySelector('.mlyn-event-focal-point-root');
	if (wp.element.createRoot) wp.element.createRoot(rootNode).render(createElement(App));
	else wp.element.render(createElement(App), rootNode);
}());
