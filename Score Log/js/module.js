/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

(function () {
    const init = () => {
        const form = document.getElementById('scoreLogAssessment');
        if (!form) return;

        const quickFilter = document.getElementById('scoreLogQuickFilter');
        const structureSource = document.getElementById('scoreLogStructureSource');
        const reuseStructureButton = document.getElementById('scoreLogReuseStructure');
        const structureStatus = document.getElementById('scoreLogStructureStatus');
        const autosaveStatus = document.getElementById('scoreLogAutosaveStatus');
        const autosaveReload = document.getElementById('scoreLogAutosaveReload');
        const autosaveOverwrite = document.getElementById('scoreLogAutosaveOverwrite');
        const draftIDInput = form.querySelector('input[name="scoreLogDraftID"]');
        const revisionInput = form.querySelector('input[name="revision"]');
        const autosaveSequenceInput = form.querySelector('input[name="autosaveSequence"]');
        const autosaveURL = form.dataset.autosaveUrl || '';
        const commentLibraryData = window.scoreLogCommentLibrary || [];
        const reusableAssessments = window.scoreLogReusableAssessments || {};
        const scaleData = window.scoreLogScaleData || {};
        const labels = window.scoreLogImportLabels || {};
        let dirty = false;
        let changeVersion = 0;
        let autosaveTimer = null;
        let autosaveInFlight = null;
        let autosaveFlushPromise = null;
        let autosaveConflict = false;
        let autosaveConflictRevision = 0;
        let autosaveOffline = false;
        let autosaveAuthBlocked = false;
        let autosaveAuthMessage = '';
        let allowManualSubmit = false;
        let submissionPending = false;

        if (autosaveReload) {
            autosaveReload.addEventListener('click', () => {
                window.location.assign(autosaveReload.dataset.reloadUrl || window.location.href);
            });
        }

        const setAutosaveState = (state, message) => {
            if (!autosaveStatus) return;
            autosaveStatus.dataset.state = state;
            autosaveStatus.textContent = message;
            autosaveStatus.classList.remove('text-gray-600', 'text-blue-700', 'text-amber-700', 'text-red-700');
            const classes = {
                saved: 'text-gray-600',
                unsaved: 'text-gray-600',
                saving: 'text-blue-700',
                offline: 'text-amber-700',
                conflict: 'text-red-700',
                auth: 'text-red-700',
                failed: 'text-red-700',
            };
            autosaveStatus.classList.add(classes[state] || 'text-gray-600');
            if (autosaveReload) autosaveReload.classList.toggle('hidden', !['conflict', 'auth'].includes(state));
            if (autosaveOverwrite) {
                autosaveOverwrite.classList.toggle(
                    'hidden',
                    state !== 'conflict' || autosaveConflictRevision < 1
                );
            }
        };

        const updateReloadLink = () => {
            if (!autosaveReload) return;
            const draftID = draftIDInput ? draftIDInput.value : '';
            if (draftID === '') {
                autosaveReload.dataset.reloadUrl = window.location.href;
                return;
            }
            const url = new URL(window.location.href);
            url.searchParams.set('draftID', draftID);
            autosaveReload.dataset.reloadUrl = url.toString();
        };

        const setDraftIdentity = (draftID, revision) => {
            if (draftIDInput && draftID !== '') draftIDInput.value = String(draftID);
            if (revisionInput && revision !== undefined && revision !== null) revisionInput.value = String(revision);
            updateReloadLink();
            if (draftIDInput && draftIDInput.value !== '' && window.history && window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.set('draftID', draftIDInput.value);
                window.history.replaceState(window.history.state, '', url.toString());
            }
        };

        const scheduleAutosave = (delay = 1800) => {
            if (autosaveConflict || autosaveURL === '') return;
            if (autosaveOffline) {
                setAutosaveState('offline', labels.autosaveOffline || 'Offline — changes are not saved.');
                return;
            }
            if (autosaveAuthBlocked) {
                setAutosaveState('auth', autosaveAuthMessage);
                return;
            }
            if (window.navigator && window.navigator.onLine === false) {
                autosaveOffline = true;
                setAutosaveState('offline', labels.autosaveOffline || 'Offline — changes are not saved.');
                return;
            }
            if (autosaveTimer) window.clearTimeout(autosaveTimer);
            autosaveTimer = window.setTimeout(() => {
                autosaveTimer = null;
                flushAutosave();
            }, delay);
        };

        const markDirty = () => {
            dirty = true;
            allowManualSubmit = false;
            submissionPending = false;
            changeVersion++;
            if (autosaveSequenceInput) autosaveSequenceInput.value = String(changeVersion);
            if (autosaveConflict) {
                setAutosaveState('conflict', labels.autosaveConflict || 'Conflict — another tab saved a newer revision. Reload the latest draft.');
                return;
            }
            if (autosaveOffline) {
                setAutosaveState('offline', labels.autosaveOffline || 'Offline — changes are not saved.');
                return;
            }
            if (autosaveAuthBlocked) {
                setAutosaveState('auth', autosaveAuthMessage);
                return;
            }
            setAutosaveState('unsaved', labels.autosaveUnsaved || 'Unsaved changes');
            scheduleAutosave();
        };

        const performAutosave = async () => {
            if (autosaveConflict || autosaveURL === '') return false;
            if (autosaveTimer) {
                window.clearTimeout(autosaveTimer);
                autosaveTimer = null;
            }

            const savedVersion = changeVersion;
            setAutosaveState('saving', labels.autosaveSaving || 'Saving...');
            const body = new FormData(form);
            body.delete('saveDraft');
            body.delete('reviewPublish');

            let response;
            try {
                response = await fetch(autosaveURL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                    body,
                });
            } catch (error) {
                autosaveOffline = true;
                setAutosaveState('offline', labels.autosaveOffline || 'Offline — changes are not saved.');
                return false;
            }

            autosaveOffline = false;
            const hasContentType = response.headers && typeof response.headers.get === 'function';
            const contentType = hasContentType
                ? String(response.headers.get('content-type') || '').toLowerCase()
                : '';
            if (response.redirected || (response.ok && hasContentType && !contentType.includes('application/json'))) {
                autosaveAuthBlocked = true;
                autosaveAuthMessage = labels.autosaveSessionExpired || 'Session expired. Reload the page and sign in again.';
                updateReloadLink();
                setAutosaveState('auth', autosaveAuthMessage);
                return false;
            }
            let data = {};
            try {
                data = await response.json();
            } catch (error) {
                data = {};
            }
            if (response.status === 409 || data.status === 'conflict') {
                autosaveConflict = true;
                autosaveConflictRevision = Math.max(0, Number(data.revision || 0));
                if (autosaveTimer) {
                    window.clearTimeout(autosaveTimer);
                    autosaveTimer = null;
                }
                updateReloadLink();
                setAutosaveState('conflict', labels.autosaveConflict || 'Conflict — another tab saved a newer revision. Reload the latest draft.');
                return false;
            }
            if (response.status === 401 || response.status === 419) {
                autosaveAuthBlocked = true;
                autosaveAuthMessage = labels.autosaveSessionExpired || 'Session expired. Reload the page and sign in again.';
                updateReloadLink();
                setAutosaveState('auth', autosaveAuthMessage);
                return false;
            }
            if (response.status === 403) {
                autosaveAuthBlocked = true;
                autosaveAuthMessage = labels.autosaveForbidden || 'Autosave stopped: permission denied.';
                updateReloadLink();
                setAutosaveState('auth', autosaveAuthMessage);
                return false;
            }
            if (!response.ok || data.status !== 'saved') {
                setAutosaveState('failed', labels.autosaveFailed || 'Autosave failed. Your changes remain unsaved.');
                if (dirty && !autosaveConflict && !autosaveAuthBlocked) scheduleAutosave(5000);
                return false;
            }

            autosaveConflictRevision = 0;
            setDraftIdentity(data.draftID || '', data.revision);
            if (changeVersion === savedVersion) dirty = false;
            if (dirty) {
                setAutosaveState('unsaved', labels.autosaveUnsaved || 'Unsaved changes');
                scheduleAutosave(0);
            } else {
                setAutosaveState(
                    'saved',
                    (labels.autosaveSaved || 'Saved') + ' · ' + (labels.autosaveRevision || 'revision') + ' ' + data.revision
                );
            }
            return true;
        };

        const runAutosaveFlush = async () => {
            if (autosaveConflict || autosaveAuthBlocked) return false;
            if (autosaveInFlight) {
                const priorResult = await autosaveInFlight;
                if (!priorResult) return false;
            }

            while (dirty) {
                if (autosaveTimer) {
                    window.clearTimeout(autosaveTimer);
                    autosaveTimer = null;
                }
                autosaveInFlight = performAutosave();
                let result = false;
                try {
                    result = await autosaveInFlight;
                } finally {
                    autosaveInFlight = null;
                }
                if (!result) return false;
            }

            return true;
        };

        const flushAutosave = async () => {
            if (autosaveConflict || autosaveAuthBlocked) return false;
            if (autosaveFlushPromise) return autosaveFlushPromise;
            autosaveFlushPromise = runAutosaveFlush();
            try {
                return await autosaveFlushPromise;
            } finally {
                autosaveFlushPromise = null;
            }
        };

        const overwriteLatest = async (event) => {
            event.preventDefault();
            if (!autosaveConflict || autosaveConflictRevision < 1 || !draftIDInput || draftIDInput.value === '') return;

            setDraftIdentity(draftIDInput.value, autosaveConflictRevision);
            autosaveConflict = false;
            dirty = true;
            if (autosaveInFlight) {
                await autosaveInFlight;
            }
            autosaveInFlight = performAutosave();
            try {
                await autosaveInFlight;
            } finally {
                autosaveInFlight = null;
            }
        };
        if (autosaveOverwrite) autosaveOverwrite.addEventListener('click', overwriteLatest);

        const scoreRows = () => Array.from(form.querySelectorAll('tr[data-student-id]'));
        const scoreInputFor = (row) => row ? row.querySelector('input.scoreLogScoreInput') : null;
        const isPersistedField = (field) => {
            const name = field && field.name ? field.name : '';
            return name.startsWith('columns[') || name.startsWith('scores[');
        };

        const numericValue = (value) => {
            const normalized = String(value || '').trim().replace(/%$/, '');
            if (!/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/.test(normalized)) return null;
            const number = Number(normalized);
            if (!Number.isFinite(number)) return null;
            const rounded = Number(number.toFixed(4));
            const canonical = rounded === 0 ? '0' : String(rounded);
            return canonical.length <= 10 ? rounded : null;
        };

        const scoreStatus = (input) => {
            const value = input ? String(input.value || '').trim() : '';
            if (value === '') return 'missing';

            const grades = scaleData.grades || {};
            if (scaleData.numeric === 'Y') {
                const number = numericValue(value);
                if (number === null || number < 0) return 'invalid';
                if (scaleData.max !== null && scaleData.max !== undefined && number > Number(scaleData.max)) {
                    return 'invalid';
                }
                return Object.keys(grades).some((grade) => numericValue(grade) === number) ? 'entered' : 'invalid';
            }

            return Object.prototype.hasOwnProperty.call(grades, value) ? 'entered' : 'invalid';
        };

        const gradeForValue = (value) => {
            value = String(value || '').trim();
            if (value === '') return null;
            const grades = scaleData.grades || {};
            if (scaleData.numeric === 'Y') {
                const number = numericValue(value);
                if (number === null) return null;
                const grade = Object.keys(grades).find((candidate) => numericValue(candidate) === number);
                return grade === undefined ? null : {value: grade, descriptor: String(grades[grade] || '')};
            }

            return Object.prototype.hasOwnProperty.call(grades, value)
                ? {value, descriptor: String(grades[value] || '')}
                : null;
        };

        const syncAutoComment = (scoreInput) => {
            const row = scoreInput ? scoreInput.closest('tr[data-student-id]') : null;
            const textarea = row ? row.querySelector('textarea') : null;
            if (!textarea) return;

            const previousAuto = textarea.dataset.autoComment || '';
            const grade = gradeForValue(scoreInput.value);
            const nextAuto = grade ? grade.descriptor : '';
            if (textarea.value.trim() === '' || textarea.value === previousAuto) {
                textarea.value = nextAuto;
            }
            textarea.dataset.autoComment = nextAuto;
        };

        const updateRowStatus = (row) => {
            const input = scoreInputFor(row);
            const status = scoreStatus(input);
            row.dataset.scoreStatus = status;
            return status;
        };

        const updateRowSummary = () => {
            let entered = 0;
            let missing = 0;
            let invalid = 0;
            scoreRows().forEach((row) => {
                const status = updateRowStatus(row);
                if (status === 'entered') entered++;
                else if (status === 'missing') missing++;
                else invalid++;
            });

            const summary = document.getElementById('scoreLogRowSummary');
            if (!summary) return;
            summary.textContent = (labels.rowSummary || '%s entered · %s missing · %s invalid')
                .replace('%s', String(entered))
                .replace('%s', String(missing))
                .replace('%s', String(invalid));
        };

        const applyQuickFilter = () => {
            const filter = quickFilter ? quickFilter.value : 'all';
            scoreRows().forEach((row) => {
                const status = updateRowStatus(row);
                row.hidden = (filter === 'missing' && status !== 'missing')
                    || (filter === 'invalid' && status !== 'invalid');
            });
        };

        const focusNextVisibleScore = () => {
            const visibleRows = scoreRows().filter((row) => !row.hidden && scoreInputFor(row));
            const focusedInput = document.activeElement && document.activeElement.closest
                ? document.activeElement.closest('.scoreLogScoreInput')
                : null;
            const currentIndex = focusedInput ? visibleRows.indexOf(focusedInput.closest('tr[data-student-id]')) : -1;
            if (currentIndex >= 0 && currentIndex + 1 < visibleRows.length) {
                const next = scoreInputFor(visibleRows[currentIndex + 1]);
                next.focus();
                next.select();
                return;
            }

            const firstMissing = visibleRows.find((row) => scoreStatus(scoreInputFor(row)) === 'missing');
            if (firstMissing) {
                const input = scoreInputFor(firstMissing);
                input.focus();
                input.select();
            }
        };

        const commentForID = (commentID) => commentLibraryData
            .find((comment) => String(comment.scoreLogCommentID || '') === String(commentID));

        const insertLibraryComment = (button) => {
            const cell = button.closest('td');
            const select = cell ? cell.querySelector('.scoreLogCommentLibrarySelect') : null;
            const textarea = cell ? cell.querySelector('textarea') : null;
            const comment = select ? commentForID(select.value) : null;
            if (!textarea || !comment || !comment.comment) return;

            let existing = textarea.value;
            if (existing.trim() === '') existing = '';
            const separator = existing !== '' && !existing.endsWith('\n') ? '\n' : '';
            textarea.value = existing + separator + comment.comment;
            const cursor = textarea.value.length;
            textarea.dispatchEvent(new Event('input', {bubbles: true}));
            textarea.focus();
            textarea.setSelectionRange(cursor, cursor);
        };

        const setFieldValue = (suffix, value) => {
            const field = form.querySelector('[name$="'+suffix+'"]');
            if (field && value !== undefined && value !== null) field.value = value;
        };

        const setToggleValue = (suffix, value) => {
            form.querySelectorAll('[name$="'+suffix+'"]').forEach((field) => {
                field.checked = field.value === value;
            });
        };

        const copyStructure = () => {
            const assessment = structureSource ? reusableAssessments[structureSource.value] : null;
            if (!assessment) {
                if (structureStatus) {
                    structureStatus.textContent = labels.structureRequired || 'Choose an assessment structure to reuse.';
                }
                return;
            }

            setFieldValue('[name]', assessment.name || '');
            setFieldValue('[description]', assessment.description || '');
            setFieldValue('[type]', assessment.type || '');
            setFieldValue('[completeDate]', assessment.completeDate || '');
            setToggleValue('[viewableStudents]', assessment.viewableStudents || 'Y');
            setToggleValue('[viewableParents]', assessment.viewableParents || 'Y');
            markDirty();
            if (structureStatus) {
                structureStatus.textContent = labels.structureAdded || 'Assessment details copied without scores or comments.';
            }
        };

        form.addEventListener('click', (event) => {
            const commentButton = event.target.closest('.scoreLogCommentLibraryInsert');
            if (commentButton) {
                insertLibraryComment(commentButton);
            }
        });

        form.addEventListener('input', (event) => {
            if (!isPersistedField(event.target)) return;
            markDirty();
            if (event.target.classList.contains('scoreLogScoreInput')) {
                const row = event.target.closest('tr[data-student-id]');
                if (row) updateRowStatus(row);
                syncAutoComment(event.target);
                updateRowSummary();
            }
        });

        form.addEventListener('change', (event) => {
            if (event.target === quickFilter) {
                applyQuickFilter();
                return;
            }
            if (event.target.classList.contains('scoreLogScoreInput')) {
                markDirty();
                const row = event.target.closest('tr[data-student-id]');
                if (row) updateRowStatus(row);
                syncAutoComment(event.target);
                updateRowSummary();
                applyQuickFilter();
            }
            if (isPersistedField(event.target)) markDirty();
        });

        form.addEventListener('keydown', (event) => {
            if (
                event.key !== 'Enter'
                || event.shiftKey
                || event.altKey
                || event.ctrlKey
                || event.metaKey
                || event.isComposing
            ) return;

            if (event.target.matches('textarea, button, input[type="submit"], input[type="button"]')) return;
            if (!event.target.matches('input')) return;
            event.preventDefault();
            if (event.target.classList.contains('scoreLogScoreInput')) {
                focusNextVisibleScore();
            }
        });

        if (reuseStructureButton) reuseStructureButton.addEventListener('click', copyStructure);

        form.addEventListener('submit', async (event) => {
            if (allowManualSubmit) {
                allowManualSubmit = false;
                submissionPending = false;
                dirty = false;
                return;
            }

            event.preventDefault();
            if (submissionPending) return;
            submissionPending = true;
            const submitter = event.submitter || null;
            if (!await flushAutosave()) {
                submissionPending = false;
                return;
            }
            allowManualSubmit = true;
            dirty = false;
            if (submitter && typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter);
            } else {
                form.submit();
            }
        });
        window.addEventListener('online', () => {
            autosaveOffline = false;
            if (dirty && !autosaveConflict) scheduleAutosave(0);
        });
        window.addEventListener('offline', () => {
            autosaveOffline = true;
            if (autosaveTimer) {
                window.clearTimeout(autosaveTimer);
                autosaveTimer = null;
            }
            setAutosaveState('offline', labels.autosaveOffline || 'Offline — changes are not saved.');
        });
        window.addEventListener('beforeunload', (event) => {
            if (!dirty) return;
            event.preventDefault();
            event.returnValue = labels.unsavedChanges || '';
        });

        updateRowSummary();
        applyQuickFilter();
        updateReloadLink();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once: true});
    } else {
        init();
    }
})();

// Export the current on-screen values (including unsaved edits) without navigating away.
window.scoreLogExportCurrent = function () {
    var form = document.getElementById('scoreLogAssessment');
    if (!form) {
        return false;
    }
    var data = new FormData(form);
    data.set('exportCurrent', '1');
    fetch('/modules/Score Log/scorelog_draftProcess.php', {
        method: 'POST',
        body: data,
        credentials: 'same-origin'
    }).then(function (response) {
        if (!response.ok) {
            throw new Error('export failed');
        }
        return response.blob();
    }).then(function (blob) {
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'score-log-scores.xlsx';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }).catch(function () {
        alert('导出失败，请重试。');
    });
    return false;
};
