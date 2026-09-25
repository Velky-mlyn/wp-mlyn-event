(function() {
    'use strict';
    async function request(op, fields) {
        var body = new URLSearchParams({
            action: 'mlyn_promo',
            nonce: mlynPromo.nonce,
            op: op
        });
        Object.keys(fields).forEach(function(key) {
            body.set(key, fields[key]);
        });
        var response = await fetch(mlynPromo.ajax, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        });
        var result;
        try {
            result = await response.json();
        } catch (e) {
            throw new Error('Server nevrátil platnou odpověď. Obnovte stránku a zkuste to znovu.');
        }
        if (!result.success) {
            throw new Error(result.data && result.data.message || 'Požadavek selhal. Obnovte stránku a zkuste to znovu.');
        }
        return result.data;
    }
    document.querySelectorAll('.mlyn-promo-picker').forEach(function(root) {
        var frame, input = root.querySelector('input'),
            thumb = root.querySelector('.mlyn-promo-thumb'),
            remove = root.querySelector('.mlyn-promo-remove');
        root.querySelector('.mlyn-promo-pick').addEventListener('click', function() {
            if (!frame) {
                frame = wp.media({
                    title: 'Vybrat obrázek pro promo banner',
                    button: {
                        text: 'Použít obrázek'
                    },
                    library: {
                        type: ['image/jpeg', 'image/png', 'image/webp']
                    },
                    multiple: false
                });
                frame.on('open', function() {
                    var selection = frame.state().get('selection');
                    selection.reset();
                    if (Number(input.value)) {
                        selection.add(wp.media.attachment(Number(input.value)));
                    }
                });
                frame.on('select', function() {
                    var selected = frame.state().get('selection').first();
                    if (!selected) {
                        return;
                    }
                    var media = selected.toJSON(),
                        image = document.createElement('img');
                    image.src = media.sizes && media.sizes.medium ? media.sizes.medium.url : media.url;
                    image.alt = media.alt || '';
                    thumb.textContent = '';
                    thumb.appendChild(image);
                    input.value = media.id;
                    remove.hidden = false;
                    input.dispatchEvent(new Event('change', {
                        bubbles: true
                    }));
                });
            }
            frame.open();
        });
        remove.addEventListener('click', function() {
            input.value = '0';
            thumb.textContent = '';
            remove.hidden = true;
            input.dispatchEvent(new Event('change', {
                bubbles: true
            }));
        });
    });
    var root = document.querySelector('.mlyn-promo-editor');
    if (root) {
        var message = root.querySelector('.mlyn-promo-message'),
            output = root.querySelector('.mlyn-promo-output'),
            warnings = root.querySelector('.mlyn-promo-warnings');
        var preview = root.querySelector('[data-promo-preview]'),
            generate = root.querySelector('[data-promo-generate]');
        var busy = false,
            changed = false,
            showingPreview = false;
        var logoList = root.querySelector('[data-logo-list]'),
            logoCache = {};
        var savedOrganizers = JSON.parse(root.dataset.organizers || '[]');
        var renderedKey = JSON.stringify(savedOrganizers),
            loadingKey = '',
            logoPending = Promise.resolve(),
            logoSerial = 0;

        function currentOrganizers() {
            var selects = document.querySelectorAll('select[data-post-type="tribe_organizer"]');
            if (!selects.length) {
                return savedOrganizers;
            }
            var ids = [];
            selects.forEach(function(select) {
                if (!select.disabled && /^\d+$/.test(select.value) && Number(select.value) > 0 && ids.indexOf(Number(select.value)) === -1) {
                    ids.push(Number(select.value));
                }
            });
            return ids;
        }

        function rememberLogos() {
            logoList.querySelectorAll('[data-organizer]').forEach(function(row) {
                logoCache[row.dataset.organizer] = {
                    show: row.querySelector('[data-logo-show]').checked,
                    height: row.querySelector('[data-logo-height]').value
                };
            });
        }

        function syncLogos() {
            var ids = currentOrganizers(),
                key = JSON.stringify(ids);
            if (key === renderedKey) {
                return Promise.resolve();
            }
            if (key === loadingKey) {
                return logoPending;
            }
            rememberLogos();
            loadingKey = key;
            var serial = ++logoSerial;
            changed = true;
            update();
            message.textContent = 'Aktualizuji loga podle aktuálních pořadatelů…';
            logoPending = request('organizers', {
                event: root.dataset.event,
                organizers: key
            }).then(function(data) {
                if (serial !== logoSerial) {
                    return syncLogos();
                }
                if (key !== JSON.stringify(currentOrganizers())) {
                    loadingKey = '';
                    return syncLogos();
                }
                rememberLogos();
                logoList.innerHTML = data.html;
                logoList.querySelectorAll('[data-organizer]').forEach(function(row) {
                    var remembered = logoCache[row.dataset.organizer];
                    if (remembered) {
                        row.querySelector('[data-logo-show]').checked = remembered.show;
                        row.querySelector('[data-logo-height]').value = remembered.height;
                    }
                });
                renderedKey = key;
                loadingKey = '';
                message.textContent = 'Loga odpovídají aktuálním pořadatelům. Náhled lze vygenerovat před uložením akce.';
            }).catch(function(error) {
                if (serial === logoSerial) {
                    loadingKey = '';
                }
                throw error;
            });
            return logoPending;
        }
        // TEC's Select2 controls can emit jQuery-only change events; read the actual
        // select values as well so adding, removing and reordering all stay in sync.
        setInterval(function() {
            if (!busy && field('mode').value === 'generated') {
                syncLogos().catch(function(e) {
                    message.textContent = e.message;
                });
            }
        }, 750);

        function field(name) {
            return root.querySelector('[name="mlyn_promo[' + name + ']"]');
        }

        function update() {
            var ready = field('mode').value === 'ready';
            root.querySelector('[data-ready-banner]').hidden = !ready;
            root.querySelector('[data-generated-fields]').hidden = ready;
            root.querySelector('[data-datetime-custom]').hidden = field('datetime_mode').value !== 'custom';
            root.querySelector('[data-datetime-auto]').hidden = field('datetime_mode').value !== 'auto';
            preview.textContent = ready ? 'Zobrazit náhled banneru' : 'Vygenerovat náhled';
            generate.hidden = ready;
            root.querySelector('[data-custom-image]').hidden = field('source').value !== 'custom';
            root.querySelector('[data-title-custom]').hidden = field('title_mode').value !== 'custom';
            root.querySelector('[data-title-auto]').hidden = field('title_mode').value !== 'auto';
            root.querySelector('[data-subtitle-custom]').hidden = field('subtitle_mode').value !== 'custom';
            root.querySelector('[data-subtitle-auto]').hidden = field('subtitle_mode').value !== 'auto';
            preview.disabled = busy || !field('enabled').checked;
            generate.disabled = busy || changed || !field('enabled').checked;
        }

        function show(data) {
            message.textContent = data.message;
            warnings.textContent = '';
            (data.warnings || []).forEach(function(text) {
                var p = document.createElement('p');
                p.textContent = text;
                warnings.appendChild(p);
            });
            output.textContent = '';
            if (data.url) {
                var image = document.createElement('img');
                image.src = data.url;
                image.alt = 'Promo banner akce';
                output.appendChild(image);
            }
        }
        root.addEventListener('input', function() {
            changed = true;
            update();
            message.textContent = 'Neuložené změny nastavení. Náhled je můžete ověřit; pro použití aktualizujte akci.';
        });
        root.addEventListener('change', function() {
            changed = true;
            update();
            message.textContent = 'Neuložené změny nastavení. Pro použití aktualizujte akci.';
        });
        async function run(op) {
            if (op === 'generate' && JSON.stringify(currentOrganizers()) !== JSON.stringify(savedOrganizers)) {
                op = 'preview';
            }
            busy = true;
            update();
            try {
                var fields = {
                    event: root.dataset.event
                };
                if (op === 'preview') {
                    if (field('mode').value === 'generated') {
                        await syncLogos();
                        if (renderedKey !== JSON.stringify(currentOrganizers())) {
                            await syncLogos();
                        }
                        fields.organizers = JSON.stringify(currentOrganizers());
                    }
                    // Use native field names, including every logo's explicit unchecked value.
                    root.querySelectorAll('[name^="mlyn_promo["]').forEach(function(input) {
                        if ((input.type === 'checkbox' || input.type === 'radio') && !input.checked) {
                            return;
                        }
                        fields[input.name.replace(/^mlyn_promo\[/, 'config[')] = input.value;
                    });
                    fields['config[enabled]'] = field('enabled').checked ? '1' : '';
                }
                message.textContent = field('mode').value === 'ready' ? 'Načítám banner…' : 'Generuji banner…';
                show(await request(op, fields));
                showingPreview = op === 'preview';
            } catch (e) {
                message.textContent = e.message;
            } finally {
                busy = false;
                update();
            }
        }

        preview.addEventListener('click', function() {
            run('preview');
        });
        generate.addEventListener('click', function() {
            run('generate');
        });
        update();
        var polls = 0;
        var timer = setInterval(async function() {
            if (++polls > 30) {
                clearInterval(timer);
                return;
            }
            if (busy || changed || showingPreview) {
                return;
            }
            try {
                var data = await request('status', {
                    event: root.dataset.event
                });
                if (!busy && !changed && !showingPreview) {
                    show(data);
                }
                if (data.status !== 'pending') {
                    clearInterval(timer);
                }
            } catch (e) {
                clearInterval(timer);
            }
        }, 4000);
    }
    var bulk = document.querySelector('.mlyn-promo-bulk');
    if (bulk) {
        var cutoff = bulk.querySelector('[data-cutoff]'),
            check = bulk.querySelector('[data-bulk-preview]'),
            apply = bulk.querySelector('[data-bulk-apply]'),
            status = bulk.querySelector('[data-bulk-message]'),
            token = '';
        cutoff.addEventListener('change', function() {
            token = '';
            apply.hidden = true;
            status.textContent = '';
        });
        check.addEventListener('click', async function() {
            check.disabled = true;
            cutoff.disabled = true;
            apply.hidden = true;
            status.textContent = 'Počítám akce…';
            try {
                var data = await request('bulk_preview', {
                    cutoff: cutoff.value
                });
                token = data.token;
                status.textContent = 'Vybráno akcí: ' + data.count + '. Potvrzením vypnete jejich promo bannery (včetně již vypnutých).';
                apply.hidden = data.count === 0;
            } catch (e) {
                status.textContent = e.message;
            } finally {
                check.disabled = false;
                cutoff.disabled = false;
            }
        });
        apply.addEventListener('click', async function() {
            if (!token) {
                return;
            }
            apply.disabled = true;
            check.disabled = true;
            cutoff.disabled = true;
            try {
                var data;
                do {
                    data = await request('bulk_apply', {
                        token: token
                    });
                    status.textContent = 'Zpracováno akcí: ' + data.done + (data.complete ? '. Hotovo.' : '… Nezavírejte tuto stránku.');
                } while (!data.complete);
                apply.hidden = true;
                token = '';
            } catch (e) {
                status.textContent = e.message + ' Již provedené změny zůstaly uložené; opakování je bezpečné.';
            } finally {
                apply.disabled = false;
                check.disabled = false;
                cutoff.disabled = false;
            }
        });
    }
}());