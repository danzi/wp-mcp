(function () {
	var drop = document.getElementById('empzi-drop');
	var input = document.getElementById('empzi_zip');
	var nameEl = document.getElementById('empzi-filename');
	var openBtn = document.getElementById('empzi-open-spec');
	var modal = document.getElementById('empzi-spec-modal');
	var closeBtns;

	function setName() {
		if (!nameEl || !input || !input.files || !input.files[0]) {
			if (nameEl) {
				nameEl.textContent = '';
			}
			return;
		}
		nameEl.textContent = input.files[0].name;
	}

	if (drop && input) {
		['dragenter', 'dragover'].forEach(function (eventName) {
			drop.addEventListener(eventName, function (event) {
				event.preventDefault();
				drop.classList.add('is-dragover');
			});
		});
		['dragleave', 'drop'].forEach(function (eventName) {
			drop.addEventListener(eventName, function (event) {
				event.preventDefault();
				drop.classList.remove('is-dragover');
			});
		});
		drop.addEventListener('drop', function (event) {
			var files = event.dataTransfer && event.dataTransfer.files;
			if (!files || !files.length) {
				return;
			}
			input.files = files;
			setName();
		});
		input.addEventListener('change', setName);
	}

	function openModal() {
		if (!modal) {
			return;
		}
		modal.classList.add('is-open');
		modal.setAttribute('aria-hidden', 'false');
	}

	function closeModal() {
		if (!modal) {
			return;
		}
		modal.classList.remove('is-open');
		modal.setAttribute('aria-hidden', 'true');
	}

	if (openBtn) {
		openBtn.addEventListener('click', openModal);
	}

	if (modal) {
		closeBtns = modal.querySelectorAll('[data-empzi-close]');
		Array.prototype.forEach.call(closeBtns, function (btn) {
			btn.addEventListener('click', closeModal);
		});
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				closeModal();
			}
		});
	}
})();
