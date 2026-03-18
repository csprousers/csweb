const ui = {
    init: async () => {
        await ui.loadRoles(); // Also loads ui.data.dpdictionarysAll and ui.data.dpdictionarys
        ui.loadFormValidation();
        ui.populateTables();
        ui.addEventListeners();
        ui.checkForToastMsg();
        ui.handleSearchInput(null, 'roles');
        ui.handleSearchInput(null, 'dpdictionarys');
    },
    loadFormValidation: () => {
        const form = $('#role-form');
        const submitBtn = $('#role-yes-button');
        form.on('input change', () => {
            // Use the validator's isValid() method
            const isValid = form[0].checkValidity();
            submitBtn.prop('disabled', !isValid);
            submitBtn.toggleClass('disabled', !isValid);
        });
    },
    checkForToastMsg: () => {
        const params = ui.getUrlParams();
        if (params.msg && params.color) {
            ui.showAlert(params.msg, params.color);
            // We remove the GET params from the URL to avoid showing the alert again on page reload or bookmarking the page
            window.history.replaceState({}, '', `${window.location.pathname}`);
        }
    },
    // params could a json, ex: {param1: 'value1', param2: 'value2'}
    reloadWithGetParams: params => {
        // return;
        const newParams = new URLSearchParams(window.location.search);
        Object.entries(params).forEach(([key, value]) => {
            newParams.set(key, value);
        });
        window.location.search = newParams.toString();
    },
    getUrlParams: () => {
        const params = new URLSearchParams(window.location.search);
        const result = {};
        for (const [key, value] of params.entries()) {
            result[key] = value;
        }
        return result;
    },
    toggleLoading: (enable = true) => {
        $('body').toggleClass('loading', enable);
    },
    // color could be 'green', 'red', 'blue', etc.
    showAlert(message, color = 'green') {
        const getAlertClass = (color) => {
            switch (color) {
                case 'red':
                    return 'alert-danger';
                case 'yellow':
                    return 'alert-warning';
                case 'blue':
                    return 'alert-info';
                case 'green':
                default:
                    return 'alert-success';
            }
        }
        const alert = $('#alert');
        alert.replaceWith(`<div id="alert" class="alert ${getAlertClass(color)} alert-margin-cspro"> ${message} </div>`);
        alert.show();
    },
    populateTables: () => {
        ui.populatePermissionsTable();
        ui.dpdictionarysPopulateTable(true);
    },
    populatePermissionsTable: () => {
        ui.data.permissions.reverse().forEach(permission => {
            const row = ui.cloneTemplateRow('.permission');
            row.find('.name').text(permission);
            row.attr('data-name', permission);
            $('#permissions').prepend(row);
        });
    },
    resetModal: () => {
        const modal = $('#modal');
        modal.find('#role-name').val('');
        modal.find('.checkbox').prop('checked', false);
        modal.find('#login-checkbox').prop('checked', false);
        modal.find('#dpdictionarys').find('.checkbox').prop('disabled', false);
    },
    dpdictionarysPopulateTable: firstTime => {
        if (firstTime) {
            ui.addDefaultDictionaryRow();
            ui.data.dpdictionarys.forEach(dpdictionary => {
                const row = ui.cloneTemplateRow('.dpdictionary');
                row.find('.id').text(dpdictionary.id);
                row.find('.name').text(dpdictionary.name);
                row.find('.label').text(dpdictionary.label);
                $('#dpdictionarys').append(row);
            });
        } else {
            $('#dpdictionarys').find('.dpdictionary').show();
            const dpdictionarysIds = ui.data.dpdictionarys.map(d => d.id);
            $('#dpdictionarys').find('.dpdictionary:not(.default)').each((index, element) => {
                const dpdictionaryId = +$(element).find('.id').text();
                if (!dpdictionarysIds.includes(dpdictionaryId)) {
                    $(element).hide();
                }
            });
        }
    },
    addDefaultDictionaryRow: () => {
        const row = ui.cloneTemplateRow('.dpdictionary');
        row.addClass('default');
        row.find('.label').text('Default');
        row.find('.default').html('');
        $('#dpdictionarys').append(row);
    },
    cloneTemplateRow: (selector) => {
        return $(`#templates ${selector}`).clone(true);
    },
    editRoleBtnClicked: role => {
        ui.data.editingRole = role;
        const modal = $('#modal');
        modal.find('.modal-title').text('Edit Role');
        modal.find('#role-yes-button').text('Save');
        modal.find('#role-name').val(role.name);
        modal.find('#role-name').attr('disabled', true);
        ui.populateRolePermissions(role);
        ui.populateDefaultPermissions(role);
        ui.populateRoleDpdictionarys(role);
        ui.data.dpdictionarysPage = 1;
        $('#dpdictionarys-search').val('');
        ui.handleSearchInput(null, 'dpdictionarys');
        modal.modal('show');
    },
    populateDefaultPermissions: role => {
        ui.data.permissionsTypes.forEach(pt => {
            $('.dpdictionary.default').find(`.${pt.frontend} .checkbox`)[0].checked = role.defaultPermissions.includes(pt.frontend);
        });
    },
    populateRolePermissions: role => {
        const modal = $('#modal');
        role.permissions.forEach(permission => {
            const row = modal.find(`.permission .name:contains(${permission.name})`).closest('tr');
            row.find('.all-checkbox').prop('checked', permission.read && permission.write);
            row.find('.read .checkbox').prop('checked', permission.read);
            row.find('.write .checkbox').prop('checked', permission.write);
        });
        modal.find('#login-checkbox').prop('checked', role.login);
    },
    populateRoleDpdictionarys: role => {
        const modal = $('#modal');
        role.dpdictionarys.forEach(dpdictionary => {
            const row = modal.find('.dpdictionary .id').filter((idx, row) => +$(row).text() === dpdictionary.id).closest('tr');
            row.find('.default-checkbox').prop('checked', dpdictionary.useDefaultPermissions);
            if (dpdictionary.useDefaultPermissions) {
                setTimeout(() => {
                    row.find('.default-checkbox').trigger('change'); // Trigger change to make sure the row checkboxes are updated (including adding the disabled property if needed)
                }, 10);
                return;
            } else {
                ui.data.permissionsTypes.forEach(pt => {
                    row.find(`.${pt.frontend} .checkbox`).prop('checked', dpdictionary.permissions.includes(pt.frontend)).prop('disabled', false);
                });
            }
        });
    },
    addEventListeners: () => {
        $('#add-role-btn').on('click', ui.addRoleBtnClicked);
        $('#templates .role .edit').on('click', ui.handleEditRoleClick);
        $('#templates .role .copy').on('click', ui.handleCopyRoleClick);
        $('#permissions,#dpdictionarys').on('change', '.all-checkbox,.permission-checkbox', ui.updatePermissionTableRow);
        $('#dpdictionarys').on('change', '.default-checkbox', ui.handleDefaultCheckboxChange);
        $('#dpdictionarys tr.default').on('change', '.all-checkbox,.permission-checkbox', ui.updateAllDpdictionarys);
        $('#templates .role .delete').on('click', ui.handleDeleteRoleClick);
        $('#delete-role-btn').on('click', ui.confirmDeleteRole);
        $('#role-form').off('submit').on('submit', ui.handleRoleFormSubmit);
        // roles pagination/search
        $('#roles-search').on('input', e => ui.handleSearchInput(e, 'roles'));
        $('#roles-page-prev').off('').on('click', e => ui.handlePagePrevNextClick(e, 'roles'));
        $('#roles-page-next').off('').on('click', e => ui.handlePagePrevNextClick(e, 'roles'));
        $('#roles-page-size').on('change', e => ui.handleSearchInput(e, 'roles'));
        // dpdictionarys pagination/search
        $('#dpdictionarys-search').on('input', e => ui.handleSearchInput(e, 'dpdictionarys'));
        $('#dpdictionarys-page-prev').off('').on('click', e => ui.handlePagePrevNextClick(e, 'dpdictionarys'));
        $('#dpdictionarys-page-next').off('').on('click', e => ui.handlePagePrevNextClick(e, 'dpdictionarys'));
        $('#dpdictionarys-page-size').on('change', e => ui.handleSearchInput(e, 'dpdictionarys'));
        // If escape key is pressed, force closing the modal
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                $('#modal').modal('hide');
            }
        });
    },
    handlePagePrevNextClick: (e, table) => {
        const el = $(e.target).closest('.page-item');
        if (el.hasClass('disabled')) return;
        const isNext = el.hasClass('next');
        ui.data[`${table}Page`] += isNext ? 1 : -1;
        ui.handleSearchInput(null, table);
    },
    handlePageItemClick: (e, table) => {
        const pageEl = $(e.target).closest('.page-item');
        const page = +pageEl.find('.page-link').text();
        ui.data[`${table}Page`] = page;
        ui.handleSearchInput(null, table);
    },
    // table === 'roles' | 'dpdictionarys' (permissions)
    handleSearchInput: (e, table) => {
        ui.toggleLoading(true);
        setTimeout(() => {
            ui.toggleLoading(false);
        }, 300);
        const searchEl = $(`#${table}-search`);
        let text = searchEl.val().trim().toLowerCase();
        const allRows = ui.data[`${table}All`];
        const pageSize = +$(`#${table}-page-size`).val();
        const filteredRows = allRows.filter(row => row.name.toLowerCase().includes(text));
        if (filteredRows.length <= pageSize) {
            ui.data[`${table}Page`] = 1; // Reset to first page if filtered results are less than or equal to page size
        }
        const startIdx = (ui.data[`${table}Page`] - 1) * pageSize;
        const endIdx = ui.data[`${table}Page`] * pageSize;
        // Pagination on filtered results
        ui.data[table] = filteredRows.slice(startIdx, endIdx);
        const numberOfPages = Math.ceil(filteredRows.length / pageSize);
        ui[`${table}PopulateTable`]();
        // Update pagination info
        $(`#${table}-page-num-of-entries`).text(filteredRows.length);
        $(`#${table}-page-start`).text(filteredRows.length === 0 ? 0 : startIdx + 1);
        $(`#${table}-page-end`).text(Math.min(endIdx, filteredRows.length));
        $(`#${table}-pages-container`).html('');
        for (let i = 1; i <= numberOfPages; i++) {
            const pageEl = $('#templates .page-number').clone(true);
            pageEl.find('.page-link').text(i);
            pageEl.attr('data-page', i);
            if (i === ui.data[`${table}Page`]) {
                pageEl.addClass('active');
            }
            $(`#${table}-pages-container`).append(pageEl);
            pageEl.on('click', e => ui.handlePageItemClick(e, table));
        }
        $(`#${table}-page-prev`).toggleClass('disabled', ui.data[`${table}Page`] === 1);
        $(`#${table}-page-next`).toggleClass('disabled', ui.data[`${table}Page`] >= numberOfPages);
        $(`#${table}-page-info-filtered-text`).toggle(ui.data[`${table}All`].length !== filteredRows.length);
        $(`#${table}-page-info-filtered-text-num`).text(ui.data[`${table}All`].length);
    },
    handleEditRoleClick: e => {
        const roleRow = $(e.target).closest('.role');
        const role = ui.data.roles.find(r => r.id === +roleRow.find('.id').text());
        ui.editRoleBtnClicked(role);
    },
    handleCopyRoleClick: e => {
        const roleRow = $(e.target).closest('.role');
        const role = ui.data.roles.find(r => r.id === +roleRow.find('.id').text());
        ui.copyRoleBtnClicked(role);
    },
    copyRoleBtnClicked: role => {
        ui.resetModal();
        const modal = $('#modal');
        modal.find('.modal-title').text('Add Role');
        modal.find('#role-yes-button').text('Add');
        modal.find('#role-name').val('');
        modal.find('#role-name').attr('disabled', false);
        ui.populateRolePermissions(role);
        ui.populateDefaultPermissions(role);
        ui.populateRoleDpdictionarys(role);
        ui.data.Page = 1;
        $('#dpdictionarys-search').val('');
        ui.handleSearchInput(null, 'dpdictionarys');
        modal.modal('show');
    },
    handleDefaultCheckboxChange: e => {
        const checkbox = $(e.target);
        const row = checkbox.closest('tr');
        row.find('.all-checkbox,.permission-checkbox').prop('disabled', checkbox[0].checked);
        ui.updateDpdictionaryCheckboxs(row);
    },
    updateAllDpdictionarys: () => {
        setTimeout(() => {
            $('#dpdictionarys .dpdictionary:not(.default)').each((idx, row) => {
                ui.updateDpdictionaryCheckboxs(row);
            });
        }, 10);
    },
    handleDeleteRoleClick: e => {
        const roleRow = $(e.target).closest('.role');
        const role = ui.data.roles.find(r => r.id === +roleRow.find('.id').text());
        ui.deleteRoleBtnClicked(role);
    },
    confirmDeleteRole: () => {
        const role = ui.data.deletingRole;
        ui.toggleLoading(true);
        $.ajax({
            url: 'deleteRole',
            type: 'DELETE',
            dataType: 'text',
            data: {
                roleId: role.id,
                roleName: role.name,
            },
            success: response => {
                setTimeout(() => {
                    console.info('Role Deleted Successfully:', response);
                    ui.toggleLoading(false);
                    ui.reloadWithGetParams({ msg: `Role <b><i>${role.name}</i></b> deleted successfully`, color: 'green' });
                }, 1000);
            },
            error: (xhr, status, error) => {
                ui.onError(xhr, status, error, '#delete-role-modal');
            }
        });
    },
    handleRoleFormSubmit: e => {
        e.preventDefault();
        const editing = $('#modal .modal-title').text() === 'Edit Role';
        const jsonRoleBackend = ui.transformGetRolesFrontendToBackendJson(editing ? ui.data.editingRole : null);
        const jsonRoleFrontend = ui.transformGetRolesBackendToFrontendJson([jsonRoleBackend])[0];
        // return;
        ui.toggleLoading(true);
        $.ajax({
            url: editing ? 'editRole' : 'addRole',
            type: 'POST',
            dataType: 'text',
            data: JSON.stringify(jsonRoleBackend),
            success: response => {
                setTimeout(() => {
                    ui.toggleLoading(false);
                    if (!editing) {
                        ui.reloadWithGetParams({ msg: `Role <b><i>${jsonRoleFrontend.name}</i></b> ${editing ? 'edited' : 'added'} successfully`, color: 'green' });
                    } else {
                        // We avoid reloading the page and we update the role in the ui.data.roles array
                        $('#modal').modal('hide');
                        ui.showAlert(`Role <b><i>${jsonRoleFrontend.name}</i></b> ${editing ? 'edited' : 'added'} successfully`, 'green');
                        const idxRole = ui.data.roles.indexOf(ui.data.roles.find(r => r.id === jsonRoleFrontend.id))
                        ui.data.roles[idxRole] = jsonRoleFrontend;
                        // Update roles table after editing
                        ui.rolesPopulateTable();
                    }
                }, 1000);
            },
            error: (xhr, status, error) => {
                ui.onError(xhr, status, error, '#modal');
            }
        });
    },
    onError: (xhr, status, error, modalSelector) => {
        setTimeout(() => {
            ui.toggleLoading(false);
            let errorMsg;
            try {
                errorMsg = JSON.parse(xhr.responseText).message;
            } catch (e) {
                errorMsg = error ?? 'Unexpected error, check console for more details.';
            } finally {
                ui.showAlert(errorMsg, 'red');
                console.error('xhr:', xhr);
                console.error('status:', status);
                console.error('error:', error);
            }
            $(modalSelector).modal('hide');
        }, 1000);
    },
    deleteRoleBtnClicked: role => {
        ui.data.deletingRole = role;
        $('#delete-modal-role-name').text(role.name);
        $('#delete-role-modal').modal('show');
    },
    transformGetRolesFrontendToBackendJson: role => {
        const permissions = {};
        $('#permissions .permission').each((idx, tr) => {
            const permission = $(tr).find('.name').text();
            const arr = permissions[permission] = [];
            const permissionsTypes = ['read', 'write'];
            permissionsTypes.forEach(pt => {
                if ($(tr).find(`.${pt} .checkbox`)[0].checked) {
                    arr.push(`${permission === 'dictionary' ? 'dictionaries' : permission}.${pt}`);
                }
            });
        });
        const dictionaries = {};
        $('#dpdictionarys .dpdictionary:not(.default)').each((idx, tr) => {
            const name = $(tr).find('.name').text();
            const label = $(tr).find('.label').text();
            let permissions = [];
            const useDefault = $(tr).find('.default-checkbox')[0].checked;
            if (useDefault) {
                permissions = undefined;
            } else {
                ui.data.permissionsTypes.forEach(pt => {
                    if ($(tr).find(`.${pt.frontend} .checkbox`)[0].checked) {
                        permissions.push(pt.backend);
                    }
                });
            }
            if (permissions !== undefined && !permissions.length) {
                permissions = ['data.none']; // If no permissions are selected, we set it to 'data.none' (no permissions, no default)
            }
            dictionaries[name] = {
                id: $(tr).find('.id').text(),
                name,
                label,
                permissions,
            };
        });
        const defaultPermissions = [];
        ui.data.permissionsTypes.forEach(pt => {
            if ($('#dpdictionarys .dpdictionary.default').find(`.${pt.frontend} .checkbox`)[0].checked) {
                defaultPermissions.push(pt.backend);
            }
        });
        return {
            id: role?.id ?? '99',
            name: $('#role-name').val().trim(),
            data: defaultPermissions,
            ...permissions,
            login: $('#login-checkbox')[0].checked,
            dictionaries: { ...dictionaries },
        };
    },
    updateDpdictionaryCheckboxs: row => {
        const defaultRowValues = ui.data.permissionsTypes.map(pt => {
            const checkbox = $(`#dpdictionarys tr.default .${pt.frontend} .checkbox`);
            return {
                col: pt.frontend,
                checked: checkbox[0].checked,
            };
        });
        const checkbox = $(row).find('.default-checkbox');
        if (!checkbox[0].checked) return;
        defaultRowValues.forEach(({ col, checked }) => {
            $(row).find(`.${col} .checkbox`)[0].checked = checked;
        });
    },
    updatePermissionTableRow: e => {
        const checkbox = $(e.target);
        const row = checkbox.closest('tr');
        const permissionsCheckboxs = row.find('.permission-checkbox');
        const isAllCheckboxChanged = checkbox.hasClass('all-checkbox');
        if (isAllCheckboxChanged) {
            permissionsCheckboxs.prop('checked', checkbox[0].checked);
        } else {
            const allPermissionsCheckboxsChecked = permissionsCheckboxs.toArray().every(c => c.checked);
            row.find('.all-checkbox').prop('checked', allPermissionsCheckboxsChecked);
        }
    },
    addRoleBtnClicked: () => {
        ui.resetModal();
        const modal = $('#modal');
        modal.find('.modal-title').text('Add Role');
        modal.find('#role-yes-button').text('Add');
        modal.find('#role-name').attr('disabled', false);
        modal.modal('show');
        ui.handleSearchInput(null, 'dpdictionarys');
        $('#role-form').trigger('input');
    },
    data: {
        rolesPage: 1,
        dpdictionarysPage: 1,
        roles: null,
        dpdictionarys: null,
        rolesAll: null,
        dpdictionarysAll: null,
        editingRole: null,
        deletingRole: null,
        permissions: ['dictionary', 'apps', 'files', 'reports', 'users', 'roles'],
        permissionsTypes: [
            {
                frontend: 'all',
                backend: 'data',
                backendId: 1,
            },
            {
                frontend: 'read',
                backend: 'data.read',
                backendId: 2,
            },
            {
                frontend: 'write',
                backend: 'data.write',
                backendId: 3,
            },
            {
                frontend: 'ui-delete',
                backend: 'data.clear.dashboard',
                backendId: 5,
            },
            {
                frontend: 'api-delete',
                backend: 'data.clear',
                backendId: 4,
            }
        ],
    },
    loadRoles: () => {
        return new Promise(resolve => {
            $.ajax({
                url: 'getRoles',
                dataType: 'json',
                success: json => {
                    ui.data.rolesAll = ui.transformGetRolesBackendToFrontendJson(json);
                    ui.data.roles = ui.data.rolesAll;
                    // We sort roles by id
                    ui.data.roles = ui.data.roles.sort((a, b) => a.id - b.id);
                    ui.data.dpdictionarysAll = [...ui.data.roles[0].dpdictionarys];
                    ui.data.dpdictionarys = ui.data.dpdictionarysAll;
                    ui.rolesPopulateTable();
                    resolve();
                },
            });
        });
    },
    rolesPopulateTable: () => {
        $('#roles').html(''); // Clear the table
        ui.data.roles.forEach(role => {
            const roleRow = $('#templates .role').clone(true);
            roleRow.find('.id').text(role.id);
            roleRow.find('.name').text(role.name);
            role.permissions.forEach(permission => {
                const text = permission.read && permission.write ? 'All' : permission.read ? 'Read' : permission.write ? 'Write' : 'None';
                roleRow.find(`.${permission.name}`).text(text);
            });
            roleRow.find('.login').text(role.login ? 'Yes' : 'No');
            roleRow.find('.edit').toggleClass('icon-disabled', !role.editable);
            roleRow.find('.copy').toggleClass('icon-disabled', !role.editable);
            roleRow.find('.delete').toggleClass('icon-disabled', !role.deletable);
            $('#roles').append(roleRow);
        });
    },
    /**
     * Roles JSON TypeScript Definition:
     * {
     *      id: number;
     *      name: string;
     *      login: boolean;
     *      editable: boolean;
     *      deletable: boolean;
     *      permissions: {
     *          name: string;
     *          read: boolean;
     *          write: boolean;
     *      }[];
     *      defaultPermissions: string[]; // ['read', 'write', 'ui-delete', 'api-delete']
     *      dpdictionarys: {
     *          id: number;
     *          name: string;
     *          label: string;
     *          useDefaultPermissions: boolean;
     *          permissions: string[]; // ['read', 'write', 'ui-delete', 'api-delete']
     *      }[];
     * }[];
     */
    transformGetRolesBackendToFrontendJson: json => {
        const roles = json;
        return roles.map(role => {
            const permissions = ui.data.permissions.map(col => {
                const all = role[col].includes(col === 'dictionary' ? 'dictionaries' : col);
                const colPrefix = col === 'dictionary' ? 'dictionaries' : col;
                return {
                    name: col,
                    read: role[col].includes(`${colPrefix}.read`) || all,
                    write: role[col].includes(`${colPrefix}.write`) || all,
                };
            });
            let defaultPermissions = ui.data.permissionsTypes.filter(pt => role.data.includes(pt.backend)).map(pt => pt.frontend);
            // Even if we save all permissions in backend (ex: data: ['data', 'data.read', 'data.write', etc...]) we only receive data: ['data'], so we check for that and add all frontend permissions (['read', 'write', 'ui-delete', 'api-delete']) in order to make checking all checkboxes easier
            if (defaultPermissions.length === 1 && defaultPermissions[0] === 'all') {
                defaultPermissions = ui.data.permissionsTypes.map(pt => pt.frontend);
            }
            const dpdictionarys = Object.keys(role.dictionaries).map(key => {
                const dpdictionary = role.dictionaries[key];
                let permissions = dpdictionary.permissions;
                // We use default permissions if the permissions property is not set (and there's no permissions)
                const useDefaultPermissions = permissions === undefined;
                if (!useDefaultPermissions && permissions.length === 1 && permissions[0] === 'data') {
                    // Even when we save all permissions in backend for a dpdictionary (ex: data: ['data', 'data.read', 'data.write', etc...]) we only receive the all permission: 1, so we check for that and add all frontend permissions (['read', 'write', 'ui-delete', 'api-delete']) in order to make checking all checkboxes easier
                    permissions = ui.data.permissionsTypes.map(pt => pt.frontend);
                } else {
                    if (permissions !== undefined) {
                        permissions = ui.data.permissionsTypes.filter(pt => permissions.includes(pt.backend)).map(pt => pt.frontend);
                    }
                }
                return {
                    id: +dpdictionary.id,
                    name: dpdictionary.name,
                    label: dpdictionary.label,
                    useDefaultPermissions,
                    permissions,
                };
            });
            // If the role is 'Standard User' (id: 1) or 'Administrator' (id: 2) it cannot be edited/deleted
            const isAdministratorOrStandardUser = [1, 2].includes(role.id);
            const canManageRoles = document.getElementById('roles-content').dataset.canManageRoles === 'true';
            return {
                id: role.id,
                name: role.name,
                login: role.login,
                editable: !isAdministratorOrStandardUser && canManageRoles,
                deletable: !isAdministratorOrStandardUser && canManageRoles,
                permissions,
                dpdictionarys,
                defaultPermissions,
            };
        });
    },
};

$(window).on('load', async () => {
    await ui.init();
    (window.ui) = ui; // Expose ui to the global scope for debugging purposes
});