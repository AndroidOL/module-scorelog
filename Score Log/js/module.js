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
    const scaleData = window.scoreLogScaleData || {};
    const gradeData = window.scoreLogImportScaleData || {};
    const labels = window.scoreLogImportLabels || {};
    let columnOrder = (orderInput.value || '').split(',').filter(Boolean);
    let dirty = false;

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
        const config = scaleData[scaleID] || {};
        const grades = gradeData[scaleID] || {};
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

            let rowInvalid = false;
            if (config.numeric === 'Y') {
                if (!/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/.test(value)) {
                    rowInvalid = true;
                } else {
                    const number = Number(value);
                    rowInvalid = !Number.isFinite(number)
                        || number < 0
                        || (config.max !== null && config.max !== undefined && number > Number(config.max))
                        || !matchesNumericGrade(value, grades);
                }
            } else {
                rowInvalid = !Object.prototype.hasOwnProperty.call(grades, value);
            }

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
            if (column) updateColumnProgress(column.getAttribute('data-column-id'));
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
        dirty = true;
        if (event.target.classList.contains('scoreLogScaleSelect')) {
            const column = event.target.closest('[data-column-id]');
            if (column) {
                const columnID = column.getAttribute('data-column-id');
                updateStickyHeader(columnID);
                updateColumnProgress(columnID);
            }
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

    form.querySelectorAll('.scoreLogCommentEditor').forEach((editor) => {
        editor.addEventListener('toggle', () => {
            if (editor.open) {
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
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once: true});
    } else {
        init();
    }
})();
