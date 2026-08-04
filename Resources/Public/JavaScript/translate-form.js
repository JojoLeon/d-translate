/**
 * d-translate: job-driven translation runs.
 *
 * The form no longer posts the whole run. It creates a job on the server (one
 * item per record × language) and then asks for one item at a time — short
 * requests that stay well inside any web server or FPM limit, with the server
 * owning the progress so a closed tab does not lose the run.
 *
 * With "process in the background" the browser only creates the job; the
 * worker command does the rest.
 */

/**
 * Points at the language field when nothing is ticked.
 *
 * Not alert(): the complaint belongs next to the field it is about, where it
 * can stay visible while the editor fixes it, instead of a modal box that has
 * to be dismissed before the checkboxes are reachable again.
 *
 * Returns false when the template has no place to put the message, so the
 * caller can still say something rather than swallow the submit.
 */
function flagMissingLanguage(form) {
    var group = form.querySelector('[data-dtranslate-languages]');
    var error = form.querySelector('[data-dtranslate-language-error]');
    if (!group || !error) {
        return false;
    }

    function boxes() {
        return Array.prototype.slice.call(group.querySelectorAll('input[name="language[]"]'));
    }

    error.hidden = false;
    boxes().forEach(function (box) { box.setAttribute('aria-invalid', 'true'); });

    // Cleared by ticking anything — the editor should not have to submit again
    // just to find out the complaint is dealt with.
    if (!group.dataset.wired) {
        group.dataset.wired = '1';
        group.addEventListener('change', function () {
            if (!group.querySelector('input[name="language[]"]:checked')) {
                return;
            }
            error.hidden = true;
            boxes().forEach(function (box) { box.removeAttribute('aria-invalid'); });
        });
    }

    var first = boxes()[0];
    if (first) {
        first.focus();
    }
    return true;
}

/**
 * Asks before a long foreground run, using the module's own dialog.
 *
 * Not window.confirm: that one is framed by the browser ("www.example.com
 * says:"), cannot be styled, and blocks the whole tab — far too loud for a
 * hint that is only about keeping this page open. Without the dialog in the
 * template (older override) the run simply starts, as it did before.
 */
function askToContinue(message, onContinue) {
    var dialog = document.querySelector('[data-dtranslate-confirm]');
    if (!dialog || typeof dialog.showModal !== 'function') {
        onContinue();
        return;
    }

    var text = dialog.querySelector('[data-dtranslate-confirm-text]');
    if (text) {
        text.textContent = message;
    }

    // Deliberately hung off the button's click, not off the dialog's "close"
    // event: closing is also what Cancel, ESC and the backdrop do, so reacting
    // to it would mean telling four exits apart. A click is the only thing that
    // may start a run — every other way out simply leaves the run unstarted.
    dialog.pendingRun = onContinue;
    if (!dialog.dataset.wired) {
        dialog.dataset.wired = '1';
        dialog.querySelector('[data-dtranslate-confirm-continue]').addEventListener('click', function () {
            var run = dialog.pendingRun;
            dialog.pendingRun = null;   // a second click cannot start the run twice
            dialog.close();
            if (run) {
                run();
            }
        });
    }

    dialog.showModal();
}

