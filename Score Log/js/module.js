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

        const onlyMissingToggle = document.getElementById('scoreLogOnlyMissing');
        const nextMissingButton = document.getElementById('scoreLogNextMissing');
        const focusStatus = document.getElementById('scoreLogFocusStatus');
        const bulkValueInput = document.getElementById('scoreLogBulkValue');
        const bulkOverwriteInput = document.getElementById('scoreLogBulkOverwrite');
        const bulkFillButton = document.getElementById('scoreLogBulkFill');
        const bulkClearButton = document.getElementById('scoreLogBulkClear');
        const bulkPreview = document.getElementById('scoreLogBulkPreview');
        const bulkUndoButton = document.getElementById('scoreLogBulkUndo');
        const structureSource = document.getElementById('scoreLogStructureSource');
        const reuseStructureButton = document.getElementById('scoreLogReuseStructure');
        const selectAll = document.getElementById('scoreLogSelectAll');
        const autosaveStatus = document.getElementById('scoreLogAutosaveStatus');
        const autosaveReload = document.getElementById('scoreLogAutosaveReload');
        const draftIDInput = form.querySelector('input[name="scoreLogDraftID"]');
        const revisionInput = form.querySelector('input[name="revision"]');
        const autosaveSequenceInput = form.querySelector('input[name="autosaveSequence"]');
        const autosaveURL = form.dataset.autosaveUrl || '';
        const commentLibraryData = window.scoreLogCommentLibrary || [];
        const reusableAssessments = window.scoreLogReusableAssessments || {};
        const labels = window.scoreLogImportLabels || {};
        let dirty = false;
        let bulkUndo = null;
        let changeVersion = 0;
        let autosaveTimer = null;
        let autosaveInFlight = null;
        let autosaveConflict = false;
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
            if (autosaveReload) autosaveReload.hidden = !['conflict', 'auth'].includes(state);
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

        const flushAutosave = async () => {
            if (autosaveConflict || autosaveAuthBlocked) return false;
            if (autosaveInFlight) {
                const priorResult = await autosaveInFlight;
                if (!priorResult) return false;
            }
            if (!dirty) return true;
            autosaveInFlight = performAutosave();
            try {
                return await autosaveInFlight;
            } finally {
                autosaveInFlight = null;
            }
        };

        const scoreRows = () => Array.from(form.querySelectorAll('tr[data-student-id]'));
        const scoreSelectFor = (row) => row ? row.querySelector('.scoreLogScoreSelect') : null;
        const isPersistedField = (field) => {
            const name = field && field.name ? field.name : '';
            return name.startsWith('columns[') || name.startsWith('scores[');
        };
        const checkedScoreRows = () => scoreRows().filter((row) => {
            const checkbox = row.querySelector('.scoreLogStudentSelect');
            return !row.hidden && checkbox && checkbox.checked;
        });

        const setFocusStatus = (message) => {
            if (focusStatus) focusStatus.textContent = message || '';
        };

        const clearStudentSelections = () => {
            scoreRows().forEach((row) => {
                const checkbox = row.querySelector('.scoreLogStudentSelect');
                if (checkbox) checkbox.checked = false;
            });
            if (selectAll) selectAll.checked = false;
            updateBulkPreview();
        };

        const updateCounts = () => {
            let entered = 0;
            let missing = 0;
            let invalid = 0;
            scoreRows().forEach((row) => {
                const select = scoreSelectFor(row);
                if (!select) return;
                if (select.value === '') missing++;
                else if (select.value !== (select.dataset.invalidValue || '')) entered++;
                else invalid++;
            });

            const enteredCount = document.getElementById('scoreLogEnteredCount');
            const missingCount = document.getElementById('scoreLogMissingCount');
            const invalidCount = document.getElementById('scoreLogInvalidCount');
            if (enteredCount) enteredCount.textContent = String(entered);
            if (missingCount) missingCount.textContent = String(missing);
            if (invalidCount) invalidCount.textContent = String(invalid);
        };

        const applyOnlyMissingView = () => {
            const onlyMissing = !!(onlyMissingToggle && onlyMissingToggle.checked);
            scoreRows().forEach((row) => {
                const select = scoreSelectFor(row);
                row.hidden = onlyMissing && select && select.value !== '';
                if (row.hidden) {
                    const checkbox = row.querySelector('.scoreLogStudentSelect');
                    if (checkbox) checkbox.checked = false;
                }
            });
            updateBulkPreview();
        };

        const updateUndoButton = () => {
            if (!bulkUndoButton) return;
            bulkUndoButton.hidden = !bulkUndo;
            bulkUndoButton.disabled = false;
            if (bulkUndo) {
                bulkUndoButton.textContent = (labels.undoBulk || 'Undo Bulk Change')
                    + ' (' + bulkUndo.changes.length + ')';
            }
        };

        const invalidateUndoForRow = (studentID) => {
            if (!bulkUndo || !bulkUndo.changes.some((change) => change.studentID === studentID)) return;
            bulkUndo = null;
            updateUndoButton();
            setFocusStatus(labels.undoInvalidated || 'A score changed after the bulk action; undo was cleared.');
        };

        const updateBulkPreview = () => {
            if (!bulkPreview || !bulkFillButton || !bulkClearButton) return;
            const selectedRows = checkedScoreRows();
            const value = bulkValueInput ? bulkValueInput.value : '';
            const overwrite = !!(bulkOverwriteInput && bulkOverwriteInput.checked);
            let blankFillChanges = 0;
            let overwriteChanges = 0;
            let clearChanges = 0;

            selectedRows.forEach((row) => {
                const select = scoreSelectFor(row);
                const current = select ? select.value : '';
                if (value !== '' && current === '') blankFillChanges++;
                if (value !== '' && current !== '' && current !== value) overwriteChanges++;
                if (current !== '') clearChanges++;
            });
            const fillChanges = blankFillChanges + (overwrite ? overwriteChanges : 0);

            bulkFillButton.disabled = selectedRows.length === 0 || value === '' || fillChanges === 0;
            bulkClearButton.disabled = selectedRows.length === 0 || clearChanges === 0;
            if (selectedRows.length === 0) {
                bulkPreview.textContent = labels.selectStudentsFirst || 'Select students to fill or clear.';
                return;
            }

            bulkPreview.textContent = [
                (labels.selected || 'Selected') + ' ' + selectedRows.length,
                (labels.fillBlanks || 'Fill blanks') + ' ' + blankFillChanges,
                (labels.overwriteWillChange || 'Overwrite will change') + ' ' + overwriteChanges,
                (labels.clearWillChange || 'Clear will change') + ' ' + clearChanges,
            ].join(' · ');
        };

        const focusNextMissing = () => {
            const focusedSelect = document.activeElement && document.activeElement.closest
                ? document.activeElement.closest('.scoreLogScoreSelect')
                : null;
            const rows = scoreRows();
            const currentRow = focusedSelect ? focusedSelect.closest('tr') : null;
            const currentIndex = currentRow ? rows.indexOf(currentRow) : -1;
            const candidates = rows.filter((row) => {
                const select = scoreSelectFor(row);
                return select && !select.disabled && select.value === '';
            });
            const afterCurrent = candidates.find((row) => rows.indexOf(row) > currentIndex);
            const nextRow = afterCurrent || candidates[0];

            if (!nextRow) {
                setFocusStatus(labels.noMissing || 'No more missing scores.');
                return;
            }

            setFocusStatus('');
            const nextSelect = scoreSelectFor(nextRow);
            nextSelect.focus();
        };

        const applyBulkChange = (mode) => {
            const fillValue = bulkValueInput ? bulkValueInput.value : '';
            const overwrite = !!(bulkOverwriteInput && bulkOverwriteInput.checked);
            if (mode === 'fill' && fillValue === '') {
                setFocusStatus(labels.bulkValueInvalid || 'Choose a score before filling.');
                updateBulkPreview();
                return;
            }

            const changes = [];
            checkedScoreRows().forEach((row) => {
                const select = scoreSelectFor(row);
                if (!select) return;
                const previous = select.value;
                const next = mode === 'fill' ? fillValue : '';
                if (mode === 'fill' && previous !== '' && !overwrite) return;
                if (previous === next) return;
                changes.push({
                    studentID: row.getAttribute('data-student-id'),
                    previous,
                    next,
                    overwritten: mode === 'fill' && previous !== '',
                });
            });

            if (changes.length === 0) {
                setFocusStatus(labels.noBulkChanges || 'The checked students already have these values.');
                updateBulkPreview();
                return;
            }

            if (mode === 'clear' && !window.confirm(
                (labels.confirmBulkClear || 'Clear %s existing scores?').replace('%s', changes.length)
            )) {
                return;
            }
            const overwritten = changes.filter((change) => change.overwritten).length;
            if (mode === 'fill' && overwritten > 0 && !window.confirm(
                (labels.confirmBulkOverwrite || 'Overwrite %s existing scores?').replace('%s', overwritten)
            )) {
                return;
            }

            changes.forEach((change) => {
                const row = scoreRows().find((candidate) => candidate.getAttribute('data-student-id') === change.studentID);
                const select = scoreSelectFor(row);
                if (select) select.value = change.next;
            });
            bulkUndo = {
                revision: revisionInput ? String(revisionInput.value) : '',
                changes,
            };
            markDirty();
            updateCounts();
            applyOnlyMissingView();
            updateUndoButton();
            updateBulkPreview();
            setFocusStatus((labels.bulkChanged || 'Score values changed:') + ' ' + changes.length);
        };

        const undoBulkChange = () => {
            if (!bulkUndo) return;
            if (!revisionInput || String(revisionInput.value) !== bulkUndo.revision) {
                bulkUndo = null;
                updateUndoButton();
                setFocusStatus(labels.undoUnavailable || 'Draft revision changed; undo is no longer available.');
                return;
            }

            bulkUndo.changes.forEach((change) => {
                const row = scoreRows().find((candidate) => candidate.getAttribute('data-student-id') === change.studentID);
                const select = scoreSelectFor(row);
                if (select) select.value = change.previous;
            });
            bulkUndo = null;
            markDirty();
            updateCounts();
            applyOnlyMissingView();
            updateUndoButton();
            updateBulkPreview();
            setFocusStatus(labels.undoComplete || 'Bulk change undone.');
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
                setFocusStatus(labels.structureRequired || 'Choose an assessment structure to reuse.');
                return;
            }

            setFieldValue('[name]', assessment.name || '');
            setFieldValue('[description]', assessment.description || '');
            setFieldValue('[type]', assessment.type || '');
            setFieldValue('[completeDate]', assessment.completeDate || '');
            setToggleValue('[viewableStudents]', assessment.viewableStudents || 'Y');
            setToggleValue('[viewableParents]', assessment.viewableParents || 'Y');
            markDirty();
            setFocusStatus(labels.structureAdded || 'Assessment details copied.');
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
            if (event.target.classList.contains('scoreLogScoreSelect')) {
                const row = event.target.closest('tr[data-student-id]');
                if (row) invalidateUndoForRow(row.getAttribute('data-student-id'));
                updateCounts();
                updateBulkPreview();
            }
        });

        form.addEventListener('change', (event) => {
            if (event.target === onlyMissingToggle) {
                applyOnlyMissingView();
                return;
            }
            if (event.target === bulkValueInput || event.target === bulkOverwriteInput) {
                updateBulkPreview();
                return;
            }
            if (event.target === selectAll) {
                const checked = event.target.checked;
                scoreRows().forEach((row) => {
                    const checkbox = row.querySelector('.scoreLogStudentSelect');
                    if (checkbox && !row.hidden) checkbox.checked = checked;
                });
                updateBulkPreview();
                return;
            }
            if (event.target.classList.contains('scoreLogStudentSelect')) {
                updateBulkPreview();
                return;
            }
            if (event.target.classList.contains('scoreLogScoreSelect')) {
                markDirty();
                const row = event.target.closest('tr[data-student-id]');
                if (row) invalidateUndoForRow(row.getAttribute('data-student-id'));
                updateCounts();
                updateBulkPreview();
                if (onlyMissingToggle && onlyMissingToggle.checked) applyOnlyMissingView();
            }
            if (isPersistedField(event.target)) markDirty();
        });

        form.addEventListener('keydown', (event) => {
            if (event.altKey && event.key.toLowerCase() === 'n') {
                event.preventDefault();
                focusNextMissing();
            }
        });

        if (nextMissingButton) nextMissingButton.addEventListener('click', focusNextMissing);
        if (bulkFillButton) bulkFillButton.addEventListener('click', () => applyBulkChange('fill'));
        if (bulkClearButton) bulkClearButton.addEventListener('click', () => applyBulkChange('clear'));
        if (bulkUndoButton) bulkUndoButton.addEventListener('click', undoBulkChange);
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

        updateCounts();
        applyOnlyMissingView();
        updateBulkPreview();
        updateReloadLink();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once: true});
    } else {
        init();
    }
})();
