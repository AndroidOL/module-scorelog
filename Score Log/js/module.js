/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

(function () {
    const init = () => {
    const form = document.getElementById('scoreLogAssessment');
    if (!form) return;

    const orderInput = document.getElementById('scoreLogColumnOrder');
    const addButton = document.getElementById('scoreLogAddColumn');
    const toolColumnSelect = document.getElementById('scoreLogToolColumn');
    const topImportButton = document.getElementById('scoreLogTopImport');
    const topExportButton = document.getElementById('scoreLogTopExport');
    const onlyMissingToggle = document.getElementById('scoreLogOnlyMissing');
    const nextMissingButton = document.getElementById('scoreLogNextMissing');
    const focusStatus = document.getElementById('scoreLogFocusStatus');
    const bulkValueInput = document.getElementById('scoreLogBulkValue');
    const bulkFillButton = document.getElementById('scoreLogBulkFill');
    const bulkClearButton = document.getElementById('scoreLogBulkClear');
    const bulkPreview = document.getElementById('scoreLogBulkPreview');
    const bulkUndoButton = document.getElementById('scoreLogBulkUndo');
    const bulkGrades = document.getElementById('scoreLogBulkGrades');
    const structureSource = document.getElementById('scoreLogStructureSource');
    const reuseStructureButton = document.getElementById('scoreLogReuseStructure');
    const revisionInput = form.querySelector('input[name="revision"]');
    const scaleData = window.scoreLogScaleData || {};
    const gradeData = window.scoreLogImportScaleData || {};
    const commentLibraryData = window.scoreLogCommentLibrary || [];
    const labels = window.scoreLogImportLabels || {};
    let columnOrder = (orderInput.value || '').split(',').filter(Boolean);
    let dirty = false;
    let bulkUndo = null;

    const numericValue = (value) => {
        const normalized = String(value || '').trim().replace(/%$/, '');
        if (!/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/.test(normalized)) return null;
        const number = Number(normalized);
        return Number.isFinite(number) ? number : null;
    };

    const matchesNumericGrade = (value, grades) => {
        const number = numericValue(value);
        if (number === null) return false;

        return Object.keys(grades).some((gradeValue) => numericValue(gradeValue) === number);
    };

    const activeColumnID = () => {
        const selected = toolColumnSelect ? toolColumnSelect.value : '';
        return columnOrder.includes(selected) ? selected : (columnOrder[0] || '');
    };

    const scoreCellFor = (row, columnID) => Array.from(row.children)
        .find((child) => child.getAttribute('data-column-id') === columnID) || null;

    const scoreInputFor = (row, columnID) => {
        const cell = scoreCellFor(row, columnID);
        return cell ? cell.querySelector('.scoreLogScoreInput') : null;
    };

    const scoreRows = () => Array.from(document.querySelectorAll('#scoreLogGrid tbody tr[data-student-id]'));

    const checkedScoreRows = () => scoreRows().filter((row) => {
        const checkbox = row.querySelector('.scoreLogStudentSelect');
        return checkbox && checkbox.checked;
    });

    const scoreIsValid = (value, scaleID) => {
        value = String(value || '').trim();
        if (value === '') return false;
        const config = scaleData[scaleID] || {};
        const grades = gradeData[scaleID] || {};
        if (config.numeric === 'Y') {
            const number = numericValue(value);
            return number !== null
                && number >= 0
                && (config.max === null || config.max === undefined || number <= Number(config.max))
                && matchesNumericGrade(value, grades);
        }

        return Object.prototype.hasOwnProperty.call(grades, value);
    };

    const setFocusStatus = (message) => {
        if (focusStatus) focusStatus.textContent = message || '';
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

    const applyOnlyMissingView = () => {
        const columnID = activeColumnID();
        const scaleID = selectedScaleID(columnID);
        const onlyMissing = !!(onlyMissingToggle && onlyMissingToggle.checked);
        scoreRows().forEach((row) => {
            const input = scoreInputFor(row, columnID);
            row.hidden = onlyMissing && input && scoreIsValid(input.value, scaleID);
        });
        updateBulkPreview();
    };

    const updateBulkInput = () => {
        if (!bulkValueInput) return;
        const columnID = activeColumnID();
        const scaleID = selectedScaleID(columnID);
        const config = scaleData[scaleID] || {};
        const grades = gradeData[scaleID] || {};
        bulkValueInput.placeholder = config.numeric === 'Y'
            ? (labels.bulkScore || 'Bulk score')
            : (labels.bulkGrade || 'Bulk grade');
        if (scaleID !== '') {
            bulkValueInput.setAttribute('list', 'scoreLogBulkGrades');
        } else {
            bulkValueInput.removeAttribute('list');
        }
        if (bulkGrades) {
            bulkGrades.textContent = '';
            Object.entries(grades).forEach(([value, descriptor]) => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = descriptor;
                bulkGrades.appendChild(option);
            });
        }
    };

    const updateBulkPreview = () => {
        if (!bulkPreview || !bulkFillButton || !bulkClearButton) return;
        const columnID = activeColumnID();
        const scaleID = selectedScaleID(columnID);
        const selectedRows = checkedScoreRows();
        const value = bulkValueInput ? bulkValueInput.value.trim() : '';
        const valueValid = value === '' || scoreIsValid(value, scaleID);
        let fillChanges = 0;
        let clearChanges = 0;

        selectedRows.forEach((row) => {
            const input = scoreInputFor(row, columnID);
            const current = input ? input.value.trim() : '';
            if (valueValid && value !== '' && current !== value) fillChanges++;
            if (current !== '') clearChanges++;
        });

        bulkFillButton.disabled = selectedRows.length === 0 || value === '' || !valueValid || fillChanges === 0;
        bulkClearButton.disabled = selectedRows.length === 0 || clearChanges === 0;
        if (selectedRows.length === 0) {
            bulkPreview.textContent = labels.selectStudentsFirst || 'Select students to fill or clear.';
            return;
        }

        const parts = [
            (labels.selected || 'Selected') + ' ' + selectedRows.length,
            (labels.fillWillChange || 'Fill will change') + ' ' + fillChanges,
            (labels.clearWillChange || 'Clear will change') + ' ' + clearChanges,
        ];
        if (!valueValid) parts.push(labels.bulkValueInvalid || 'Bulk value is not in the selected scale.');
        bulkPreview.textContent = parts.join(' · ');
    };

    const applyOnlyMissingViewAndFocus = () => {
        applyOnlyMissingView();
        if (onlyMissingToggle && onlyMissingToggle.checked) focusNextMissing();
    };

    const focusNextMissing = () => {
        const focusedInput = document.activeElement && document.activeElement.closest
            ? document.activeElement.closest('.scoreLogScoreInput')
            : null;
        const focusedCell = focusedInput ? focusedInput.closest('[data-column-id]') : null;
        const columnID = focusedCell ? focusedCell.getAttribute('data-column-id') : activeColumnID();
        if (toolColumnSelect && columnID && columnOrder.includes(columnID)) {
            toolColumnSelect.value = columnID;
        }
        updateBulkInput();

        const rows = scoreRows();
        const currentRow = focusedInput ? focusedInput.closest('tr') : null;
        const currentIndex = currentRow ? rows.indexOf(currentRow) : -1;
        const candidates = rows.filter((row) => {
            const input = scoreInputFor(row, columnID);
            return input && !input.disabled && input.value.trim() === '';
        });
        const afterCurrent = candidates.find((row) => rows.indexOf(row) > currentIndex);
        const nextRow = afterCurrent || candidates[0];

        if (!nextRow) {
            setFocusStatus(labels.noMissing || 'No more missing scores in this assessment column.');
            updateBulkPreview();
            return;
        }

        const nextInput = scoreInputFor(nextRow, columnID);
        setFocusStatus('');
        nextInput.focus();
        nextInput.select();
        updateBulkPreview();
    };

    const applyBulkChange = (mode) => {
        const columnID = activeColumnID();
        const scaleID = selectedScaleID(columnID);
        const fillValue = bulkValueInput ? bulkValueInput.value.trim() : '';
        if (mode === 'fill' && !scoreIsValid(fillValue, scaleID)) {
            setFocusStatus(labels.bulkValueInvalid || 'Bulk value is not in the selected scale.');
            updateBulkPreview();
            return;
        }

        const changes = [];
        checkedScoreRows().forEach((row) => {
            const input = scoreInputFor(row, columnID);
            if (!input) return;
            const previous = input.value;
            const next = mode === 'fill' ? fillValue : '';
            if (previous === next) return;
            changes.push({
                studentID: row.getAttribute('data-student-id'),
                previous,
            });
            input.value = next;
        });

        if (changes.length === 0) {
            setFocusStatus(labels.noBulkChanges || 'The checked students already have these values.');
            updateBulkPreview();
            return;
        }

        bulkUndo = {
            revision: revisionInput ? String(revisionInput.value) : '',
            columnID,
            changes,
        };
        dirty = true;
        updateColumnProgress(columnID);
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

        const restoredColumnID = bulkUndo.columnID;
        bulkUndo.changes.forEach((change) => {
            const row = scoreRows().find((candidate) => candidate.getAttribute('data-student-id') === change.studentID);
            const input = row ? scoreInputFor(row, restoredColumnID) : null;
            if (input) input.value = change.previous;
        });
        bulkUndo = null;
        dirty = true;
        updateColumnProgress(restoredColumnID);
        applyOnlyMissingView();
        updateUndoButton();
        updateBulkPreview();
        setFocusStatus(labels.undoComplete || 'Bulk change undone.');
    };

    const populateCommentLibrary = (select) => {
        if (!select || select.dataset.libraryLoaded === 'Y') return;
        commentLibraryData.forEach((comment) => {
            const option = document.createElement('option');
            option.value = String(comment.scoreLogCommentID || '');
            option.textContent = comment.label || '';
            option.dataset.comment = comment.comment || '';
            select.appendChild(option);
        });
        select.dataset.libraryLoaded = 'Y';
    };

    const uniqueCopyName = (sourceName) => {
        const base = String(sourceName || labels.assessment || 'Assessment').trim();
        const suffixLabel = labels.copySuffix || 'Copy';
        const usedNames = new Set(columnOrder.map((columnID) => columnName(columnID).trim().toLowerCase()));
        let copyNumber = 1;

        while (true) {
            const suffix = ' (' + suffixLabel + (copyNumber > 1 ? ' ' + copyNumber : '') + ')';
            const candidate = base.slice(0, Math.max(1, 30 - suffix.length)).trim() + suffix;
            if (!usedNames.has(candidate.toLowerCase())) return candidate;
            copyNumber++;
        }
    };

    const reuseStructure = () => {
        const option = structureSource && structureSource.selectedIndex >= 0
            ? structureSource.options[structureSource.selectedIndex]
            : null;
        if (!option || option.value === '') {
            setFocusStatus(labels.structureRequired || 'Choose an assessment structure to reuse.');
            return;
        }

        const nextID = allColumnIDs.find((columnID) => !columnOrder.includes(columnID));
        if (!nextID) {
            setFocusStatus(labels.noStructureSlots || 'No unused assessment column is available.');
            return;
        }

        setColumnVisible(nextID, true);
        const header = elementsFor(nextID).find((element) => element.querySelector('input[name$="[name]"]'));
        if (!header) return;

        elementsFor(nextID).forEach((element) => {
            element.querySelectorAll('.scoreLogScoreInput, .scoreLogCommentEditor textarea').forEach((input) => {
                input.value = '';
            });
        });

        const setField = (suffix, value) => {
            const field = header.querySelector('[name$="'+suffix+'"]');
            if (field && value !== undefined && value !== null) field.value = value;
        };
        setField('[name]', uniqueCopyName(option.dataset.name));
        setField('[description]', option.dataset.description || '');
        setField('[type]', option.dataset.type || '');
        setField('[scaleID]', option.dataset.scaleId || '');
        setField('[completeDate]', option.dataset.completeDate || '');
        setField('[viewableStudents]', option.dataset.viewableStudents || 'Y');
        setField('[viewableParents]', option.dataset.viewableParents || 'Y');

        columnOrder.push(nextID);
        if (toolColumnSelect) toolColumnSelect.value = nextID;
        reorderDOMColumns();
        updateOrder();
        updateStickyHeader(nextID);
        updateColumnProgress(nextID);
        applyOnlyMissingView();
        dirty = true;
        setFocusStatus(labels.structureAdded || 'Assessment structure copied without scores or comments.');
    };

    const allColumnIDs = Array.from(document.querySelectorAll('#scoreLogGrid [data-column-id]'))
        .map((element) => element.getAttribute('data-column-id'))
        .filter((value, index, values) => values.indexOf(value) === index);

    const elementsFor = (columnID) => Array.from(document.querySelectorAll('#scoreLogGrid [data-column-id]'))
        .filter((element) => element.getAttribute('data-column-id') === columnID);

    const updateOrder = () => {
        orderInput.value = columnOrder.join(',');
        addButton.disabled = columnOrder.length >= 10;
        updateColumnTools();
        document.querySelectorAll('#scoreLogGrid .scoreLogRemoveColumn').forEach((button) => {
            const column = button.closest('[data-column-id]');
            button.disabled = columnOrder.length <= 1 || (column && column.hidden);
        });
        updateBulkInput();
        updateBulkPreview();
    };

    const columnName = (columnID) => {
        const header = elementsFor(columnID).find((element) => element.querySelector('.scoreLogNameInput'));
        const input = header ? header.querySelector('.scoreLogNameInput') : null;
        return input && input.value.trim() !== '' ? input.value.trim() : (labels.assessment || 'Assessment');
    };

    const updateColumnTools = () => {
        if (!toolColumnSelect) return;
        const selected = toolColumnSelect.value;
        toolColumnSelect.textContent = '';
        columnOrder.forEach((columnID) => {
            const option = document.createElement('option');
            option.value = columnID;
            option.textContent = columnName(columnID);
            toolColumnSelect.appendChild(option);
        });
        if (columnOrder.includes(selected)) toolColumnSelect.value = selected;
    };

    const setColumnVisible = (columnID, visible) => {
        elementsFor(columnID).forEach((element) => {
            element.hidden = !visible;
            element.querySelectorAll('input, select, textarea, button').forEach((control) => {
                control.disabled = !visible;
            });
        });
    };

    const selectedScaleID = (columnID) => {
        const header = elementsFor(columnID).find((element) => element.querySelector('.scoreLogScaleSelect'));
        const select = header ? header.querySelector('.scoreLogScaleSelect') : null;
        return select ? select.value : '';
    };

    const updateStickyHeader = (columnID) => {
        const name = columnName(columnID);
        const scaleID = selectedScaleID(columnID);
        const scaleHeader = elementsFor(columnID)
            .map((element) => element.querySelector('.scoreLogScaleSelect'))
            .find(Boolean);
        const scaleName = scaleHeader && scaleHeader.selectedIndex >= 0
            ? scaleHeader.options[scaleHeader.selectedIndex].textContent
            : '';
        elementsFor(columnID).forEach((element) => {
            const nameLabel = element.querySelector('[data-column-sticky-name]');
            const scaleLabel = element.querySelector('[data-column-sticky-scale]');
            if (nameLabel) nameLabel.textContent = name;
            if (scaleLabel) scaleLabel.textContent = scaleName;
        });

        const config = scaleData[scaleID] || {};
        document.querySelectorAll('#scoreLogGrid tbody tr').forEach((row) => {
            const cell = Array.from(row.children)
                .find((child) => child.getAttribute('data-column-id') === columnID);
            if (!cell) return;
            const input = cell.querySelector('.scoreLogScoreInput');
            const studentCell = row.children[0];
            const studentName = studentCell ? studentCell.textContent.trim() : '';
            if (input) {
                input.setAttribute('aria-label', name + ' / ' + studentName);
                input.setAttribute('inputmode', config.numeric === 'Y' ? 'decimal' : 'text');
                input.placeholder = config.numeric === 'Y' ? labels.score : labels.grade;
                if (scaleID !== '') {
                    input.setAttribute('list', 'scoreLogGrades-' + scaleID);
                } else {
                    input.removeAttribute('list');
                }
            }
        });
    };

    const updateColumnProgress = (columnID) => {
        const scaleID = selectedScaleID(columnID);
        let entered = 0;
        let missing = 0;
        let invalid = 0;

        document.querySelectorAll('#scoreLogGrid tbody tr').forEach((row) => {
            const cell = Array.from(row.children)
                .find((child) => child.getAttribute('data-column-id') === columnID);
            if (!cell || cell.hidden) return;
            const input = cell.querySelector('.scoreLogScoreInput');
            const value = input ? input.value.trim() : '';
            if (value === '') {
                missing++;
                return;
            }

            const rowInvalid = !scoreIsValid(value, scaleID);

            if (rowInvalid) invalid++;
            else entered++;
        });
        updateStickyHeader(columnID);

        const progress = elementsFor(columnID)
            .map((element) => element.querySelector('[data-column-progress]'))
            .find(Boolean);
        if (progress) {
            progress.textContent = labels.entered + ' ' + entered + ' / '
                + labels.missing + ' ' + missing + ' / '
                + labels.invalid + ' ' + invalid;
        }
    };

    const updateAllProgress = () => columnOrder.forEach(updateColumnProgress);

    const reorderDOMColumns = () => {
        document.querySelectorAll('#scoreLogGrid tr').forEach((row) => {
            const firstCell = row.children[0];
            columnOrder.forEach((columnID) => {
                const cell = Array.from(row.children)
                    .find((child) => child.getAttribute('data-column-id') === columnID);
                if (cell) row.appendChild(cell);
            });
            if (firstCell && !firstCell.hasAttribute('data-column-id')) {
                row.insertBefore(firstCell, row.firstChild);
            }
        });
    };

    const moveColumn = (columnID, direction) => {
        const currentIndex = columnOrder.indexOf(columnID);
        const nextIndex = currentIndex + direction;
        if (currentIndex < 0 || nextIndex < 0 || nextIndex >= columnOrder.length) return;
        [columnOrder[currentIndex], columnOrder[nextIndex]] = [columnOrder[nextIndex], columnOrder[currentIndex]];
        reorderDOMColumns();
        updateOrder();
        updateAllProgress();
    };

    form.addEventListener('click', (event) => {
        const commentInsertButton = event.target.closest('.scoreLogCommentLibraryInsert');
        if (commentInsertButton) {
            const editor = commentInsertButton.closest('.scoreLogCommentEditor');
            const select = editor ? editor.querySelector('.scoreLogCommentLibrarySelect') : null;
            const textarea = editor ? editor.querySelector('textarea') : null;
            populateCommentLibrary(select);
            const option = select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
            if (textarea && option && option.dataset.comment !== undefined && option.dataset.comment !== '') {
                textarea.value = option.dataset.comment;
                textarea.dispatchEvent(new Event('input', {bubbles: true}));
                textarea.focus();
            }
            return;
        }

        const toolButton = event.target.closest('.scoreLogColumnTool');
        if (toolButton) {
            submitTopTool(toolButton.dataset.actionName, toolButton.value);
            return;
        }

        const moveButton = event.target.closest('.scoreLogMoveColumn');
        if (moveButton) {
            const column = moveButton.closest('[data-column-id]');
            moveColumn(column.getAttribute('data-column-id'), Number(moveButton.dataset.direction || 0));
            return;
        }

        const removeButton = event.target.closest('.scoreLogRemoveColumn');
        if (removeButton) {
            const column = removeButton.closest('[data-column-id]');
            const columnID = column.getAttribute('data-column-id');
            if (columnOrder.length <= 1) return;
            const hasData = elementsFor(columnID).some((element) => {
                return Array.from(element.querySelectorAll('input, textarea'))
                    .some((input) => input.value.trim() !== '');
            });
            if (hasData && !window.confirm(labels.confirmRemove || 'Remove this assessment column?')) return;
            elementsFor(columnID).forEach((element) => {
                element.querySelectorAll('input, textarea').forEach((input) => {
                    input.value = '';
                });
            });
            setColumnVisible(columnID, false);
            columnOrder = columnOrder.filter((id) => id !== columnID);
            updateOrder();
            updateAllProgress();
        }
    });

    addButton.addEventListener('click', () => {
        const nextID = allColumnIDs.find((columnID) => !columnOrder.includes(columnID));
        if (!nextID) return;
        setColumnVisible(nextID, true);
        const header = elementsFor(nextID).find((element) => element.querySelector('input[name$="[name]"]'));
        const nameInput = header ? header.querySelector('input[name$="[name]"]') : null;
        if (nameInput && nameInput.value.trim() === '') {
            nameInput.focus();
        }
        columnOrder.push(nextID);
        reorderDOMColumns();
        updateOrder();
        updateAllProgress();
    });

    form.addEventListener('input', (event) => {
        if (event.target === bulkValueInput) {
            updateBulkPreview();
            return;
        }

        dirty = true;
        if (event.target.classList.contains('scoreLogNameInput')) {
            const column = event.target.closest('[data-column-id]');
            if (column) {
                const columnID = column.getAttribute('data-column-id');
                updateStickyHeader(columnID);
                updateColumnTools();
            }
        }
        if (event.target.classList.contains('scoreLogScoreInput')) {
            const column = event.target.closest('[data-column-id]');
            const row = event.target.closest('tr[data-student-id]');
            if (row) invalidateUndoForRow(row.getAttribute('data-student-id'));
            if (column) updateColumnProgress(column.getAttribute('data-column-id'));
            updateBulkPreview();
        }
        if (event.target.matches('.scoreLogCommentEditor textarea')) {
            const editor = event.target.closest('.scoreLogCommentEditor');
            const summary = editor.querySelector('summary');
            const defaultComment = (event.target.dataset.defaultComment || '').trim();
            const hasComment = event.target.value.trim() !== '' && event.target.value.trim() !== defaultComment;
            editor.classList.toggle('hasComment', hasComment);
            summary.textContent = hasComment ? labels.comment : labels.addComment;
        }
    });

    form.addEventListener('change', (event) => {
        if (event.target === onlyMissingToggle) {
            applyOnlyMissingViewAndFocus();
            return;
        }
        if (event.target.classList.contains('scoreLogStudentSelect')) {
            updateBulkPreview();
            return;
        }
        if (event.target === toolColumnSelect) {
            updateBulkInput();
            applyOnlyMissingView();
            updateBulkPreview();
            return;
        }
        if (event.target === bulkValueInput) {
            updateBulkPreview();
            return;
        }

        dirty = true;
        if (event.target.classList.contains('scoreLogScaleSelect')) {
            const column = event.target.closest('[data-column-id]');
            if (column) {
                const columnID = column.getAttribute('data-column-id');
                updateStickyHeader(columnID);
                updateColumnProgress(columnID);
                updateBulkInput();
                updateBulkPreview();
            }
        }
    });

    form.addEventListener('focusin', (event) => {
        const scoreInput = event.target.closest('.scoreLogScoreInput');
        if (!scoreInput) return;
        const cell = scoreInput.closest('[data-column-id]');
        const columnID = cell ? cell.getAttribute('data-column-id') : '';
        if (toolColumnSelect && columnID && columnOrder.includes(columnID)) {
            toolColumnSelect.value = columnID;
            updateBulkInput();
            updateBulkPreview();
        }
    });

    form.addEventListener('focusout', (event) => {
        if (!event.target.classList.contains('scoreLogScoreInput') || !onlyMissingToggle) return;
        window.setTimeout(() => {
            if (onlyMissingToggle.checked) applyOnlyMissingView();
        }, 0);
    });

    form.addEventListener('keydown', (event) => {
        if (event.altKey && event.key.toLowerCase() === 'n') {
            event.preventDefault();
            focusNextMissing();
            return;
        }
        if (
            event.key === 'Enter'
            && !event.shiftKey
            && !event.altKey
            && !event.ctrlKey
            && !event.metaKey
            && event.target.classList.contains('scoreLogScoreInput')
        ) {
            event.preventDefault();
            focusNextMissing();
        }
    });

    const submitTopTool = (actionName, columnID) => {
        const targetColumnID = columnID || (toolColumnSelect ? toolColumnSelect.value : '');
        if (!targetColumnID) return;
        form.querySelectorAll('input[data-tool-action]').forEach((input) => input.remove());
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = actionName;
        input.value = targetColumnID;
        input.dataset.toolAction = 'Y';
        form.appendChild(input);
        dirty = false;
        form.submit();
    };
    if (topImportButton) {
        topImportButton.addEventListener('click', () => submitTopTool('importColumnID'));
    }
    if (topExportButton) {
        topExportButton.addEventListener('click', () => submitTopTool('exportColumnID'));
    }
    if (nextMissingButton) {
        nextMissingButton.addEventListener('click', focusNextMissing);
    }
    if (bulkFillButton) {
        bulkFillButton.addEventListener('click', () => applyBulkChange('fill'));
    }
    if (bulkClearButton) {
        bulkClearButton.addEventListener('click', () => applyBulkChange('clear'));
    }
    if (bulkUndoButton) {
        bulkUndoButton.addEventListener('click', undoBulkChange);
    }
    if (reuseStructureButton) {
        reuseStructureButton.addEventListener('click', reuseStructure);
    }

    form.querySelectorAll('.scoreLogCommentEditor').forEach((editor) => {
        editor.addEventListener('toggle', () => {
            if (editor.open) {
                populateCommentLibrary(editor.querySelector('.scoreLogCommentLibrarySelect'));
                const textarea = editor.querySelector('textarea');
                if (textarea) textarea.focus();
            }
        });
    });

    form.addEventListener('submit', () => {
        dirty = false;
    });
    window.addEventListener('beforeunload', (event) => {
        if (!dirty) return;
        event.preventDefault();
        event.returnValue = labels.unsavedChanges || '';
    });

    updateOrder();
    updateAllProgress();
    updateBulkInput();
    applyOnlyMissingView();
    updateBulkPreview();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once: true});
    } else {
        init();
    }
})();