document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.matches('[data-dtranslate-form]')) {
        return;
    }
    if (!form.dataset.startUrl || !form.dataset.stepUrl) {
        return; // no endpoints → fall through to the plain submit
    }

    event.preventDefault();

    // Labels come from the form's data attributes — Fluid rendered them through
    // the language service, this file stays language-neutral.
    var t = form.dataset;
    function label(name, args) {
        var text = t[name] || '';
        (args || []).forEach(function (value) {
            text = text.replace('%s', value);
        });
        return text;
    }

    var data = new FormData(form);
    var languages = data.getAll('language[]');
    var records = data.getAll('records[]');
    if (!languages.length) {
        if (!flagMissingLanguage(form)) {
            alert(label('labelChooseLanguage'));   // template without the field marker
        }
        return;
    }

    // Page metadata plus every record, once per language.
    var units = (records.length + 1) * languages.length;

    // Only a foreground run is worth a question: that one needs this tab to
    // stay open until the last unit is done. A background run belongs to the
    // worker, so asking would just be noise.
    var background = !!data.get('background');
    var confirmAbove = parseInt(form.dataset.confirmAbove || '0', 10);

    if (!background && confirmAbove > 0 && units > confirmAbove) {
        askToContinue(label('labelEstimate', [units, languages.length]), startRun);
        return;
    }
    startRun();

    // Hoisted on purpose: the decision above is what this handler is about,
    // the machinery below is detail.
    function startRun() {
        var bar = document.getElementById('dtranslate-bar');
        var status = document.getElementById('dtranslate-status');
        var log = document.getElementById('dtranslate-log');
        var progress = document.getElementById('dtranslate-progress');
        var submitBtn = form.querySelector('button[type="submit"]');
        var reloadBtn = document.getElementById('dtranslate-reload');

        progress.style.display = 'block';
        log.innerHTML = '';
        submitBtn.disabled = true;
        submitBtn.classList.add('disabled');
        if (reloadBtn) {
            reloadBtn.style.display = 'none';
            reloadBtn.onclick = function () { window.location.reload(); };
        }

        var errors = 0;
        var stopped = false;

        function setProgress(percent) {
            bar.style.width = percent + '%';
            bar.setAttribute('aria-valuenow', percent);
        }

        function appendLog(message, isError) {
            if (!message) {
                return;
            }
            var li = document.createElement('li');
            li.textContent = message;
            if (isError) {
                li.style.color = 'var(--bs-danger, #dc3545)';
            }
            log.appendChild(li);
            log.scrollTop = log.scrollHeight;
        }

        // Every request gets its own deadline: a stuck response must not freeze the
        // whole run the way an open fetch used to.
        function post(url, body, timeoutMs) {
            var controller = new AbortController();
            var timer = setTimeout(function () { controller.abort(); }, timeoutMs);
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: body.toString(),
                signal: controller.signal,
            }).then(function (response) {
                clearTimeout(timer);
                var type = response.headers.get('content-type') || '';
                if (type.indexOf('application/json') === -1) {
                    // A PHP fatal answers with an HTML error page — reading that as
                    // JSON used to surface as "Unexpected token <".
                    return response.text().then(function (text) {
                        return {
                            ok: false,
                            finished: false,
                            message: label('labelStepFailed', [response.status]) + ' ' +
                                (extractTypo3Error(text) || label('labelSeeLog')),
                        };
                    });
                }
                return response.json();
            }).catch(function (err) {
                clearTimeout(timer);
                throw err;
            });
        }

        // First exception headline out of a TYPO3 error page, e.g.
        // "#1718500031 Dreistein\DTranslate\Exception\… : LLM request failed…".
        function extractTypo3Error(html) {
            var match = html.match(/<h[1-4][^>]*>\s*(?:\(\d+\/\d+\))?\s*(#\d+[^<]{0,300})<\/h[1-4]>/i);
            if (match) {
                return match[1].trim();
            }
            match = html.match(/<title>([^<]{0,200})<\/title>/i);
            return match ? match[1].trim() : '';
        }

        function finish(message, warn) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('disabled');
            bar.classList.remove('progress-bar-animated');
            status.textContent = message;
            if (warn) {
                bar.classList.add('bg-warning');
                if (reloadBtn) {
                    reloadBtn.style.display = '';
                }
                return;
            }
            bar.classList.remove('bg-warning');
            bar.classList.add('bg-success');
            setTimeout(function () { window.location.reload(); }, 1800);
        }

        var body = new URLSearchParams();
        body.append('pageId', data.get('pageId'));
        if (data.get('profile')) {
            body.append('profile', data.get('profile'));
        }
        languages.forEach(function (l) { body.append('language[]', l); });
        records.forEach(function (r) { body.append('records[]', r); });
        if (data.get('publishPage')) {
            body.append('publishPage', '1');
        }
        if (data.get('skipTranslated')) {
            body.append('skipTranslated', '1');
        }
        if (background) {
            body.append('background', '1');
        }

        status.textContent = label('labelProgress', [0, units, '…']);

        post(form.dataset.startUrl, body, 30000).then(function (json) {
            if (!json.ok) {
                appendLog(json.message, true);
                finish(json.message, true);
                return;
            }
            if (background) {
                appendLog(json.message, false);
                setProgress(100);
                finish(json.message, false);
                return;
            }

            appendLog(label('labelJobStarted', [json.job, json.total]), false);
            var jobId = json.job;

            // One unit at a time. Two used to be in flight — handing out items
            // atomically does stop the same record being done twice, but it does
            // not make the items independent: a container child whose container
            // is still being localized in the other request has no translated
            // parent yet, b13 drops its localize command, and the child silently
            // stays in the source language. Order between items is part of the
            // contract, so they have to run in order.
            var workers = 1;
            var running = 0;

            function pump() {
                if (stopped) {
                    return;
                }
                while (running < workers) {
                    running++;
                    step();
                }
            }

            function step() {
                var stepBody = new URLSearchParams();
                stepBody.append('job', jobId);
                post(form.dataset.stepUrl, stepBody, 600000).then(function (json) {
                    running--;
                    if (stopped) {
                        return;
                    }
                    if (json.message) {
                        appendLog(json.message, json.ok === false);
                    }
                    if (json.ok === false) {
                        errors++;
                    }
                    if (typeof json.percent === 'number') {
                        setProgress(json.percent);
                        status.textContent = label('labelJobProgress', [json.done + json.failed, json.total]);
                    }
                    if (json.fatal) {
                        stopped = true;
                        appendLog(label('labelAborted'), true);
                        finish(label('labelFinishedErrors', [errors]), true);
                        return;
                    }
                    if (json.finished) {
                        stopped = true;
                        finish(
                            errors > 0 ? label('labelFinishedErrors', [errors]) : label('labelFinished'),
                            errors > 0
                        );
                        return;
                    }
                    pump();
                }).catch(function (err) {
                    running--;
                    errors++;
                    appendLog(label('labelNetworkError', ['step', err.message]), true);
                    if (!stopped) {
                        stopped = true;
                        finish(label('labelFinishedErrors', [errors]), true);
                    }
                });
            }

            pump();
        }).catch(function (err) {
            finish(label('labelNetworkError', ['start', err.message]), true);
        });
    }
}, true);

/**
 * "Select all" / "Deselect all" per record group.
 *
 * Delegated rather than wired per group: how many groups the page renders
 * depends on what tables hold records, so there is nothing fixed to bind to.
 */
document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-dtranslate-select-all], [data-dtranslate-select-none]');
    if (!button) {
        return;
    }
    var group = button.closest('[data-dtranslate-group]');
    if (!group) {
        return;
    }
    var checked = button.hasAttribute('data-dtranslate-select-all');
    Array.prototype.slice.call(group.querySelectorAll('input[name="records[]"]')).forEach(function (box) {
        box.checked = checked;
    });
});

/**
 * Background runs keep working after the page was left; the job list polls so
 * the editor sees progress without reloading by hand.
 */
document.addEventListener('DOMContentLoaded', function () {
    var list = document.getElementById('dtranslate-jobs');
    if (!list || !list.dataset.statusUrl) {
        return;
    }
    var open = Array.prototype.slice.call(list.querySelectorAll('[data-job-open="1"]'));
    if (!open.length) {
        return;
    }
    setInterval(function () {
        open.forEach(function (row) {
            fetch(list.dataset.statusUrl + '&job=' + row.dataset.jobId, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (!json || json.ok === false) {
                        return;
                    }
                    var progress = row.querySelector('[data-job-progress]');
                    if (progress) {
                        progress.textContent = (json.done + json.failed) + ' / ' + json.total;
                    }
                    var state = row.querySelector('[data-job-state]');
                    if (state) {
                        state.textContent = json.state;
                    }
                    if (json.finished) {
                        window.location.reload();
                    }
                })
                .catch(function () { /* transient — the next tick tries again */ });
        });
    }, 5000);
});
